<?php

declare(strict_types=1);

namespace Obx\Node\Images;

use Obx\Node\Db\Db;
use Obx\Node\Http\ApiError;
use Obx\Node\Services\Blobs;

/**
 * Server-side rendition cache: one set of web/square/thumb per original sha256, so an
 * unchanged image is never rendered twice (re-imports cost nothing).
 */
final class Renditions
{
    public function __construct(private Db $db, private Blobs $blobs, private bool $enabled)
    {
    }

    public function serverGenerated(): bool
    {
        return $this->enabled;
    }

    /** @return array<string, array{sha256: string, width: int, height: int}> */
    public function ensure(string $originalSha): array
    {
        $rows = $this->db->all('SELECT name, sha256, width, height FROM obx_renders WHERE original_sha = ?', [$originalSha]);
        $out = [];
        foreach ($rows as $r) {
            if ($this->blobs->has((string) $r['sha256'])) {
                $out[(string) $r['name']] = ['sha256' => (string) $r['sha256'], 'width' => (int) $r['width'], 'height' => (int) $r['height']];
            }
        }
        if (isset($out['web'], $out['square'], $out['thumb'])) {
            return $out;
        }
        $blob = $this->blobs->get($originalSha);
        $path = $blob !== null ? $this->blobs->path($originalSha) : null;
        if ($blob === null || $path === null) {
            throw new ApiError('blob_missing', 'The original ' . $originalSha . ' has not been uploaded.');
        }
        try {
            $files = Renderer::render($path, (string) $blob['content_type'], $this->blobs->tmpDir());
        } catch (\RuntimeException $e) {
            throw new ApiError('validation_failed', 'The image could not be converted: ' . $e->getMessage(), [['pointer' => '/files/original', 'detail' => $e->getMessage()]]);
        }
        $out = [];
        foreach ($files as $name => $f) {
            $sha = (string) hash_file('sha256', $f['path']);
            $this->blobs->store($f['path'], $sha, (int) filesize($f['path']), ['image/jpeg']);
            $this->db->run('DELETE FROM obx_renders WHERE original_sha = ? AND name = ?', [$originalSha, $name]);
            $this->db->insert('obx_renders', [
                'original_sha' => $originalSha,
                'name' => $name,
                'sha256' => $sha,
                'width' => $f['width'],
                'height' => $f['height'],
            ]);
            $out[$name] = ['sha256' => $sha, 'width' => $f['width'], 'height' => $f['height']];
        }
        return $out;
    }
}
