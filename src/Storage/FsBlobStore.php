<?php

declare(strict_types=1);

namespace Obx\Node\Storage;

/**
 * Blobs on the local filesystem: <dir>/ab/cd/<sha256>. Files are written once and never changed.
 */
final class FsBlobStore implements BlobStore
{
    public function __construct(private string $dir)
    {
    }

    public function ping(): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0770, true);
        }
        if (!is_dir($this->dir) || !is_writable($this->dir)) {
            throw new \RuntimeException('The blob folder ' . $this->dir . ' is not writable.');
        }
    }

    private function path(string $sha): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
            throw new \InvalidArgumentException('Invalid blob key.');
        }
        return $this->dir . '/' . substr($sha, 0, 2) . '/' . substr($sha, 2, 2) . '/' . $sha;
    }

    public function put(string $sha256, string $path, string $contentType): void
    {
        $target = $this->path($sha256);
        if (is_file($target)) {
            return;
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the blob directory.');
        }
        $tmp = $target . '.tmp' . bin2hex(random_bytes(4));
        if (!@rename($path, $tmp)) {
            if (!@copy($path, $tmp)) {
                throw new \RuntimeException('Cannot store the file.');
            }
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            if (!is_file($target)) {
                throw new \RuntimeException('Cannot store the file.');
            }
        }
    }

    public function exists(string $sha256): bool
    {
        return is_file($this->path($sha256));
    }

    public function localPath(string $sha256): ?string
    {
        $p = $this->path($sha256);
        return is_file($p) ? $p : null;
    }

    public function delete(string $sha256): void
    {
        @unlink($this->path($sha256));
    }
}
