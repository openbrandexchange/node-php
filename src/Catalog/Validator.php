<?php

declare(strict_types=1);

namespace Obx\Node\Catalog;

use Obx\Node\Http\ApiError;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Gtin;
use Obx\Node\Support\Json;

/**
 * Validates and normalises Ingest write models (BrandInput, ProductInput, VariantInput,
 * AssetInput). Produces the canonical stored model: every known field present, omitted
 * optional fields null, localised text keys sorted. Unknown fields become warnings.
 */
final class Validator
{
    public const ROLES = ['packshot', 'model', 'detail', 'ghost', 'flatlay', 'scene', 'swatch', 'size_chart', 'other'];
    public const VIEWS = ['front', 'back', 'side', 'left', 'right', 'top', 'bottom', 'tilted_left', 'tilted_right', 'inside', 'detail', 'other'];
    public const CHANNELS = ['webshop', 'marketplace', 'social', 'print'];
    public const RENDITIONS = ['original', 'web', 'square', 'thumb'];

    /** @var list<array{pointer: string, detail: string, obx_error?: string}> */
    private array $errors = [];
    /** @var list<string> */
    public array $warnings = [];

    public static function externalId(string $raw): string
    {
        $id = trim($raw);
        if ($id === '' || strlen($id) > 200 && mb_strlen($id, 'UTF-8') > 200 || preg_match('/[\x00-\x1F\x7F\/]/', $id)) {
            throw ApiError::validation('/external_id', 'The external id must be 1-200 characters without control characters or "/".');
        }
        return $id;
    }

    private function err(string $pointer, string $detail, string $code = 'validation_failed'): void
    {
        $this->errors[] = ['pointer' => $pointer, 'detail' => $detail, 'obx_error' => $code];
    }

    private function finish(): void
    {
        if ($this->errors === []) {
            return;
        }
        $codes = array_unique(array_map(static fn ($e) => $e['obx_error'] ?? 'validation_failed', $this->errors));
        $code = count($codes) === 1 ? $codes[0] : 'validation_failed';
        $detail = count($this->errors) === 1 ? $this->errors[0]['detail'] : 'The request has ' . count($this->errors) . ' invalid fields.';
        throw new ApiError($code, $detail, $this->errors);
    }

    /** @param array<string, mixed> $data */
    private function unknown(array $data, array $known, string $base = ''): void
    {
        foreach (array_keys($data) as $k) {
            if (!in_array($k, $known, true)) {
                $this->warnings[] = 'Unknown field "' . $base . $k . '" ignored.';
            }
        }
    }

    private function str(array $d, string $k, string $p, int $max, bool $required = false, ?string $pattern = null): ?string
    {
        if (!array_key_exists($k, $d) || $d[$k] === null) {
            if ($required) {
                $this->err($p . '/' . $k, 'Required.');
            }
            return null;
        }
        $v = $d[$k];
        if (is_int($v) || is_float($v)) {
            $v = (string) $v;
        }
        if (!is_string($v)) {
            $this->err($p . '/' . $k, 'Must be a string.');
            return null;
        }
        $v = trim($v);
        if ($v === '') {
            if ($required) {
                $this->err($p . '/' . $k, 'Must not be empty.');
            }
            return null;
        }
        if (mb_strlen($v, 'UTF-8') > $max) {
            $this->err($p . '/' . $k, 'At most ' . $max . ' characters.');
            return null;
        }
        if ($pattern !== null && !preg_match($pattern, $v)) {
            $this->err($p . '/' . $k, 'Invalid format.');
            return null;
        }
        return $v;
    }

    private function url(array $d, string $k, string $p): ?string
    {
        $v = $this->str($d, $k, $p, 2048);
        if ($v !== null && (!preg_match('#^https?://[^\s]+$#i', $v) || filter_var($v, FILTER_VALIDATE_URL) === false)) {
            $this->err($p . '/' . $k, 'Must be an absolute http(s) URL.');
            return null;
        }
        return $v;
    }

