<?php

declare(strict_types=1);

namespace Obx\Node\Catalog;

/**
 * The article data of spec 0.3 (docs/protocol.md §5.9): vocabularies (the registries of this
 * file and the named terms of materials and care in resources/vocabularies), the 0.1 material
 * key normalisation, the label completeness warnings (import-format.md rule 10) and the cell
 * formats of the products.csv columns (import-format.md §1.6), shared by the Ingest API,
 * imports and the CSV export.
 */
final class ArticleData
{
    /** Feature registry (protocol.md §5.9.4): key => [type, allowed values], in registry order. */
    public const FEATURES = [
        'shell' => ['enum', ['hard', 'soft', 'hybrid']],
        'wheels' => ['integer', [0, 2, 4, 8]],
        'lock' => ['enum', ['none', 'combination', 'tsa_combination', 'tsa_key', 'key']],
        'expandable' => ['boolean', []],
        'closure' => ['enum', ['zip', 'magnet', 'flap', 'buckle', 'drawstring', 'button', 'hook_and_loop', 'laces', 'slip_on', 'open']],
        'laptop_compartment' => ['boolean', []],
        'water_resistance' => ['enum', ['none', 'water_repellent', 'waterproof']],
        'detachable_parts' => ['list', ['shoulder_strap', 'handle', 'lining', 'insole', 'hood', 'belt', 'pouch', 'wheels']],
        'adjustable_parts' => ['list', ['shoulder_strap', 'waist', 'hem', 'cuff']],
        'fit' => ['enum', ['slim', 'regular', 'relaxed', 'oversized']],
        'sleeve_length' => ['enum', ['sleeveless', 'short', 'three_quarter', 'long']],
        'neckline' => ['enum', ['crew', 'v_neck', 'collar', 'hood', 'turtleneck', 'boat', 'scoop']],
    ];

    /** Measurement keys in schema (and products.csv column) order. */
    public const MEASUREMENTS = ['height_cm', 'width_cm', 'depth_cm', 'weight_kg', 'volume_l', 'volume_expanded_l',
        'handle_drop_cm', 'strap_length_cm', 'laptop_max_inch', 'heel_height_cm', 'shaft_height_cm', 'inseam_cm'];

    public const FOOTWEAR_PARTS = ['upper', 'lining_and_sock', 'outer_sole'];
    public const FOOTWEAR_VALUES = ['leather', 'coated_leather', 'textile', 'other'];
    public const COLOR_FAMILIES = ['black', 'white', 'grey', 'beige', 'brown', 'red', 'pink', 'orange', 'yellow', 'green',
        'blue', 'purple', 'gold', 'silver', 'multicolour', 'transparent'];
    public const CERTIFICATION_SCOPES = ['product', 'material'];

    /** Label completeness warning codes (schemas/Common.json WarningCode). */
    public const WARNING_CODES = ['fibre_composition_missing', 'animal_origin_unknown', 'footwear_materials_missing', 'fibre_percentages_not_100'];

    /** Item types with the textile label warnings: the apparel keys, hat to tie, bag, backpack, luggage. */
    public const TEXTILE_ITEM_TYPES = ['t_shirt', 'top', 'shirt', 'blouse', 'polo', 'sweater', 'cardigan', 'hoodie', 'sweatshirt',
        'vest', 'jacket', 'coat', 'blazer', 'suit', 'dress', 'skirt', 'trousers', 'jeans', 'shorts', 'jumpsuit', 'leggings',
        'underwear', 'lingerie', 'nightwear', 'swimwear', 'socks', 'hat', 'cap', 'scarf', 'gloves', 'tie', 'bag', 'backpack', 'luggage'];
    public const FOOTWEAR_ITEM_TYPES = ['shoes', 'sneakers', 'boots', 'sandals'];
    /** Fibres that make animal_origin_unknown apply (and every key ending in _wool). */
    public const ANIMAL_FIBRES = ['leather', 'suede', 'fur', 'down', 'feather', 'wool', 'merino_wool', 'lambswool', 'cashmere', 'alpaca', 'mohair', 'silk'];

    public const KEY = '/^[a-z][a-z0-9_]{0,63}$/';

