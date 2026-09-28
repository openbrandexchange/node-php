<?php

declare(strict_types=1);

namespace Obx\Node\Support;

final class Str
{
    private const TRANSLIT = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ð' => 'd', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'þ' => 'th', 'ß' => 'ss',
        'ā' => 'a', 'ă' => 'a', 'ą' => 'a', 'ć' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd', 'ē' => 'e',
        'ė' => 'e', 'ę' => 'e', 'ě' => 'e', 'ğ' => 'g', 'ī' => 'i', 'į' => 'i', 'ı' => 'i', 'ķ' => 'k',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ł' => 'l', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ō' => 'o',
        'ő' => 'o', 'œ' => 'oe', 'ŕ' => 'r', 'ř' => 'r', 'ś' => 's', 'ş' => 's', 'š' => 's', 'ș' => 's',
        'ţ' => 't', 'ť' => 't', 'ț' => 't', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u', 'ź' => 'z',
        'ż' => 'z', 'ž' => 'z', 'ĳ' => 'ij',
    ];

    /**
     * The OBX slug rule: lowercase, diacritics stripped, every run of characters outside
     * a-z0-9 becomes "-", leading/trailing "-" removed.
     */
    public static function slug(string $value): string
    {
        $value = self::lower($value);
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if (is_string($normalized)) {
                $value = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $value;
            }
        }
        $value = strtr($value, self::TRANSLIT);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    public static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    public static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : (int) preg_match_all('/./us', $value);
    }

    public static function truncate(string $value, int $max): string
    {
        if (self::length($value) <= $max) {
            return $value;
        }
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }

    /** Safe download file name: no path separators, control chars or leading dots. */
    public static function safeFilename(string $name, string $fallback = 'file'): string
    {
        $name = str_replace(['/', '\\', "\0"], '_', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = ltrim(trim($name), '.');
        if ($name === '' || $name === '..') {
            return $fallback;
        }
        return self::truncate($name, 200);
    }

    /** Content-Disposition value with an ASCII fallback and RFC 6266 filename* for non-ASCII. */
    public static function contentDisposition(string $type, string $filename): string
    {
        $filename = self::safeFilename($filename);
        $ascii = preg_replace('/[^\x20-\x7E]/u', '_', $filename) ?? 'file';
        $ascii = str_replace(['"', '\\'], '_', $ascii);
        $value = $type . '; filename="' . $ascii . '"';
        if ($ascii !== $filename) {
            $value .= "; filename*=UTF-8''" . rawurlencode($filename);
        }
        return $value;
    }

    /**
     * File name of a rendition: renditions other than original are JPEG and get _web,
     * _square or _thumb inserted before the .jpg extension.
     */
    public static function renditionFilename(string $filename, string $rendition): string
    {
        if ($rendition === 'original') {
            return $filename;
        }
        $base = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $filename) ?? $filename;
        return $base . '_' . $rendition . '.jpg';
    }
}