    /** @return array<string, string>|null */
    public function localized(mixed $v, string $pointer, bool $required = false): ?array
    {
        if ($v === null) {
            if ($required) {
                $this->err($pointer, 'Required.');
            }
            return null;
        }
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            $this->err($pointer, 'Must be an object keyed by language, e.g. {"en": "…"}.');
            return null;
        }
        $out = [];
        foreach ($v as $lang => $text) {
            $lang = (string) $lang;
            if (!preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $lang)) {
                $this->err($pointer . '/' . $lang, 'Language keys are BCP 47 tags such as "en" or "nl-BE".');
                continue;
            }
            if ($text === null) {
                continue;
            }
            if (!is_string($text) || trim($text) === '') {
                $this->err($pointer . '/' . $lang, 'Must be a non-empty string.');
                continue;
            }
            if (mb_strlen($text, 'UTF-8') > 20000) {
                $this->err($pointer . '/' . $lang, 'At most 20000 characters.');
                continue;
            }
            $out[$lang] = $text;
        }
        if ($out === []) {
            if ($required) {
                $this->err($pointer, 'At least one language is required.');
            }
            return null;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    private function status(array $d, string $p): ?string
    {
        if (!array_key_exists('status', $d) || $d['status'] === null) {
            return null;
        }
        if (!in_array($d['status'], ['published', 'unpublished'], true)) {
            $this->err($p . '/status', 'Must be "published" or "unpublished".');
            return null;
        }
        return $d['status'];
    }

    // ---------------------------------------------------------------- brand

    /** @return array{0: array<string, mixed>, 1: ?string} [model, status] */
    public function brand(mixed $data): array
    {
        $d = $this->object($data);
        $this->unknown($d, ['name', 'logo_url', 'website', 'description', 'status']);
        $model = [
            'name' => $this->str($d, 'name', '', 200, true),
            'logo_url' => $this->url($d, 'logo_url', ''),
            'website' => $this->url($d, 'website', ''),
            'description' => $this->localized($d['description'] ?? null, '/description'),
        ];
        $status = $this->status($d, '');
        $this->finish();
        return [$model, $status];
    }

    // ---------------------------------------------------------------- product

    /** @return array{0: array<string, mixed>, 1: ?string} */
    public function product(mixed $data): array
    {
        $d = $this->object($data);
        $known = ['brand_external_id', 'style_number', 'name', 'description', 'item_type', 'gpc_brick', 'target_group', 'season',
            'collection', 'materials', 'care', 'country_of_origin', 'dpp_url', 'attributes', 'status'];
        $this->unknown($d, $known);
        $key = '/^[a-z][a-z0-9_]{0,63}$/';
        $model = [
            'brand_external_id' => $this->str($d, 'brand_external_id', '', 200, false, '/^[^\x00-\x1F\x7F\/]+$/'),
            'style_number' => $this->str($d, 'style_number', '', 200, true),
            'name' => $this->localized($d['name'] ?? null, '/name', true),
            'description' => $this->localized($d['description'] ?? null, '/description'),
            'item_type' => $this->str($d, 'item_type', '', 64, false, $key),
            'gpc_brick' => $this->str($d, 'gpc_brick', '', 8, false, '/^[0-9]{8}$/'),
            'target_group' => $this->str($d, 'target_group', '', 64, false, $key),
            'season' => $this->str($d, 'season', '', 64),
            'collection' => $this->str($d, 'collection', '', 200),
            'materials' => $this->materials($d['materials'] ?? null, '/materials'),
            'care' => $this->care($d['care'] ?? null, '/care'),
            'country_of_origin' => $this->str($d, 'country_of_origin', '', 2, false, '/^[A-Z]{2}$/'),
            'dpp_url' => $this->url($d, 'dpp_url', ''),
            'attributes' => $this->attributes($d['attributes'] ?? null, '/attributes'),
        ];
        $status = $this->status($d, '');
        $this->finish();
        return [$model, $status];
    }

    /** @return list<array<string, mixed>>|null */
    public function materials(mixed $v, string $p): ?array
    {
        if ($v === null) {
            return null;
        }
        if (!is_array($v) || !array_is_list($v)) {
            $this->err($p, 'Must be a list of parts.');
            return null;
        }
        $out = [];
        foreach ($v as $i => $part) {
            if (!is_array($part)) {
                $this->err($p . '/' . $i, 'Must be an object.');
                continue;
            }
            $name = $part['part'] ?? null;
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
                $this->err($p . '/' . $i . '/part', 'Part keys are lowercase, e.g. "main" or "lining".');
                continue;
            }
            $fibers = $part['fibers'] ?? null;
            if (!is_array($fibers) || $fibers === [] || !array_is_list($fibers)) {
                $this->err($p . '/' . $i . '/fibers', 'At least one fibre is required.');
                continue;
            }
            $fo = [];
            foreach ($fibers as $j => $f) {
                $fk = is_array($f) ? ($f['fiber'] ?? null) : null;
                $pct = is_array($f) ? ($f['percentage'] ?? null) : null;
                if (!is_string($fk) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $fk)) {
                    $this->err($p . '/' . $i . '/fibers/' . $j . '/fiber', 'Fibre keys are lowercase, e.g. "cotton".');
                    continue;
                }
                if (!is_int($pct) && !is_float($pct) || $pct <= 0 || $pct > 100) {
                    $this->err($p . '/' . $i . '/fibers/' . $j . '/percentage', 'A number above 0 and at most 100.');
                    continue;
                }
                $fo[] = ['fiber' => $fk, 'percentage' => self::number($pct)];
            }
            $out[] = ['part' => $name, 'fibers' => $fo];
        }
        return $out;
    }

    public static function number(int|float $n): int|float
    {
        return is_float($n) && floor($n) === $n && abs($n) < 1e15 ? (int) $n : $n;
    }

    /** @return list<array{code: string, text: ?array}>|null */
    public function care(mixed $v, string $p): ?array
    {
        if ($v === null) {
            return null;
        }
        if (!is_array($v) || !array_is_list($v)) {
            $this->err($p, 'Must be a list of care codes.');
            return null;
        }
        $out = [];
        foreach ($v as $i => $item) {
            $code = is_array($item) ? ($item['code'] ?? null) : null;
            if (!is_string($code) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code)) {
                $this->err($p . '/' . $i . '/code', 'Care codes are lowercase keys, e.g. "wash_30".');
                continue;
            }
            $out[] = ['code' => $code, 'text' => $this->localized($item['text'] ?? null, $p . '/' . $i . '/text')];
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function attributes(mixed $v, string $p): ?array
    {
        if ($v === null) {
            return null;
        }
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            $this->err($p, 'Must be an object.');
            return null;
        }
        $out = [];
        foreach ($v as $k => $val) {
            $k = (string) $k;
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $k)) {
                $this->err($p . '/' . $k, 'Attribute keys are lower_snake_case.');
                continue;
            }
            if (is_string($val) || is_int($val) || is_float($val) || is_bool($val)) {
                $out[$k] = $val;
            } elseif (is_array($val)) {
                $l = $this->localized($val, $p . '/' . $k);
                if ($l !== null) {
                    $out[$k] = $l;
                }
            } elseif ($val !== null) {
                $this->err($p . '/' . $k, 'Values are strings, numbers, booleans or localised text.');
            }
        }
        ksort($out, SORT_STRING);
        return $out === [] ? null : $out;
    }

    // ---------------------------------------------------------------- variant

    /** @return array{0: array<string, mixed>, 1: ?string} */
    public function variant(mixed $data): array
    {
        $d = $this->object($data);
        $this->unknown($d, ['product_external_id', 'gtin', 'sku', 'color', 'size', 'size_system', 'status']);
        $gtin = null;
        if (array_key_exists('gtin', $d) && $d['gtin'] !== null && $d['gtin'] !== '') {
            $g = is_int($d['gtin']) ? (string) $d['gtin'] : $d['gtin'];
            if (!is_string($g) || !preg_match('/^(\d{8}|\d{12,14})$/', $g)) {
                $this->err('/gtin', 'A GTIN has 8, 12, 13 or 14 digits.', 'invalid_gtin');
            } elseif (!Gtin::valid($g)) {
                $this->err('/gtin', 'The check digit of this GTIN is wrong.', 'invalid_gtin');
            } else {
                $gtin = $g;
            }
        }
        $color = $d['color'] ?? null;
        $colorOut = null;
        if (!is_array($color) || array_is_list($color) && $color !== []) {
            $this->err('/color', 'Required: an object with at least "code".');
        } else {
            $this->unknown($color, ['code', 'name', 'supplier_color'], 'color.');
            $colorOut = [
                'code' => $this->str($color, 'code', '/color', 64, true),
                'name' => $this->localized($color['name'] ?? null, '/color/name'),
                'supplier_color' => $this->str($color, 'supplier_color', '/color', 200),
            ];
        }
        $model = [
            'product_external_id' => $this->str($d, 'product_external_id', '', 200, true, '/^[^\x00-\x1F\x7F\/]+$/'),
            'gtin' => $gtin,
            'sku' => $this->str($d, 'sku', '', 200),
            'color' => $colorOut,
            'size' => $this->str($d, 'size', '', 64),
            'size_system' => $this->str($d, 'size_system', '', 16),
        ];
        $status = $this->status($d, '');
        $this->finish();
        return [$model, $status];
    }

    // ---------------------------------------------------------------- asset

    /**
     * @return array{0: array<string, mixed>, 1: ?string, 2: ?array} [model, status, files|null]
     */
    public function asset(mixed $data): array
    {
        $d = $this->object($data);
        $known = ['product_external_id', 'color_code', 'variant_external_ids', 'media_type', 'role', 'view', 'position',
            'filename', 'alt_text', 'rights', 'provenance', 'files', 'status'];
        $this->unknown($d, $known);
        if (isset($d['media_type']) && $d['media_type'] !== 'image') {
            $this->err('/media_type', 'Only "image" is supported.');
        }
        $role = $d['role'] ?? null;
        if (!is_string($role) || !in_array($role, self::ROLES, true)) {
            $this->err('/role', 'Required: one of ' . implode(', ', self::ROLES) . '.');
            $role = null;
        }
        $view = $d['view'] ?? null;
        if ($view !== null && (!is_string($view) || !in_array($view, self::VIEWS, true))) {
            $this->err('/view', 'One of ' . implode(', ', self::VIEWS) . ' or null.');
            $view = null;
        }
        $pos = $d['position'] ?? null;
        if (!is_int($pos) || $pos < 1 || $pos > 999) {
            $this->err('/position', 'Required: a whole number from 1 to 999.');
            $pos = null;
        }
        $variants = null;
        if (isset($d['variant_external_ids'])) {
            if (!is_array($d['variant_external_ids']) || !array_is_list($d['variant_external_ids'])) {
                $this->err('/variant_external_ids', 'Must be a list of variant external ids.');
            } else {
                $variants = array_values(array_unique(array_map('strval', $d['variant_external_ids'])));
                sort($variants, SORT_STRING);
                if ($variants === []) {
                    $variants = null;
                }
            }
        }
        $filename = $this->str($d, 'filename', '', 255);
        if ($filename !== null && preg_match('#[/\\\\\x00-\x1F]#', $filename)) {
            $this->err('/filename', 'A file name without folders.');
        }
        $model = [
            'product_external_id' => $this->str($d, 'product_external_id', '', 200, true, '/^[^\x00-\x1F\x7F\/]+$/'),
            'color_code' => $this->str($d, 'color_code', '', 64),
            'variant_external_ids' => $variants,
            'media_type' => 'image',
            'role' => $role,
            'view' => $view,
            'position' => $pos,
            'filename' => $filename,
            'alt_text' => $this->localized($d['alt_text'] ?? null, '/alt_text'),
            'rights' => $this->rights($d['rights'] ?? null, '/rights'),
            'provenance' => $this->provenance($d['provenance'] ?? null, '/provenance'),
        ];
        $files = null;
        if (array_key_exists('files', $d) && $d['files'] !== null) {
            $files = $this->files($d['files'], '/files');
        }
        $status = $this->status($d, '');
        $this->finish();
        return [$model, $status, $files];
    }

    /** @return array<string, mixed>|null partial rights (only given fields) */
    public function rights(mixed $v, string $p): ?array
    {
        if ($v === null) {
            return null;
        }
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            $this->err($p, 'Must be an object.');
            return null;
        }
        $this->unknown($v, ['license', 'license_text', 'channels', 'territories', 'embargo_until', 'expires_at', 'credit', 'model_release'], 'rights.');
        $out = [];
        if (array_key_exists('license', $v)) {
            if (!in_array($v['license'], ['retailer-use', 'custom'], true)) {
                $this->err($p . '/license', 'Either "retailer-use" or "custom".');
            } else {
                $out['license'] = $v['license'];
            }
        }
        if (array_key_exists('license_text', $v)) {
            $out['license_text'] = $this->str($v, 'license_text', $p, 20000);
        }
        if (($out['license'] ?? null) === 'custom' && ($out['license_text'] ?? null) === null) {
            $this->err($p . '/license_text', 'Required when the licence is "custom".');
        }
        if (array_key_exists('channels', $v)) {
            if (!is_array($v['channels']) || !array_is_list($v['channels']) || array_diff($v['channels'], self::CHANNELS) !== []) {
                $this->err($p . '/channels', 'A list of: ' . implode(', ', self::CHANNELS) . '.');
            } else {
                $out['channels'] = array_values(array_intersect(self::CHANNELS, $v['channels']));
            }
        }
        if (array_key_exists('territories', $v)) {
            if ($v['territories'] === null) {
                $out['territories'] = null;
            } elseif (!is_array($v['territories']) || !array_is_list($v['territories'])) {
                $this->err($p . '/territories', 'A list of ISO country codes, or null for worldwide.');
            } else {
                $t = [];
                foreach ($v['territories'] as $c) {
                    if (!is_string($c) || !preg_match('/^[A-Z]{2}$/', $c)) {
                        $this->err($p . '/territories', 'Country codes are two capital letters, e.g. "NL".');
                        break;
                    }
                    $t[] = $c;
                }
                $out['territories'] = array_values(array_unique($t));
            }
        }
        foreach (['embargo_until', 'expires_at'] as $k) {
            if (array_key_exists($k, $v)) {
                if ($v[$k] === null) {
                    $out[$k] = null;
                } else {
                    $n = is_string($v[$k]) ? Clock::normalize($v[$k]) : null;
                    if ($n === null) {
                        $this->err($p . '/' . $k, 'An RFC 3339 date-time, e.g. 2030-01-01T00:00:00Z.');
                    } else {
                        $out[$k] = $n;
                    }
                }
            }
        }
        if (array_key_exists('credit', $v)) {
            $out['credit'] = $this->str($v, 'credit', $p, 500);
        }
        if (array_key_exists('model_release', $v)) {
            if ($v['model_release'] !== null && !is_bool($v['model_release'])) {
                $this->err($p . '/model_release', 'true, false or null.');
            } else {
                $out['model_release'] = $v['model_release'];
            }
        }
        ksort($out);
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function provenance(mixed $v, string $p): ?array
    {
        if ($v === null) {
            return null;
        }
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            $this->err($p, 'Must be an object.');
            return null;
        }
        $this->unknown($v, ['ai_generated', 'ai_edited', 'source', 'c2pa', 'c2pa_manifest_url', 'generator'], 'provenance.');
        $out = [];
        foreach (['ai_generated', 'ai_edited'] as $k) {
            if (array_key_exists($k, $v)) {
                if (!is_bool($v[$k])) {
                    $this->err($p . '/' . $k, 'true or false.');
                } else {
                    $out[$k] = $v[$k];
                }
            }
        }
        if (array_key_exists('source', $v)) {
            if (!in_array($v['source'], ['photo', 'generated', 'composite'], true)) {
                $this->err($p . '/source', 'One of photo, generated, composite.');
            } else {
                $out['source'] = $v['source'];
            }
        }
        if (array_key_exists('c2pa', $v)) {
            if (!in_array($v['c2pa'], ['embedded', 'sidecar', 'none'], true)) {
                $this->err($p . '/c2pa', 'One of embedded, sidecar, none.');
            } else {
                $out['c2pa'] = $v['c2pa'];
            }
        }
        if (array_key_exists('c2pa_manifest_url', $v)) {
            $out['c2pa_manifest_url'] = $this->url($v, 'c2pa_manifest_url', $p);
        }
        if (array_key_exists('generator', $v)) {
            $out['generator'] = $this->str($v, 'generator', $p, 200);
        }
        ksort($out);
        return $out;
    }

    /** @return array<string, array{sha256: string, width: ?int, height: ?int}> */
    private function files(mixed $v, string $p): array
    {
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            $this->err($p, 'Must be an object keyed by rendition name.');
            return [];
        }
        $out = [];
        foreach ($v as $name => $ref) {
            $name = (string) $name;
            if (!in_array($name, self::RENDITIONS, true)) {
                $this->err($p . '/' . $name, 'Unknown rendition; use original, web, square or thumb.');
                continue;
            }
            $sha = is_array($ref) ? ($ref['sha256'] ?? null) : null;
            if (!is_string($sha) || !preg_match('/^[a-f0-9]{64}$/', $sha)) {
                $this->err($p . '/' . $name . '/sha256', 'A lowercase hex sha256.');
                continue;
            }
            $w = $ref['width'] ?? null;
            $h = $ref['height'] ?? null;
            if ($w !== null && (!is_int($w) || $w < 1) || $h !== null && (!is_int($h) || $h < 1)) {
                $this->err($p . '/' . $name, 'width and height are positive whole numbers.');
                continue;
            }
            $out[$name] = ['sha256' => $sha, 'width' => $w, 'height' => $h];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function object(mixed $data): array
    {
        if (!Json::isObject($data)) {
            throw new ApiError('validation_failed', 'The body must be a JSON object.', [['pointer' => '', 'detail' => 'Must be an object.']]);
        }
        return $data;
    }

    /**
     * RFC 7396 JSON merge patch.
     */
    public static function mergePatch(mixed $target, mixed $patch): mixed
    {
        if (!Json::isObject($patch)) {
            return $patch;
        }
        if (!Json::isObject($target)) {
            $target = [];
        }
        foreach ($patch as $k => $v) {
            if ($v === null) {
                unset($target[$k]);
            } else {
                $target[$k] = self::mergePatch($target[$k] ?? null, $v);
            }
        }
        return $target;
    }
}
