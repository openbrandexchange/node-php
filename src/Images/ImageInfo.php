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

    /**
     * The EXIF orientation (1 to 8) of a JPEG, read from its APP1 segment without the exif
     * extension; 1 when there is none or it cannot be read.
     */
    public static function exifOrientation(string $path): int
    {
        $f = @fopen($path, 'rb');
        if ($f === false) {
            return 1;
        }
        $head = (string) fread($f, 2);
        if ($head !== "\xFF\xD8") {
            fclose($f);
            return 1;
        }
        for ($i = 0; $i < 64; $i++) {
            $marker = (string) fread($f, 4);
            if (strlen($marker) < 4 || $marker[0] !== "\xFF") {
                break;
            }
            $type = ord($marker[1]);
            $len = (int) unpack('n', substr($marker, 2, 2))[1];
            if ($type === 0xDA || $len < 2) {
                break; // start of scan: no metadata after this
            }
            $data = $len > 2 ? (string) fread($f, $len - 2) : '';
            if ($type === 0xE1 && str_starts_with($data, "Exif\0\0")) {
                fclose($f);
                return self::tiffOrientation(substr($data, 6));
            }
        }
        fclose($f);
        return 1;
    }

    private static function tiffOrientation(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }
        $le = substr($tiff, 0, 2) === 'II';
        if (!$le && substr($tiff, 0, 2) !== 'MM') {
            return 1;
        }
        $u16 = static fn (int $o): int => strlen($tiff) >= $o + 2 ? (int) unpack($le ? 'v' : 'n', substr($tiff, $o, 2))[1] : 0;
        $u32 = static fn (int $o): int => strlen($tiff) >= $o + 4 ? (int) unpack($le ? 'V' : 'N', substr($tiff, $o, 4))[1] : 0;
        $ifd = $u32(4);
        $count = $u16($ifd);
        for ($n = 0; $n < $count && $n < 512; $n++) {
            $entry = $ifd + 2 + $n * 12;
            if ($u16($entry) === 0x0112) {
                $v = $u16($entry + 8);
                return $v >= 1 && $v <= 8 ? $v : 1;
            }
        }
        return 1;
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