    /** The ISO 3758 symbol groups of a care entry (schemas/Care.json), in label order. */
    public const CARE_CATEGORIES = ['washing', 'bleaching', 'drying', 'ironing', 'professional_care'];
    /** GINETEX symbol notation of a care entry (schemas/Care.json). */
    public const CARE_SYMBOL = '/^[1-5]_[A-Za-z0-9_]{1,30}$/';

    /** @var array<string, array<string, array<string, mixed>>> vocabulary name => code => entry */
    private static array $vocabularies = [];

    /**
     * A vocabulary of the specification (resources/vocabularies/<name>.json, copied from the
     * spec's vocabularies/ by scripts/sync-spec.sh): code => entry with name (and for care
     * category and symbol).
     *
     * @param 'parts'|'materials'|'qualifiers'|'care' $name
     * @return array<string, array<string, mixed>>
     */
    public static function vocabulary(string $name): array
    {
        if (!isset(self::$vocabularies[$name])) {
            $file = dirname(__DIR__, 2) . '/resources/vocabularies/' . $name . '.json';
            $doc = json_decode((string) @file_get_contents($file), true);
            if (!is_array($doc) || !is_array($doc['entries'] ?? null)) {
                throw new \RuntimeException('The vocabulary ' . $file . ' is missing or unreadable.');
            }
            $out = [];
            foreach ($doc['entries'] as $e) {
                $out[(string) $e['code']] = $e;
            }
            self::$vocabularies[$name] = $out;
        }
        return self::$vocabularies[$name];
    }

    /**
     * A term {code, name} (protocol.md §5.9.1): for a code in the vocabulary its name there,
     * otherwise the name it was given (or null). Languages without a name are left out; an
     * object without any language is null. The vocabulary's language order is kept.
     *
     * @param 'parts'|'materials'|'qualifiers' $vocabulary
     * @param array<string, string>|null $given
     * @return array{code: string, name: array<string, string>|null}
     */
    public static function term(string $vocabulary, string $code, ?array $given = null): array
    {
        $known = self::vocabulary($vocabulary)[$code] ?? null;
        $name = $known !== null ? ($known['name'] ?? null) : $given;
        return ['code' => $code, 'name' => self::cleanName($name)];
    }

    /**
     * A care entry {code, category, symbol, name, text}: category, symbol and name of a code in
     * vocabularies/care.json, otherwise what was given.
     *
     * @param array<string, string>|null $name
     * @param array<string, string>|null $text
     * @return array{code: string, category: ?string, symbol: ?string, name: array<string, string>|null, text: array<string, string>|null}
     */
    public static function careEntry(string $code, ?string $category = null, ?string $symbol = null, ?array $name = null, ?array $text = null): array
    {
        $known = self::vocabulary('care')[$code] ?? null;
        if ($known !== null) {
            $category = $known['category'] ?? null;
            $symbol = $known['symbol'] ?? null;
            $name = $known['name'] ?? null;
        }
        return ['code' => $code, 'category' => $category, 'symbol' => $symbol, 'name' => self::cleanName($name), 'text' => $text];
    }

    /**
     * @param mixed $name
     * @return array<string, string>|null
     */
    private static function cleanName(mixed $name): ?array
    {
        if (!is_array($name)) {
            return null;
        }
        $out = [];
        foreach ($name as $lang => $text) {
            if (is_string($text) && trim($text) !== '') {
                $out[(string) $lang] = $text;
            }
        }
        return $out === [] ? null : $out;
    }

    /**
     * The 0.1 material keys recycled_<material> and organic_<material> are the material with
     * that qualifier (protocol.md §5.9.1); the key wins over a qualifier that was also given.
     * With several prefixes ("organic_recycled_cotton") the first one is the qualifier.
     *
     * @return array{0: string, 1: ?string} [material code, qualifier code from the key or null]
     */
    public static function splitMaterialKey(string $code): array
    {
        $qualifier = null;
        while (true) {
            if (str_starts_with($code, 'recycled_') && strlen($code) > 9) {
                $qualifier ??= 'recycled';
                $code = substr($code, 9);
            } elseif (str_starts_with($code, 'organic_') && strlen($code) > 8) {
                $qualifier ??= 'organic';
                $code = substr($code, 8);
            } else {
                return [$code, $qualifier];
            }
        }
    }

