<?php

declare(strict_types=1);

namespace Obx\Node\Support;

final class Gtin
{
    /** True when $digits is 8, 12, 13 or 14 digits with a valid GS1 check digit. */
    public static function valid(string $digits): bool
    {
        if (!preg_match('/^(\d{8}|\d{12,14})$/', $digits)) {
            return false;
        }
        return self::checkDigit(substr($digits, 0, -1)) === (int) substr($digits, -1);
    }

    public static function checkDigit(string $body): int
    {
        $sum = 0;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $d = (int) $body[$len - 1 - $i];
            $sum += ($i % 2 === 0) ? $d * 3 : $d;
        }
        return (10 - ($sum % 10)) % 10;
    }

    /** Left-pads a valid-length code to GTIN-14. */
    public static function to14(string $digits): string
    {
        return str_pad($digits, 14, '0', STR_PAD_LEFT);
    }

    /**
     * Lookup normalisation: trim, remove inner whitespace; 8/12/13/14 digits -> GTIN-14.
     * Check digits are not validated here. Returns null for anything else.
     */
    public static function normalizeLookup(string $input): ?string
    {
        $s = preg_replace('/\s+/u', '', $input) ?? '';
        if (!preg_match('/^(\d{8}|\d{12,14})$/', $s)) {
            return null;
        }
        return self::to14($s);
    }
}
