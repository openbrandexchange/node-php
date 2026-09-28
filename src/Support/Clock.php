<?php

declare(strict_types=1);

namespace Obx\Node\Support;

/**
 * Wall clock with an offset that tests can move forward.
 */
final class Clock
{
    public static float $offset = 0.0;

    public static function now(): float
    {
        return microtime(true) + self::$offset;
    }

    public static function time(): int
    {
        return (int) floor(self::now());
    }

    /** RFC 3339 UTC timestamp with millisecond precision, e.g. 2026-09-28T12:00:00.123Z. */
    public static function iso(?float $ts = null): string
    {
        $ts ??= self::now();
        $sec = (int) floor($ts);
        $ms = (int) floor(($ts - $sec) * 1000);
        return gmdate('Y-m-d\TH:i:s', $sec) . sprintf('.%03dZ', $ms);
    }

    /** Parses an RFC 3339 timestamp or a YYYY-MM-DD date (midnight UTC). Returns null when invalid. */
    public static function parse(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        // A "+" in an unencoded query string arrives as a space: "…T10:00:00 02:00".
        $value = preg_replace('/(\d{2}:\d{2}:\d{2}(?:\.\d+)?) (\d{2}:\d{2})$/', '$1+$2', $value) ?? $value;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= 'T00:00:00Z';
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[Tt ](\d{2}):(\d{2}):(\d{2})(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/', $value, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 60) {
            return null;
        }
        $base = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
        $frac = isset($m[7]) && $m[7] !== '' ? (float) ('0' . $m[7]) : 0.0;
        $tz = strtoupper($m[8]);
        $offset = 0;
        if ($tz !== 'Z') {
            $sign = $tz[0] === '-' ? -1 : 1;
            $offset = $sign * ((int) substr($tz, 1, 2) * 3600 + (int) substr($tz, 4, 2) * 60);
        }
        return $base - $offset + $frac;
    }

    /**
     * Normalises a user-supplied RFC 3339 value to UTC "Z" form. Whole seconds stay without a
     * fraction ("2030-01-01T00:00:00Z"); fractions keep millisecond precision.
     */
    public static function normalize(?string $value): ?string
    {
        $ts = self::parse($value);
        if ($ts === null) {
            return null;
        }
        $sec = (int) floor($ts);
        $ms = (int) round(($ts - $sec) * 1000);
        if ($ms >= 1000) {
            $sec++;
            $ms = 0;
        }
        return gmdate('Y-m-d\TH:i:s', $sec) . ($ms > 0 ? sprintf('.%03d', $ms) : '') . 'Z';
    }
}