    /**
     * A stored fibre in the 0.3 shape {material, percentage, qualifier}, with the 0.1 material
     * keys normalised and the names of the vocabulary.
     *
     * @param array<string, mixed> $material {code, name?}
     * @param array<string, mixed>|null $qualifier {code, name?} or null
     * @return array{material: array{code: string, name: array<string, string>|null}, percentage: int|float, qualifier: array{code: string, name: array<string, string>|null}|null}
     */
    public static function fibre(array $material, int|float $percentage, ?array $qualifier): array
    {
        [$code, $fromKey] = self::splitMaterialKey((string) ($material['code'] ?? ''));
        if ($fromKey !== null && ($qualifier === null || $qualifier['code'] !== $fromKey)) {
            $qualifier = ['code' => $fromKey];
        }
        return [
            'material' => self::term('materials', $code, is_array($material['name'] ?? null) ? $material['name'] : null),
            'percentage' => self::number($percentage),
            'qualifier' => $qualifier === null ? null
                : self::term('qualifiers', (string) $qualifier['code'], is_array($qualifier['name'] ?? null) ? $qualifier['name'] : null),
        ];
    }

    /**
     * Materials as stored by 0.1 or 0.2 ({"part": "lining", "fibers": [{"fiber": "polyester",
     * "percentage": 100, "recycled": true}]}) in the 0.3 shape, for the upgrade of a database
     * (schema 5). Parts that are already 0.3 are left as they are; flags that are false are
     * dropped, and of recycled and organic both true, recycled is kept.
     *
     * @return mixed the materials, converted where they were 0.1/0.2
     */
    public static function materialsFromLegacy(mixed $materials): mixed
    {
        if (!is_array($materials) || !array_is_list($materials)) {
            return $materials;
        }
        $out = [];
        foreach ($materials as $part) {
            if (!is_array($part)) {
                $out[] = $part;
                continue;
            }
            if (is_string($part['part'] ?? null)) {
                $part['part'] = ['code' => $part['part']];
            }
            if (is_array($part['fibers'] ?? null)) {
                $fibers = [];
                foreach ($part['fibers'] as $f) {
                    if (is_array($f) && array_key_exists('fiber', $f) && !array_key_exists('material', $f)) {
                        $q = ($f['recycled'] ?? false) === true ? 'recycled' : (($f['organic'] ?? false) === true ? 'organic' : null);
                        $f = ['material' => ['code' => $f['fiber']], 'percentage' => $f['percentage'] ?? null,
                            'qualifier' => $q === null ? null : ['code' => $q]];
                    }
                    $fibers[] = $f;
                }
                $part['fibers'] = $fibers;
            }
            $out[] = $part;
        }
        return $out;
    }

    public static function isAnimalFibre(string $key): bool
    {
        return in_array($key, self::ANIMAL_FIBRES, true) || str_ends_with($key, '_wool');
    }

