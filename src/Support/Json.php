<?php

declare(strict_types=1);

namespace Obx\Node\Support;

use Obx\Node\Http\ApiError;

final class Json
{
    public static function encode(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode($value, $flags);
    }

    /** Decodes JSON to arrays; objects become associative arrays. Returns $default for null/empty input. */
    public static function decode(?string $json, mixed $default = null): mixed
    {
        if ($json === null || $json === '') {
            return $default;
        }
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return $default;
        }
    }

    /** Decodes a request body; malformed JSON is a 400 invalid_request. */
    public static function decodeBody(string $body): mixed
    {
        if (trim($body) === '') {
            throw new ApiError('invalid_request', 'The request body is empty; JSON was expected.');
        }
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new ApiError('invalid_request', 'The request body is not valid JSON: ' . $e->getMessage());
        }
    }

    /**
     * Canonical encoding for hashing: object keys sorted recursively, lists kept in order.
     */
    public static function canonical(mixed $value): string
    {
        return self::encode(self::sortKeys($value));
    }

    public static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'sortKeys'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $k => $v) {
            $value[$k] = self::sortKeys($v);
        }
        return $value;
    }

    /** True when $value is a JSON object (associative array or empty array coming from "{}"). */
    public static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /** Encodes an array that must serialise as a JSON object even when empty. */
    public static function obj(array $value): array|\stdClass
    {
        return $value === [] ? new \stdClass() : $value;
    }
}
