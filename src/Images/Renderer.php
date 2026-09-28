<?php

declare(strict_types=1);

namespace Obx\Node\Images;

/**
 * Server-side renditions with GD: web (longest side <= 2000 px), square (web centred on a white
 * square), thumb (longest side <= 600 px). Always JPEG, never upscaled, transparency flattened
 * on white. The original is never modified.
 */
final class Renderer
{
    public const WEB_MAX = 2000;
    public const THUMB_MAX = 600;
    public const MAX_PIXELS = 100_000_000;
    private const QUALITY = 85;

    public static function available(): bool
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            return false;
        }
        $info = gd_info();
        return !empty($info['JPEG Support']) && !empty($info['PNG Support']);
    }

    /** @return array{0: int, 1: int} */
    public static function fit(int $w, int $h, int $max): array
    {
        $long = max($w, $h);
        if ($long <= $max) {
            return [$w, $h];
        }
        $scale = $max / $long;
        return [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
    }

    /**
     * Renders web, square and thumb from $source into $outDir.
     *
     * @return array<string, array{path: string, width: int, height: int}>
     */
    public static function render(string $source, string $contentType, string $outDir): array
    {
        $dims = ImageInfo::dimensions($source);
        if ($dims === null) {
            throw new \RuntimeException('Cannot read the image dimensions.');
        }
        [$w, $h] = $dims;
        if ($w * $h > self::MAX_PIXELS) {
            throw new \RuntimeException('The image is larger than 100 megapixels.');
        }
        self::ensureMemory($w * $h);
        $img = match ($contentType) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default => false,
        };
        if ($img === false) {
            throw new \RuntimeException('This image type cannot be converted on this server.');
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0770, true);
        }
        [$ww, $wh] = self::fit($w, $h, self::WEB_MAX);
        $web = self::resizeOnWhite($img, $w, $h, $ww, $wh);
        imagedestroy($img);
        $out = [];
        $out['web'] = ['path' => self::save($web, $outDir, 'web'), 'width' => $ww, 'height' => $wh];

        $side = max($ww, $wh);
        $square = imagecreatetruecolor($side, $side);
        imagefill($square, 0, 0, imagecolorallocate($square, 255, 255, 255));
        imagecopy($square, $web, intdiv($side - $ww, 2), intdiv($side - $wh, 2), 0, 0, $ww, $wh);
        $out['square'] = ['path' => self::save($square, $outDir, 'square'), 'width' => $side, 'height' => $side];
        imagedestroy($square);

        [$tw, $th] = self::fit($ww, $wh, self::THUMB_MAX);
        if ([$tw, $th] === [$ww, $wh]) {
            $thumb = $web;
        } else {
            $thumb = imagecreatetruecolor($tw, $th);
            imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
            imagecopyresampled($thumb, $web, 0, 0, 0, 0, $tw, $th, $ww, $wh);
        }
        $out['thumb'] = ['path' => self::save($thumb, $outDir, 'thumb'), 'width' => $tw, 'height' => $th];
        if ($thumb !== $web) {
            imagedestroy($thumb);
        }
        imagedestroy($web);
        return $out;
    }

    private static function resizeOnWhite(\GdImage $src, int $w, int $h, int $nw, int $nh): \GdImage
    {
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagealphablending($dst, true);
        if ($nw === $w && $nh === $h) {
            imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        } else {
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        }
        return $dst;
    }

    private static function save(\GdImage $img, string $dir, string $name): string
    {
        $path = $dir . '/' . $name . '-' . bin2hex(random_bytes(6)) . '.jpg';
        if (!imagejpeg($img, $path, self::QUALITY)) {
            throw new \RuntimeException('Writing the JPEG failed.');
        }
        return $path;
    }

    private static function ensureMemory(int $pixels): void
    {
        $need = $pixels * 10 + 64 * 1024 * 1024;
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit > 0 && $limit < $need) {
            @ini_set('memory_limit', (string) $need);
        }
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