    /**
     * Label completeness warnings of a product as stored (import-format.md rule 10), in the
     * order fibre_composition_missing, animal_origin_unknown, footwear_materials_missing,
     * fibre_percentages_not_100 (once per part). They never block a write or publishing.
     *
     * @param array<string, mixed> $product the stored product write model
     * @return list<array{code: string, column: string, pointer: string, detail: string}>
     */
    public static function labelWarnings(array $product): array
    {
        $type = (string) ($product['item_type'] ?? '');
        $materials = is_array($product['materials'] ?? null) ? $product['materials'] : [];
        $out = [];
        if (in_array($type, self::TEXTILE_ITEM_TYPES, true)) {
            if ($materials === []) {
                $out[] = ['code' => 'fibre_composition_missing', 'column' => 'materials', 'pointer' => '/materials',
                    'detail' => 'The fibre composition is missing; it must be on the label of a textile product and shown before purchase (Regulation (EU) No 1007/2011).'];
            }
            if (($product['contains_animal_origin'] ?? null) === null) {
                foreach ($materials as $part) {
                    foreach ((array) ($part['fibers'] ?? []) as $f) {
                        $key = self::splitMaterialKey((string) ($f['material']['code'] ?? ''))[0];
                        if (self::isAnimalFibre($key)) {
                            $out[] = ['code' => 'animal_origin_unknown', 'column' => 'contains_animal_origin', 'pointer' => '/contains_animal_origin',
                                'detail' => 'The materials contain ' . $key . '; state whether the product contains non-textile parts of animal origin (Regulation (EU) No 1007/2011 Art. 12).'];
                            break 2;
                        }
                    }
                }
            }
            foreach ($materials as $i => $part) {
                $sum = 0.0;
                foreach ((array) ($part['fibers'] ?? []) as $f) {
                    $sum += (float) ($f['percentage'] ?? 0);
                }
                if (abs($sum - 100) > 0.01) {
                    $out[] = ['code' => 'fibre_percentages_not_100', 'column' => 'materials', 'pointer' => '/materials/' . $i,
                        'detail' => 'The percentages of ' . ($part['part']['code'] ?? 'main') . ' add up to ' . self::number($sum) . ', not 100.'];
                }
            }
        } elseif (in_array($type, self::FOOTWEAR_ITEM_TYPES, true)) {
            $fw = is_array($product['footwear_materials'] ?? null) ? $product['footwear_materials'] : [];
            $missing = array_values(array_filter(self::FOOTWEAR_PARTS, static fn ($p) => ($fw[$p] ?? null) === null));
            if ($missing !== []) {
                $out[] = ['code' => 'footwear_materials_missing', 'column' => 'footwear_materials', 'pointer' => '/footwear_materials',
                    'detail' => 'The footwear materials of ' . implode(', ', $missing) . ' are missing; they must be on the label of footwear (Directive 94/11/EC).'];
            }
        }
        return $out;
    }

    /** A whole number as int, otherwise the float (55.0 → 55). */
    public static function number(int|float $n): int|float
    {
        return is_float($n) && floor($n) === $n && abs($n) < 1e15 ? (int) $n : $n;
    }

    /** A cell key: lowercase, spaces and other separators as "_" ("Slip On" → slip_on). */
    public static function key(string $s): string
    {
        $s = strtolower(trim($s));
        $s = (string) preg_replace('/[^a-z0-9]+/', '_', $s);
        return trim($s, '_');
    }

    // ================================================================ products.csv cells (§1.6)

    /**
     * "upper: leather; lining_and_sock: textile; outer_sole: other"; a part not in the cell is null.
     *
     * @return array{upper: ?string, lining_and_sock: ?string, outer_sole: ?string}
     * @throws \InvalidArgumentException
     */
    public static function parseFootwear(string $cell): array
    {
        $out = ['upper' => null, 'lining_and_sock' => null, 'outer_sole' => null];
        $seen = 0;
        foreach (explode(';', $cell) as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            if (!str_contains($raw, ':')) {
                throw new \InvalidArgumentException('"' . $raw . '": write "<part>: <material>" with the parts upper, lining_and_sock and outer_sole.');
            }
            [$name, $value] = explode(':', $raw, 2);
            $part = self::key($name);
            $v = self::key($value);
            if (!in_array($part, self::FOOTWEAR_PARTS, true)) {
                throw new \InvalidArgumentException('"' . trim($name) . '" is not a part of a shoe (upper, lining_and_sock, outer_sole).');
            }
            if (!in_array($v, self::FOOTWEAR_VALUES, true)) {
                throw new \InvalidArgumentException('"' . trim($value) . '" is not a footwear material (leather, coated_leather, textile, other).');
            }
            if ($out[$part] !== null) {
                throw new \InvalidArgumentException('The part ' . $part . ' appears twice.');
            }
            $out[$part] = $v;
            $seen++;
        }
        if ($seen === 0) {
            throw new \InvalidArgumentException('No footwear materials found.');
        }
        return $out;
    }

    /** @param array<string, mixed>|null $fw */
    public static function formatFootwear(?array $fw): string
    {
        $parts = [];
        foreach (self::FOOTWEAR_PARTS as $p) {
            if (($fw[$p] ?? null) !== null) {
                $parts[] = $p . ': ' . $fw[$p];
            }
        }
        return implode('; ', $parts);
    }

