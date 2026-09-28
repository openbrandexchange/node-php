<?php

declare(strict_types=1);

namespace Obx\Node\Ingest;

use Obx\Node\App;
use Obx\Node\Http\ApiError;
use Obx\Node\Http\Request;
use Obx\Node\Images\ImageInfo;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Ids;
use Obx\Node\Support\Json;

/**
 * Resumable multipart uploads for large originals (docs/ingest.md §4, method 3).
 */
final class Uploads
{
    public const MAX_OBJECT_BYTES = 500 * 1024 * 1024;

    public function __construct(private App $app)
    {
    }

    private function dir(string $id): string
    {
        return $this->app->config->dataDir() . '/uploads/' . $id;
    }

    /** @param array<string, mixed> $u @return array<string, mixed> */
    public function json(array $u): array
    {
        $received = Json::decode((string) $u['received'], []);
        sort($received);
        $status = $u['status'];
        if ($status === 'open' && (int) $u['expires_ts'] < Clock::time()) {
            $status = 'expired';
        }
        return [
            'id' => $u['id'],
            'sha256' => $u['sha256'],
            'bytes' => (int) $u['bytes'],
            'content_type' => $u['content_type'],
            'part_bytes' => (int) $u['part_bytes'],
            'parts_total' => (int) $u['parts_total'],
            'received_parts' => array_values(array_map('intval', $received)),
            'status' => $status,
            'expires_at' => Clock::iso((float) $u['expires_ts']),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>} HTTP status and Blob or Upload
     */
    public function create(array $body, string $owner): array
    {
        $sha = $body['sha256'] ?? null;
        $bytes = $body['bytes'] ?? null;
        $ct = $body['content_type'] ?? null;
        if (!is_string($sha) || !preg_match('/^[a-f0-9]{64}$/', $sha) || !is_int($bytes) || $bytes < 1 || !is_string($ct)) {
            throw new ApiError('validation_failed', 'Send sha256, bytes and content_type.', [['pointer' => '', 'detail' => 'sha256 (hex), bytes (integer) and content_type are required.']]);
        }
        $existing = $this->app->blobs->get($sha);
        if ($existing !== null) {
            return [200, \Obx\Node\Services\Blobs::describe($existing, false)];
        }
        if ($bytes > self::MAX_OBJECT_BYTES) {
            throw new ApiError('payload_too_large', 'Files up to ' . self::MAX_OBJECT_BYTES . ' bytes are accepted.');
        }
        if (!in_array(strtolower($ct), ImageInfo::ACCEPTED, true)) {
            throw new ApiError('unsupported_media_type', 'Accepted: ' . implode(', ', ImageInfo::ACCEPTED) . '.');
        }
        $part = (int) $this->app->config->get('part_bytes', 8 * 1024 * 1024);
        $row = [
            'id' => Ids::new('upl'),
            'sha256' => $sha,
            'bytes' => $bytes,
            'content_type' => strtolower($ct),
            'part_bytes' => $part,
            'parts_total' => max(1, (int) ceil($bytes / $part)),
            'received' => '[]',
            'status' => 'open',
            'owner' => $owner,
            'expires_ts' => Clock::time() + 86400,
            'created_at' => Clock::iso(),
        ];
        $this->app->db->insert('obx_uploads', $row);
        @mkdir($this->dir((string) $row['id']), 0770, true);
        return [201, $this->json($row)];
    }

    /** @return array<string, mixed> */
    public function get(string $id, string $owner): array
    {
        $u = $this->app->db->one('SELECT * FROM obx_uploads WHERE id = ? AND owner = ?', [$id, $owner]);
        if ($u === null) {
            throw new ApiError('not_found', 'No such upload.');
        }
        return $u;
    }

    /** @return array<string, mixed> Upload */
    public function putPart(string $id, string $owner, int $number, Request $req): array
    {
        $u = $this->get($id, $owner);
        if ($u['status'] !== 'open' || (int) $u['expires_ts'] < Clock::time()) {
            throw new ApiError('conflict', 'This upload is no longer open.');
        }
        $total = (int) $u['parts_total'];
        if ($number < 1 || $number > $total) {
            throw new ApiError('validation_failed', 'Part numbers run from 1 to ' . $total . '.', [['pointer' => '/number', 'detail' => 'Out of range.']]);
        }
        $expected = $number < $total ? (int) $u['part_bytes'] : (int) $u['bytes'] - ($total - 1) * (int) $u['part_bytes'];
        $tmp = $req->bodyToFile($this->app->blobs->tmpDir(), (int) $u['part_bytes']);
        if ($tmp['bytes'] !== $expected) {
            @unlink($tmp['path']);
            throw new ApiError('validation_failed', 'Part ' . $number . ' must be exactly ' . $expected . ' bytes (got ' . $tmp['bytes'] . ').', [['pointer' => '/body', 'detail' => 'Wrong part size.']]);
        }
        $dir = $this->dir($id);
        @mkdir($dir, 0770, true);
        rename($tmp['path'], $dir . '/' . $number);
        $this->app->db->tx(function () use ($id, $number) {
            $row = $this->app->db->one('SELECT received FROM obx_uploads WHERE id = ?', [$id]);
            $received = Json::decode((string) $row['received'], []);
            $received[] = $number;
            $received = array_values(array_unique(array_map('intval', $received)));
            sort($received);
            $this->app->db->update('obx_uploads', ['received' => Json::encode($received)], ['id' => $id]);
        });
        return $this->json($this->get($id, $owner));
    }

    /** @return array<string, mixed> Blob */
    public function complete(string $id, string $owner): array
    {
        $u = $this->get($id, $owner);
        if ($u['status'] === 'completed') {
            $b = $this->app->blobs->get((string) $u['sha256']);
            if ($b !== null) {
                return \Obx\Node\Services\Blobs::describe($b, false);
            }
        }
        if ($u['status'] !== 'open') {
            throw new ApiError('conflict', 'This upload is no longer open.');
        }
        $received = Json::decode((string) $u['received'], []);
        $missing = array_values(array_diff(range(1, (int) $u['parts_total']), $received));
        if ($missing !== []) {
            throw new ApiError('upload_incomplete', 'Missing parts: ' . implode(', ', array_slice($missing, 0, 20)) . '.');
        }
        $dir = $this->dir($id);
        $out = $this->app->blobs->tempFile('asm');
        $fh = fopen($out, 'wb');
        $ctx = hash_init('sha256');
        $bytes = 0;
        for ($n = 1; $n <= (int) $u['parts_total']; $n++) {
            $in = fopen($dir . '/' . $n, 'rb');
            while (!feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($fh, $chunk);
                hash_update($ctx, $chunk);
                $bytes += strlen($chunk);
            }
            fclose($in);
        }
        fclose($fh);
        $sha = hash_final($ctx);
        $this->removeDir($dir);
        if ($sha !== $u['sha256'] || $bytes !== (int) $u['bytes']) {
            @unlink($out);
            $this->app->db->update('obx_uploads', ['status' => 'aborted'], ['id' => $id]);
            throw new ApiError('checksum_mismatch', 'The assembled file does not match the declared sha256 and size. The upload was aborted.');
        }
        $blob = $this->app->blobs->store($out, $sha, $bytes);
        $this->app->db->update('obx_uploads', ['status' => 'completed'], ['id' => $id]);
        return $blob;
    }

    public function abort(string $id, string $owner): void
    {
        $this->get($id, $owner);
        $this->removeDir($this->dir($id));
        $this->app->db->update('obx_uploads', ['status' => 'aborted'], ['id' => $id]);
    }

    public function expire(): void
    {
        foreach ($this->app->db->all("SELECT id FROM obx_uploads WHERE expires_ts < ?", [Clock::time()]) as $u) {
            $this->removeDir($this->dir((string) $u['id']));
            $this->app->db->delete('obx_uploads', ['id' => $u['id']]);
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
