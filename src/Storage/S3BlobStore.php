<?php

declare(strict_types=1);

namespace Obx\Node\Storage;

/**
 * Blobs in an S3-compatible bucket (optional; for hosts with an ephemeral disk such as
 * Cloud Run). Reads are cached on local disk so ranges and hashes are served locally.
 */
final class S3BlobStore implements BlobStore
{
    public function __construct(private S3Client $s3, private string $prefix, private string $cacheDir)
    {
    }

    public function ping(): void
    {
        $this->s3->ping($this->prefix);
    }

    private function key(string $sha): string
    {
        return $this->prefix . substr($sha, 0, 2) . '/' . $sha;
    }

    private function cachePath(string $sha): string
    {
        return $this->cacheDir . '/' . substr($sha, 0, 2) . '/' . $sha;
    }

    public function put(string $sha256, string $path, string $contentType): void
    {
        $this->s3->put($this->key($sha256), $path, $contentType);
        $cache = $this->cachePath($sha256);
        if (!is_dir(dirname($cache))) {
            @mkdir(dirname($cache), 0770, true);
        }
        if (!@rename($path, $cache)) {
            @copy($path, $cache);
        }
    }

    public function exists(string $sha256): bool
    {
        return is_file($this->cachePath($sha256)) || $this->s3->exists($this->key($sha256));
    }

    public function localPath(string $sha256): ?string
    {
        $cache = $this->cachePath($sha256);
        if (is_file($cache)) {
            return $cache;
        }
        if (!is_dir(dirname($cache))) {
            @mkdir(dirname($cache), 0770, true);
        }
        $tmp = $cache . '.part' . bin2hex(random_bytes(4));
        try {
            $this->s3->get($this->key($sha256), $tmp);
        } catch (\RuntimeException) {
            @unlink($tmp);
            return null;
        }
        if (!hash_equals($sha256, (string) hash_file('sha256', $tmp))) {
            @unlink($tmp);
            return null;
        }
        rename($tmp, $cache);
        return $cache;
    }

    public function delete(string $sha256): void
    {
        $this->s3->delete($this->key($sha256));
        @unlink($this->cachePath($sha256));
    }
}