    /**
     * "gots:CU 123456; rws:CU 200101 (material)": scheme lowercased, licence number after the
     * first ":" (null without), scope product unless "(material)". Entries keep their order.
     *
     * @return list<array{scheme: string, licence_number: ?string, scope: string}>
     * @throws \InvalidArgumentException
     */
    public static function parseCertifications(string $cell): array
    {
        $out = [];
        foreach (explode(';', $cell) as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $scope = 'product';
            if (preg_match('/\s*\((material|product)\)\s*$/i', $raw, $m)) {
                $scope = strtolower($m[1]);
                $raw = trim(substr($raw, 0, -strlen($m[0])));
            }
            $licence = null;
            $scheme = $raw;
            if (str_contains($raw, ':')) {
                [$scheme, $licence] = explode(':', $raw, 2);
                $licence = trim($licence);
                if ($licence === '') {
                    $licence = null;
                }
            }
            $key = self::key($scheme);
            if (!preg_match(self::KEY, $key)) {
                throw new \InvalidArgumentException('"' . trim($scheme) . '" is not a certification scheme key such as gots or oeko_tex_standard_100.');
            }
            if ($licence !== null && mb_strlen($licence, 'UTF-8') > 200) {
                throw new \InvalidArgumentException('The licence number of ' . $key . ' is longer than 200 characters.');
            }
            $out[] = ['scheme' => $key, 'licence_number' => $licence, 'scope' => $scope];
        }
        if ($out === []) {
            throw new \InvalidArgumentException('No certifications found.');
        }
        return $out;
    }

    /** @param list<array<string, mixed>>|null $certs */
    public static function formatCertifications(?array $certs): string
    {
        $parts = [];
        foreach ((array) $certs as $c) {
            $s = (string) $c['scheme'];
            if (($c['licence_number'] ?? null) !== null) {
                $s .= ':' . $c['licence_number'];
            }
            if (($c['scope'] ?? 'product') === 'material') {
                $s .= ' (material)';
            }
            $parts[] = $s;
        }
        return implode('; ', $parts);
    }

    /**
     * A positive number with "." or "," as the decimal separator, no thousands separator and
     * no unit ("2.6", "2,6").
     *
     * @throws \InvalidArgumentException
     */
    public static function parseMeasurement(string $cell): int|float
    {
        $s = trim($cell);
        if (!preg_match('/^([0-9]+([.,][0-9]+)?|[.,][0-9]+)$/', $s)) {
            throw new \InvalidArgumentException('"' . $s . '" is not a number; write for example 2.6 or 2,6, without a unit.');
        }
        $v = (float) str_replace(',', '.', $s);
        if (!($v > 0) || is_infinite($v)) {
            throw new \InvalidArgumentException('"' . $s . '" is not a positive number.');
        }
        return self::number($v);
    }

    /**
     * strap_length_cm: "min-max" (80-130) or one number for a strap that cannot be adjusted.
     *
     * @return array{min: int|float, max: int|float}
     * @throws \InvalidArgumentException
     */
    public static function parseStrapLength(string $cell): array
    {
        $s = trim($cell);
        if (!str_contains($s, '-')) {
            $v = self::parseMeasurement($s);
            return ['min' => $v, 'max' => $v];
        }
        [$lo, $hi] = explode('-', $s, 2);
        try {
            $min = self::parseMeasurement($lo);
            $max = self::parseMeasurement($hi);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException($e->getMessage() . ' Write the strap as min-max, for example 80-130.');
        }
        if ($min > $max) {
            throw new \InvalidArgumentException('"' . $s . '": the shortest setting comes first (min-max, for example 80-130).');
        }
        return ['min' => $min, 'max' => $max];
    }

    /** @param array<string, mixed>|null $m */
    public static function formatMeasurement(?array $m, string $key): string
    {
        $v = $m[$key] ?? null;
        if ($v === null) {
            return '';
        }
        if ($key === 'strap_length_cm') {
            if (!is_array($v)) {
                return '';
            }
            return $v['min'] == $v['max'] ? self::formatNumber($v['min']) : self::formatNumber($v['min']) . '-' . self::formatNumber($v['max']);
        }
        return is_int($v) || is_float($v) ? self::formatNumber($v) : '';
    }

