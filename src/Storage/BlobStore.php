<?php

declare(strict_types=1);

namespace Obx\Node\Storage;

/**
 * Content-addressed byte storage (sha256 keys). Metadata lives in the obx_blobs table; this
 * interface only moves bytes.
 */
interface BlobStore
{
    /** Stores the file at $path under $sha256. The source file may be moved. */
    public function put(string $sha256, string $path, string $contentType): void;

    public function exists(string $sha256): bool;

    /** A local, readable path with the bytes (downloaded to a cache for remote stores). */
    public function localPath(string $sha256): ?string;

    public function delete(string $sha256): void;

    /** Throws when the store cannot be reached or written (readiness check). */
    public function ping(): void;
}
