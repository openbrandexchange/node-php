<?php

declare(strict_types=1);

namespace Obx\Node\Support;

/**
 * Node-assigned opaque ids: a short type prefix plus 20 lowercase base32 characters
 * (100 bits of randomness). Lowercase only, so byte order and database collations agree.
 */
final class Ids
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    public static function new(string $prefix): string
    {
        return $prefix . '_' . self::random(20);
    }

    public static function random(int $length): string
    {
        $bytes = random_bytes($length);
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[ord($bytes[$i]) & 31];
        }
        return $out;
    }

    public static function valid(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._~-]{0,127}$/', $id);
    }
}
