<?php

declare(strict_types=1);

namespace Obx\Node\Services;

use Obx\Node\Db\Db;
use Obx\Node\Http\ApiError;
use Obx\Node\Images\ImageInfo;
use Obx\Node\Storage\BlobStore;
use Obx\Node\Support\Clock;

/**
 * Blob metadata (obx_blobs) plus the byte store. Blobs are images identified by sha256.
 */
final class Blobs
{
    public function __construct(private Db $db, private BlobStore $store, private string $tmpDir)
    {
    }

    public function tmpDir(): string
    {
        if (!is_dir($this->tmpDir)) {
            @mkdir($this->tmpDir, 0770, true);
        }
        return $this->tmpDir;
    }

    public function tempFile(string $prefix = 'obx'): string
    {
        return (string) tempnam($this->tmpDir(), $prefix);
    }

    /** @return array<string, mixed>|null */
    public function get(string $sha): ?array
    {
        return $this->db->one('SELECT * FROM obx_blobs WHERE sha256 = ?', [$sha]);
    }

    public function has(string $sha): bool
    {
        return $this->get($sha) !== null;
    }

    /**
     * Stores a file (already hashed) as a blob after checking its signature.
     *
     * @param list<string> $accepted content types allowed for this use
     * @return array{sha256: string, bytes: int, content_type: string, width: ?int, height: ?int, created: bool}
     */
    public function store(string $path, string $sha, int $bytes, array $accepted = ImageInfo::ACCEPTED): array
    {
        $existing = $this->get($sha);
        if ($existing !== null) {
            @unlink($path);
            $this->db->run('UPDATE obx_blobs SET used_ts = ? WHERE sha256 = ?', [Clock::time(), $sha]);
            return self::describe($existing, false);
        }
        if ($bytes < 1) {
            @unlink($path);
            throw new ApiError('validation_failed', 'The file is empty.');
        }
        $type = ImageInfo::sniffFile($path);
        if ($type === null || !in_array($type, $accepted, true)) {
            @unlink($path);
            throw new ApiError('unsupported_media_type', 'Only ' . implode(', ', $accepted) . ' files are accepted (checked by file content).');
        }
        $dims = ImageInfo::dimensions($path);
        $this->store->put($sha, $path, $type);
        $row = [
            'sha256' => $sha,
            'bytes' => $bytes,
            'content_type' => $type,
            'width' => $dims[0] ?? null,
            'height' => $dims[1] ?? null,
            'created_at' => Clock::iso(),
            'used_ts' => Clock::time(),
        ];
        try {
            $this->db->insert('obx_blobs', $row);
        } catch (\PDOException) {
            // Inserted concurrently: fine, same bytes.
        }
        @unlink($path);
        return self::describe($row, true);
    }

    /**
     * Hashes and stores a local file (copying it).
     *
     * @return array{sha256: string, bytes: int, content_type: string, width: ?int, height: ?int, created: bool}
     */
    public function storeCopy(string $source, array $accepted = ImageInfo::ACCEPTED): array
    {
        $sha = (string) hash_file('sha256', $source);
        $existing = $this->get($sha);
        if ($existing !== null) {
            return self::describe($existing, false);
        }
        $tmp = $this->tempFile('cp');
        copy($source, $tmp);
        return $this->store($tmp, $sha, (int) filesize($tmp), $accepted);
    }

    /** @return array{sha256: string, bytes: int, content_type: string, width: ?int, height: ?int, created: bool} */
    public static function describe(array $row, bool $created): array
    {
        return [
            'sha256' => (string) $row['sha256'],
            'bytes' => (int) $row['bytes'],
            'content_type' => (string) $row['content_type'],
            'width' => $row['width'] !== null ? (int) $row['width'] : null,
            'height' => $row['height'] !== null ? (int) $row['height'] : null,
            'created' => $created,
        ];
    }

    public function path(string $sha): ?string
    {
        return $this->store->localPath($sha);
    }

    /** Deletes blobs that no asset, render or logo references and that are older than 24 h. */
    public function collectGarbage(int $olderThanTs, int $limit = 500): int
    {
        $referenced = [];
        foreach ($this->db->all('SELECT files, pending FROM obx_assets') as $a) {
            foreach (['files', 'pending'] as $col) {
                foreach ((array) json_decode((string) $a[$col], true) as $f) {
                    if (is_array($f) && isset($f['sha256'])) {
                        $referenced[$f['sha256']] = true;
                    }
                }
            }
        }
        foreach ($this->db->all('SELECT original_sha, sha256 FROM obx_renders') as $r) {
            $referenced[$r['original_sha']] = true;
            $referenced[$r['sha256']] = true;
        }
        $logo = $this->db->value("SELECT v FROM obx_settings WHERE k = 'settings'");
        $settings = json_decode((string) $logo, true);
        if (is_array($settings) && isset($settings['branding']['logo_sha'])) {
            $referenced[$settings['branding']['logo_sha']] = true;
        }
        $n = 0;
        foreach ($this->db->all('SELECT sha256 FROM obx_blobs WHERE used_ts < ? LIMIT ' . $limit, [$olderThanTs]) as $row) {
            if (isset($referenced[$row['sha256']])) {
                continue;
            }
            $this->store->delete($row['sha256']);
            $this->db->delete('obx_blobs', ['sha256' => $row['sha256']]);
            $this->db->run('DELETE FROM obx_renders WHERE original_sha = ?', [$row['sha256']]);
            $n++;
        }
        return $n;
    }
}
