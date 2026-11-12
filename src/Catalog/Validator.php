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
        $known = ['brand_external_id', 'style_number', 'name', 'description', 'item_type', 'item_subtype', 'gpc_brick', 'target_group',
            'season', 'collection', 'materials', 'contains_animal_origin', 'footwear_materials', 'care', 'country_of_origin',
            'certifications', 'dpp_url', 'features', 'attributes', 'status'];
        $this->unknown($d, $known);
        $key = '/^[a-z][a-z0-9_]{0,63}$/';
        // The canonical stored model (key order = protocol order, docs/protocol.md §5.2).
        $model = [
            'brand_external_id' => $this->str($d, 'brand_external_id', '', 200, false, '/^[^\x00-\x1F\x7F\/]+$/'),
            'style_number' => $this->str($d, 'style_number', '', 200, true),
            'name' => $this->localized($d['name'] ?? null, '/name', true),
            'description' => $this->localized($d['description'] ?? null, '/description'),
            'item_type' => $this->str($d, 'item_type', '', 64, false, $key),
            'item_subtype' => $this->str($d, 'item_subtype', '', 64, false, $key),
            'gpc_brick' => $this->str($d, 'gpc_brick', '', 8, false, '/^[0-9]{8}$/'),
            'target_group' => $this->str($d, 'target_group', '', 64, false, $key),
            'season' => $this->str($d, 'season', '', 64),
            'collection' => $this->str($d, 'collection', '', 200),
            'materials' => $this->materials($d['materials'] ?? null, '/materials'),
            'contains_animal_origin' => $this->nullableBool($d, 'contains_animal_origin'),
            'footwear_materials' => $this->footwear($d['footwear_materials'] ?? null, '/footwear_materials'),
            'care' => $this->care($d['care'] ?? null, '/care'),
            'country_of_origin' => $this->str($d, 'country_of_origin', '', 2, false, '/^[A-Z]{2}$/'),
            'certifications' => $this->certifications($d['certifications'] ?? null, '/certifications'),
            'dpp_url' => $this->url($d, 'dpp_url', ''),
            'features' => $this->features($d['features'] ?? null, '/features'),
            'attributes' => $this->attributes($d['attributes'] ?? null, '/attributes'),
        ];
        $status = $this->status($d, '');
        $this->finish();
        return [$model, $status];
    }

    /**
     * Materials (protocol.md §5.9.1): parts with fibres, part, material and qualifier as terms
     * {code, name}. The name may be left out; a code in the vocabulary gets the vocabulary's
     * name, another code the name it was given (or null). The 0.1 material keys
     * recycled_<material> / organic_<material> become the material with that qualifier.
     *
     * @return list<array<string, mixed>>|null
     */
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
            if (!Json::isObject($part)) {
                $this->err($p . '/' . $i, 'Must be an object.');
                continue;
            }
            $this->unknown($part, ['part', 'fibers'], 'materials.');
            $term = $this->term($part['part'] ?? null, $p . '/' . $i . '/part', 'materials.part.', 'Part codes are lowercase, e.g. {"code": "main"} or {"code": "lining"}.');
            $fibers = $part['fibers'] ?? null;
            if (!is_array($fibers) || $fibers === [] || !array_is_list($fibers)) {
                $this->err($p . '/' . $i . '/fibers', 'At least one fibre is required.');
                continue;
            }
            $fo = [];
            foreach ($fibers as $j => $f) {
                $fp = $p . '/' . $i . '/fibers/' . $j;
                if (!Json::isObject($f)) {
                    $this->err($fp, 'Must be an object with material, percentage and qualifier.');
                    continue;
                }
                $this->unknown($f, ['material', 'percentage', 'qualifier'], 'materials.fibers.');
                $material = $this->term($f['material'] ?? null, $fp . '/material', 'materials.fibers.material.', 'Material codes are lowercase, e.g. {"code": "cotton"}.');
                $qualifier = ($f['qualifier'] ?? null) === null ? null
                    : $this->term($f['qualifier'], $fp . '/qualifier', 'materials.fibers.qualifier.', 'A qualifier is null or a lowercase code, e.g. {"code": "recycled"}.');
                $pct = $f['percentage'] ?? null;
                if (!is_int($pct) && !is_float($pct) || $pct <= 0 || $pct > 100) {
                    $this->err($fp . '/percentage', 'A number above 0 and at most 100.');
                    continue;
                }
                if ($material === null || ($f['qualifier'] ?? null) !== null && $qualifier === null) {
                    continue;
                }
                $fo[] = ArticleData::fibre($material, $pct, $qualifier);
            }
            if ($term !== null) {
                $out[] = ['part' => ArticleData::term('parts', $term['code'], $term['name']), 'fibers' => $fo];
            }
        }
        return $out;
    }

    /**
     * A term on a write path: {code, name?}; name is a LocalizedText or null.
     *
     * @return array{code: string, name: array<string, string>|null}|null null when invalid
     */
    private function term(mixed $v, string $p, string $base, string $hint): ?array
    {
        if (!Json::isObject($v) || !is_string($v['code'] ?? null) || !preg_match(ArticleData::KEY, $v['code'])) {
            $this->err(Json::isObject($v) ? $p . '/code' : $p, $hint);
            return null;
        }
        $this->unknown($v, ['code', 'name'], $base);
        $errors = count($this->errors);
        $name = $this->localized($v['name'] ?? null, $p . '/name');
        return count($this->errors) > $errors ? null : ['code' => $v['code'], 'name' => $name];
    }

    public static function number(int|float $n): int|float
    {
        return is_float($n) && floor($n) === $n && abs($n) < 1e15 ? (int) $n : $n;
    }

    /** @param array<string, mixed> $d */
    private function nullableBool(array $d, string $k, string $p = ''): ?bool
    {
        $v = $d[$k] ?? null;
        if ($v !== null && !is_bool($v)) {
            $this->err($p . '/' . $k, 'true, false or null.');
            return null;
        }
        return $v;
    }

    /**
     * Footwear materials (Directive 94/11/EC): all three parts, null when not stated; an object
     * with no part stated is stored as null.
     *
     * @return array{upper: ?string, lining_and_sock: ?string, outer_sole: ?string}|null
     */
    private function footwear(mixed $v, string $p): ?array
    {
        if ($v === null) {
            return null;
        }
        if (!Json::isObject($v)) {
            $this->err($p, 'An object with upper, lining_and_sock and outer_sole.');
            return null;
        }
        $this->unknown($v, ArticleData::FOOTWEAR_PARTS, 'footwear_materials.');
        $out = [];
        foreach (ArticleData::FOOTWEAR_PARTS as $part) {
            $val = $v[$part] ?? null;
            if ($val !== null && (!is_string($val) || !in_array($val, ArticleData::FOOTWEAR_VALUES, true))) {
                $this->err($p . '/' . $part, 'One of ' . implode(', ', ArticleData::FOOTWEAR_VALUES) . ' or null.');
                $val = null;
            }
            $out[$part] = $val;
        }
        return array_filter($out, static fn ($x) => $x !== null) === [] ? null : $out;
    }

    /** @return list<array{scheme: string, licence_number: ?string, scope: string}>|null an empty list is null */
    private function certifications(mixed $v, string $p): ?array
    {
        if ($v === null) {
            return null;
        }
        if (!is_array($v) || !array_is_list($v)) {
            $this->err($p, 'A list of certifications.');
            return null;
        }
        $out = [];
        foreach ($v as $i => $c) {
            if (!Json::isObject($c) || $c === []) {
                $this->err($p . '/' . $i, 'An object with scheme, licence_number and scope.');
                continue;
            }
            $this->unknown($c, ['scheme', 'licence_number', 'scope'], 'certifications.');
            $scheme = $c['scheme'] ?? null;
            if (!is_string($scheme) || !preg_match(ArticleData::KEY, $scheme)) {
                $this->err($p . '/' . $i . '/scheme', 'A scheme key such as gots or oeko_tex_standard_100.');
                continue;
            }
            $licence = $this->str($c, 'licence_number', $p . '/' . $i, 200);
            $scope = $c['scope'] ?? null;
            if (!is_string($scope) || !in_array($scope, ArticleData::CERTIFICATION_SCOPES, true)) {
                $this->err($p . '/' . $i . '/scope', 'Either "product" or "material".');
                continue;
            }
            $out[] = ['scheme' => $scheme, 'licence_number' => $licence, 'scope' => $scope];
        }
        return $out === [] ? null : $out;
    }

    /**
     * Typed features (protocol.md §5.9.4): registry keys must have their type and values; other
     * keys are kept as sent (a string, number, boolean or a list of those). An empty object is null.
     *
     * @return array<string, mixed>|null
     */
    private function features(mixed $v, string $p): ?array
    {
        if ($v === null) {
            return null;
        }
        if (!Json::isObject($v)) {
            $this->err($p, 'An object of features, e.g. {"wheels": 4}.');
            return null;
        }
        $out = [];
        foreach ($v as $k => $val) {
            $k = (string) $k;
            $ptr = $p . '/' . $k;
            if (!preg_match(ArticleData::KEY, $k)) {
                $this->err($ptr, 'Feature keys are lower_snake_case.');
                continue;
            }
            if ($val === null) {
                continue;
            }
            $spec = ArticleData::FEATURES[$k] ?? null;
            if ($spec === null) {
                $scalar = static fn ($x) => is_string($x) || is_int($x) || is_float($x) || is_bool($x);
                if ($scalar($val) || is_array($val) && array_is_list($val) && array_filter($val, static fn ($x) => !$scalar($x)) === []) {
                    $out[$k] = $val;
                } else {
                    $this->err($ptr, 'A string, number, boolean or a list of those.');
                }
                continue;
            }
            [$type, $values] = $spec;
            $allowed = implode(', ', array_map('strval', $values));
            switch ($type) {
                case 'enum':
                    if (!is_string($val) || !in_array($val, $values, true)) {
                        $this->err($ptr, 'One of ' . $allowed . '.');
                        continue 2;
                    }
                    break;
                case 'integer':
                    if (is_float($val) && floor($val) === $val) {
                        $val = (int) $val;
                    }
                    if (!is_int($val) || !in_array($val, $values, true)) {
                        $this->err($ptr, 'One of ' . $allowed . '.');
                        continue 2;
                    }
                    break;
                case 'boolean':
                    if (!is_bool($val)) {
                        $this->err($ptr, 'true or false.');
                        continue 2;
                    }
                    break;
                default:
                    if (!is_array($val) || !array_is_list($val) || array_diff(array_map(static fn ($x) => is_string($x) ? $x : "\0", $val), $values) !== []) {
                        $this->err($ptr, 'A list of ' . $allowed . '.');
                        continue 2;
                    }
                    if (count(array_unique($val)) !== count($val)) {
                        $this->err($ptr, 'A list without duplicates.');
                        continue 2;
                    }
                    if ($val === []) {
                        continue 2;
                    }
            }
            $out[$k] = $val;
        }
        return $out === [] ? null : ArticleData::orderFeatures($out);
    }

    /**
     * Measurements of a variant (protocol.md §5.9.3): positive numbers, strap_length_cm as
     * {min, max}. An empty object is null.
     *
     * @return array<string, mixed>|null
     */
    private function measurements(mixed $v, string $p): ?array
    {
        if ($v === null) {
            return null;
        }
        if (!Json::isObject($v)) {
            $this->err($p, 'An object of measurements, e.g. {"weight_kg": 2.6}.');
            return null;
        }
        $this->unknown($v, ArticleData::MEASUREMENTS, 'measurements.');
        $positive = static fn ($x) => (is_int($x) || is_float($x)) && $x > 0 && !is_infinite((float) $x);
        $out = [];
        foreach (ArticleData::MEASUREMENTS as $k) {
            $val = $v[$k] ?? null;
            if ($val === null) {
                continue;
            }
            if ($k === 'strap_length_cm') {
                if (!Json::isObject($val) || !$positive($val['min'] ?? null) || !$positive($val['max'] ?? null)) {
                    $this->err($p . '/' . $k, 'An object {"min": 80, "max": 130} with positive numbers.');
                    continue;
                }
                if ($val['min'] > $val['max']) {
                    $this->err($p . '/' . $k, 'min is larger than max.');
                    continue;
                }
                $out[$k] = ['min' => self::number($val['min']), 'max' => self::number($val['max'])];
                continue;
            }
            if (!$positive($val)) {
                $this->err($p . '/' . $k, 'A positive number (' . (str_ends_with($k, '_kg') ? 'kg' : (str_ends_with($k, '_l') ? 'litres' : (str_ends_with($k, '_inch') ? 'inch' : 'cm'))) . ').');
                continue;
            }
            $out[$k] = self::number($val);
        }
        return $out === [] ? null : $out;
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
            $ip = $p . '/' . $i;
            $code = Json::isObject($item) ? ($item['code'] ?? null) : null;
            if (!is_string($code) || !preg_match(ArticleData::KEY, $code)) {
                $this->err($ip . '/code', 'Care codes are lowercase keys, e.g. "wash_30".');
                continue;
            }
            $this->unknown($item, ['code', 'category', 'symbol', 'name', 'text'], 'care.');
            $errors = count($this->errors);
            $category = $item['category'] ?? null;
            if ($category !== null && !in_array($category, ArticleData::CARE_CATEGORIES, true)) {
                $this->err($ip . '/category', 'One of ' . implode(', ', ArticleData::CARE_CATEGORIES) . ' or null.');
            }
            $symbol = $item['symbol'] ?? null;
            if ($symbol !== null && (!is_string($symbol) || !preg_match(ArticleData::CARE_SYMBOL, $symbol))) {
                $this->err($ip . '/symbol', 'A symbol code such as 1_C_40, or null.');
            }
            $name = $this->localized($item['name'] ?? null, $ip . '/name');
            $text = $this->localized($item['text'] ?? null, $ip . '/text');
            if (count($this->errors) > $errors) {
                continue;
            }
            $out[] = ArticleData::careEntry($code, $category, $symbol, $name, $text);
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
        $this->unknown($d, ['product_external_id', 'gtin', 'sku', 'color', 'size', 'size_length', 'size_system', 'measurements', 'status']);
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
            $this->unknown($color, ['code', 'name', 'supplier_color', 'family'], 'color.');
            $family = $color['family'] ?? null;
            if ($family !== null && (!is_string($family) || !in_array($family, ArticleData::COLOR_FAMILIES, true))) {
                $this->err('/color/family', 'One of ' . implode(', ', ArticleData::COLOR_FAMILIES) . ' or null.');
                $family = null;
            }
            $colorOut = [
                'code' => $this->str($color, 'code', '/color', 64, true),
                'name' => $this->localized($color['name'] ?? null, '/color/name'),
                'supplier_color' => $this->str($color, 'supplier_color', '/color', 200),
                'family' => $family,
            ];
        }
        $model = [
            'product_external_id' => $this->str($d, 'product_external_id', '', 200, true, '/^[^\x00-\x1F\x7F\/]+$/'),
            'gtin' => $gtin,
            'sku' => $this->str($d, 'sku', '', 200),
            'color' => $colorOut,
            'size' => $this->str($d, 'size', '', 64),
            'size_length' => $this->str($d, 'size_length', '', 64),
            'size_system' => $this->str($d, 'size_system', '', 16),
            'measurements' => $this->measurements($d['measurements'] ?? null, '/measurements'),
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