    public static function formatNumber(int|float $n): string
    {
        if (is_int($n)) {
            return (string) $n;
        }
        $s = rtrim(rtrim(sprintf('%.10F', $n), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    /** @throws \InvalidArgumentException */
    public static function parseColorFamily(string $cell): string
    {
        $v = strtolower(trim($cell));
        $v = ['gray' => 'grey', 'multicolor' => 'multicolour'][$v] ?? $v;
        if (!in_array($v, self::COLOR_FAMILIES, true)) {
            throw new \InvalidArgumentException('"' . trim($cell) . '" is not a colour family (' . implode(', ', self::COLOR_FAMILIES) . ').');
        }
        return $v;
    }

    /**
     * The cell of a feature:<key> column. A registry key gets its registry type (enums
     * lowercased with spaces as "_", integers as digits, booleans as yes/no, lists separated by
     * "|" without duplicates); a value outside the registry is an error. Any other key is kept
     * as the text of the cell.
     *
     * @return string|int|bool|list<string>
     * @throws \InvalidArgumentException
     */
    public static function parseFeature(string $key, string $cell): string|int|bool|array
    {
        $s = trim($cell);
        $spec = self::FEATURES[$key] ?? null;
        if ($spec === null) {
            return $s;
        }
        [$type, $values] = $spec;
        $allowed = implode(', ', array_map('strval', $values));
        switch ($type) {
            case 'enum':
                $v = self::key($s);
                if (!in_array($v, $values, true)) {
                    throw new \InvalidArgumentException('"' . $s . '" is not one of ' . $allowed . '.');
                }
                return $v;
            case 'integer':
                if (!preg_match('/^[0-9]{1,9}$/', $s) || !in_array((int) $s, $values, true)) {
                    throw new \InvalidArgumentException('"' . $s . '" is not one of ' . $allowed . '.');
                }
                return (int) $s;
            case 'boolean':
                $b = \Obx\Node\Import\Formats::bool($s);
                if ($b === null) {
                    throw new \InvalidArgumentException('"' . $s . '" is not yes or no.');
                }
                return $b;
            default:
                $list = [];
                foreach (explode('|', $s) as $raw) {
                    $v = self::key($raw);
                    if ($v === '') {
                        continue;
                    }
                    if (!in_array($v, $values, true)) {
                        throw new \InvalidArgumentException('"' . trim($raw) . '" is not one of ' . $allowed . '.');
                    }
                    if (!in_array($v, $list, true)) {
                        $list[] = $v;
                    }
                }
                if ($list === []) {
                    throw new \InvalidArgumentException('"' . $s . '" is not a list of ' . $allowed . '.');
                }
                return $list;
        }
    }

    public static function formatFeature(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'yes' : 'no',
            is_array($v) => implode('|', array_map([self::class, 'formatFeature'], $v)),
            is_int($v) || is_float($v) => self::formatNumber($v),
            default => (string) $v,
        };
    }

    /**
     * Features in output order: registry keys in registry order, then the other keys sorted.
     *
     * @param array<string, mixed> $features
     * @return array<string, mixed>
     */
    public static function orderFeatures(array $features): array
    {
        $out = [];
        foreach (array_keys(self::FEATURES) as $k) {
            if (array_key_exists($k, $features)) {
                $out[$k] = $features[$k];
            }
        }
        $other = array_diff_key($features, self::FEATURES);
        ksort($other, SORT_STRING);
        return $out + $other;
    }

    /**
     * Measurements in schema order.
     *
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    public static function orderMeasurements(array $m): array
    {
        $out = [];
        foreach (self::MEASUREMENTS as $k) {
            if (array_key_exists($k, $m)) {
                $out[$k] = $m[$k];
            }
        }
        return $out;
    }

    /**
     * The materials grammar with the qualifier: "outer: 100% polyester (recycled)".
     *
     * @param list<array<string, mixed>>|null $materials
     */
    public static function formatMaterials(?array $materials): string
    {
        if ($materials === null) {
            return '';
        }
        $parts = [];
        foreach ($materials as $m) {
            $fibers = [];
            foreach ((array) $m['fibers'] as $f) {
                $s = self::formatNumber($f['percentage']) . '% ' . $f['material']['code'];
                if (($f['qualifier'] ?? null) !== null) {
                    $s .= ' (' . $f['qualifier']['code'] . ')';
                }
                $fibers[] = $s;
            }
            $parts[] = $m['part']['code'] . ': ' . implode(', ', $fibers);
        }
        return implode('; ', $parts);
    }
}
