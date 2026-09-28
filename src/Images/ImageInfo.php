<?php

declare(strict_types=1);

namespace Obx\Node\Images;

/**
 * File-signature sniffing and header-only dimension reading for the accepted image types.
 */
final class ImageInfo
{
    public const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];

    /** Content type from magic bytes, or null when not an accepted image. */
    public static function sniff(string $head): ?string
    {
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }
        if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return 'image/png';
        }
        if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (strncmp($head, "II*\0", 4) === 0 || strncmp($head, "MM\0*", 4) === 0) {
            return 'image/tiff';
        }
        return null;
    }

    public static function sniffFile(string $path): ?string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $head = (string) fread($fh, 32);
        fclose($fh);
        return self::sniff($head);
    }

    /** @return array{0: int, 1: int}|null width, height from the file header */
    public static function dimensions(string $path): ?array
    {
        $info = @getimagesize($path);
        if (!is_array($info) || $info[0] < 1 || $info[1] < 1) {
            return null;
        }
        return [(int) $info[0], (int) $info[1]];
    }

    public static function extension(string $contentType): string
    {
        return match ($contentType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/tiff' => 'tif',
            default => 'bin',
        };
    }
}
