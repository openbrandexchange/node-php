<?php

declare(strict_types=1);

namespace Obx\Node\Import;

use Obx\Node\App;
use Obx\Node\Catalog\ArticleData;
use Obx\Node\Catalog\Validator;
use Obx\Node\Http\ApiError;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Gtin;
use Obx\Node\Support\Json;
use Obx\Node\Support\Str;

/**
 * Parses and applies one canonical import (docs/import-format.md): products.csv, assets.csv
 * and images (files, zips or an images manifest), with ingest semantics per object.
 */
final class ImportEngine
{
    private const PRODUCT_COLS = ['brand', 'product_id', 'style_number', 'name', 'description', 'item_type', 'item_subtype', 'target_group',
        'season', 'collection', 'gpc_brick', 'materials', 'contains_animal_origin', 'footwear_materials', 'care', 'country_of_origin',
        'certifications', 'dpp_url', 'status'];
    private const VARIANT_COLS = ['variant_id', 'gtin', 'sku', 'color_code', 'color_name', 'color_family', 'supplier_color', 'size',
        'size_length', 'size_system', 'variant_status',
        'height_cm', 'width_cm', 'depth_cm', 'weight_kg', 'volume_l', 'volume_expanded_l',
        'handle_drop_cm', 'strap_length_cm', 'laptop_max_inch', 'heel_height_cm', 'shaft_height_cm', 'inseam_cm'];
    /** Variant columns that only make sense on a row with a variant (import-format.md §1.2). */
    private const VARIANT_ONLY = ['color_family', 'size_length', 'height_cm', 'width_cm', 'depth_cm', 'weight_kg', 'volume_l',
        'volume_expanded_l', 'handle_drop_cm', 'strap_length_cm', 'laptop_max_inch', 'heel_height_cm', 'shaft_height_cm', 'inseam_cm'];
    private const ASSET_COLS = ['file', 'asset_id', 'product_id', 'style_number', 'color_code', 'position', 'role', 'view', 'alt_text',
        'license', 'license_text', 'channels', 'territories', 'embargo_until', 'expires_at', 'credit', 'model_release',
        'ai_generated', 'ai_edited', 'source', 'c2pa', 'c2pa_manifest_url', 'generator', 'status'];

    /** @var list<array<string, mixed>> ImportRowError */
    public array $errors = [];
    /** @var array<string, int> */
    private array $counts;
    private string $lang;
    /** @var array<string, array{ext: string, name: string}> lower(name|ext) => brand */
    private array $brands = [];
    private int $maxBytes;

    /**
     * @param array<string, mixed> $options
     * @param list<string>|null $restriction
     */
    public function __construct(private App $app, private array $options, private ?array $restriction, private ?string $jobId = null)
    {
        $this->counts = Importer::zeroCounts();
        $this->lang = (string) ($options['default_language'] ?? $app->settings->defaultLanguage());
        $this->maxBytes = max(26214400, (int) $app->config->get('max_upload_bytes'));
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $c = $this->counts;
        $c['errors'] = count(array_filter($this->errors, static fn ($e) => $e['severity'] === 'error'));
        $c['warnings'] = count(array_filter($this->errors, static fn ($e) => $e['severity'] === 'warning'));
        return $c;
    }

    private function issue(string $file, ?int $row, ?string $column, string $severity, string $code, string $detail, ?string $value = null): void
    {
        // A workbook's rows are imported as products.csv / assets.csv; their errors name the
        // workbook and start with the cell's locator (import-format.md §5.5).
        $role = $file === 'products.csv' ? 'products' : ($file === 'assets.csv' ? 'assets' : null);
        if ($role !== null && isset($this->workbooks[$role])) {
            $file = $role . '.xlsx';
            $detail = $this->locator($role, $row, $column) . $detail;
        }
        $this->errors[] = [
            'file' => mb_substr($file, 0, 255, 'UTF-8'),
            'row' => $row,
            'column' => $column,
            'severity' => $severity,
            'obx_error' => $code,
            'detail' => mb_substr($detail, 0, 2000, 'UTF-8'),
            'value' => $value !== null ? mb_substr($value, 0, 500, 'UTF-8') : null,
        ];
    }

    /** @var array<string, array{raw: list<string>, headers: list<string>}> role => header of the workbook read as that file */
    private array $workbooks = [];

    /** The §5.5 locator for an error about a workbook's row and/or column. */
    private function locator(string $role, ?int $row, ?string $column): string
    {
        $wb = $this->workbooks[$role];
        $index = null;
        if ($column !== null && $column !== '') {
            foreach ([$wb['raw'], $wb['headers']] as $names) {
                $i = array_search($column, $names, true);
                if ($i !== false) {
                    $index = (int) $i;
                    break;
                }
            }
            if ($index === null) {
                foreach ($wb['headers'] as $i => $h) {
                    if (explode(':', $h, 2)[0] === $column) {
                        $index = $i;
                        break;
                    }
                }
            }
        }
        return Xlsx::locator($row, $index !== null ? $wb['raw'][$index] : null, $index !== null ? $index + 1 : null);
    }

    /**
     * Reads products.csv / assets.csv, or the workbook that takes its place: converted to rows
     * (import-format.md §5) and then read with the CSV rules. A workbook that cannot be imported
     * fails the run (§5.5), as an unreadable CSV does: its one ImportRowError (row and column
     * null) is the run's only one, and nothing of the import is written.
     *
     * @param array{path: string, xlsx: bool}|null $source
     * @return array{headers: list<string>, raw_headers: list<string>, rows: list<array{row: int, values: array<string, string>}>}|null
     */
    private function readTable(?array $source, string $role): ?array
    {
        if ($source === null) {
            return null;
        }
        if (!$source['xlsx']) {
            return Csv::read($source['path']);
        }
        $file = $role . '.xlsx';
        if (!Xlsx::available()) {
            $this->refuse($file, 'unsupported_media_type', 'This server cannot read Excel workbooks (the PHP zlib or xmlreader extension is missing). Save the sheet as CSV UTF-8 and import that.');
        }
        if ((int) @filesize($source['path']) > $this->maxBytes) {
            $this->refuse($file, 'payload_too_large', 'The workbook is larger than the limit for one CSV file (' . $this->maxBytes . ' bytes).');
        }
        try {
            $wb = Xlsx::convert($source['path'], $role);
        } catch (WorkbookError $e) {
            $this->refuse($file, $e->obxError, ucfirst($e->getMessage()) . '.');
        }
        foreach ([...$wb['errors'], ...$wb['warnings']] as $e) {
            $this->errors[] = $e;
        }
        if ($wb['header'] === []) {
            throw new ApiError('import_invalid', $file . ': row 1 of the sheet "' . $wb['sheet'] . '" is empty; a header row is required.');
        }
        $table = Csv::table($wb['header'], $wb['records']);
        $this->workbooks[$role] = ['raw' => array_map([Xlsx::class, 'trimHeader'], $wb['header']), 'headers' => $table['headers']];
        return $table;
    }

    /**
     * Refuses a workbook (§5.5): the run fails with a problem of that code, and its results hold
     * exactly this one ImportRowError (what other files of the import gave so far is dropped:
     * nothing of the import is imported).
     */
    private function refuse(string $file, string $code, string $detail): never
    {
        $this->errors = [];
        $this->issue($file, null, null, 'error', $code, $detail);
        throw new ApiError($code, $file . ': ' . $detail);
    }

    // ================================================================ entry

    /** Runs the import from a staging directory ("input/" inside). */
    public function run(string $staging): void
    {
        $in = $staging . '/input';
        $work = $staging . '/work';
        @mkdir($work, 0770, true);
        [$productsSource, $assetsSource, $images] = $this->collect($in, $work);
        $manifest = is_file($in . '/manifest.json') ? (array) Json::decode((string) file_get_contents($in . '/manifest.json'), []) : [];

        $products = $this->readTable($productsSource, 'products');
        $assetsRows = $this->readTable($assetsSource, 'assets');
        $this->counts['rows'] = $products !== null ? count($products['rows']) : 0;

        $plan = $products !== null ? $this->planProducts($products) : [];
        $imageEntries = $this->imageEntries($images, $manifest);
        $this->counts['files'] = count($imageEntries);
        $assetPlan = $this->planAssets($plan, $imageEntries, $assetsRows);

        if (($this->options['mode'] ?? 'upsert') === 'mirror') {
            $this->checkMirror($plan);
        }

        $apply = function () use ($plan, $assetPlan): void {
            $this->apply($plan, $assetPlan);
        };
        if (!empty($this->options['dry_run'])) {
            $this->app->writer->noRender = true;
            try {
                $this->app->db->tx(function () use ($apply) {
                    $apply();
                    throw new DryRunRollback();
                });
            } catch (DryRunRollback) {
            } finally {
                $this->app->writer->noRender = false;
            }
        } else {
            $apply();
        }
    }

    /**
     * Finds products.csv, assets.csv (or the workbooks products.xlsx, assets.xlsx that take their
     * place, import-format.md §5.1) and images among the staged inputs.
     *
     * @return array{0: ?array{path: string, xlsx: bool}, 1: ?array{path: string, xlsx: bool}, 2: list<array{name: string, path: string}>}
     */
    private function collect(string $in, string $work): array
    {
        $direct = static function (string $role) use ($in): ?array {
            if (is_file($in . '/' . $role . '.csv')) {
                return ['path' => $in . '/' . $role . '.csv', 'xlsx' => false];
            }
            if (is_file($in . '/' . $role . '.xlsx')) {
                return ['path' => $in . '/' . $role . '.xlsx', 'xlsx' => true];
            }
            return null;
        };
        $productsSrc = $direct('products');
        $assetsSrc = $direct('assets');
        $images = [];
        $maxTotal = $this->maxBytes * 10;

        $fromEntries = function (array $entries, bool $csvAllowed) use (&$productsSrc, &$assetsSrc, &$images): void {
            $csvCandidates = [];
            $workbooks = [];
            foreach ($entries as $e) {
                $base = basename($e['name']);
                $lower = strtolower($base);
                if (str_ends_with($lower, '.csv')) {
                    if ($csvAllowed) {
                        $csvCandidates[] = $e;
                    }
                    continue;
                }
                if ($lower === 'products.xlsx' || $lower === 'assets.xlsx') {
                    if ($csvAllowed) {
                        $workbooks[] = $e;
                    }
                    continue;
                }
                if (Formats::isImageName($base)) {
                    $images[] = ['name' => $base, 'path' => $e['path']];
                }
            }
            if (!$csvAllowed || ($csvCandidates === [] && $workbooks === [])) {
                return;
            }
            $order = static fn ($a, $b) => substr_count($a['name'], '/') <=> substr_count($b['name'], '/') ?: strcmp($a['name'], $b['name']);
            usort($csvCandidates, $order);
            usort($workbooks, $order);
            $has = static function (array $list, string $file): ?array {
                foreach ($list as $c) {
                    if (strtolower(basename($c['name'])) === $file) {
                        return $c;
                    }
                }
                return null;
            };
            foreach (['products', 'assets'] as $role) {
                if ($has($csvCandidates, $role . '.csv') !== null && $has($workbooks, $role . '.xlsx') !== null) {
                    throw new ApiError('import_invalid', 'The import has both ' . $role . '.csv and ' . $role . '.xlsx; keep one of them.');
                }
            }
            $others = [];
            foreach ($csvCandidates as $c) {
                $lower = strtolower(basename($c['name']));
                if ($lower === 'products.csv' && $productsSrc === null) {
                    $productsSrc = ['path' => $c['path'], 'xlsx' => false];
                } elseif ($lower === 'assets.csv' && $assetsSrc === null) {
                    $assetsSrc = ['path' => $c['path'], 'xlsx' => false];
                } elseif ($lower !== 'products.csv' && $lower !== 'assets.csv') {
                    $others[] = $c;
                }
            }
            $productsWorkbook = $has($workbooks, 'products.xlsx');
            $assetsWorkbook = $has($workbooks, 'assets.xlsx');
            if ($productsWorkbook !== null && $productsSrc === null) {
                $productsSrc = ['path' => $productsWorkbook['path'], 'xlsx' => true];
            }
            if ($assetsWorkbook !== null && $assetsSrc === null) {
                $assetsSrc = ['path' => $assetsWorkbook['path'], 'xlsx' => true];
            }
            // The "only *.csv" fallback of §3; it does not extend to workbooks.
            if ($productsSrc === null && count($others) === 1) {
                $productsSrc = ['path' => $others[0]['path'], 'xlsx' => false];
            }
        };

        if (is_file($in . '/package.zip')) {
            $entries = SafeZip::entries($in . '/package.zip', $maxTotal);
            $wanted = array_filter($entries, static fn ($n) => str_ends_with(strtolower($n), '.csv') || in_array(strtolower(basename($n)), ['products.xlsx', 'assets.xlsx'], true) || Formats::isImageName(basename($n)));
            $fromEntries(SafeZip::extractMany($in . '/package.zip', $wanted, $work . '/package', $this->maxBytes), true);
        }
        if (is_dir($in . '/tree')) {
            $entries = [];
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($in . '/tree', \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && !$f->isLink()) {
                    $rel = substr($f->getPathname(), strlen($in . '/tree/'));
                    if (!SafeZip::ignored($rel)) {
                        $entries[] = ['name' => $rel, 'path' => $f->getPathname()];
                    }
                }
            }
            $fromEntries($entries, true);
        }
        $direct = is_file($in . '/images.json') ? (array) Json::decode((string) file_get_contents($in . '/images.json'), []) : [];
        foreach ($direct as $i => $d) {
            $path = $in . '/' . $d['path'];
            $head = (string) @file_get_contents($path, false, null, 0, 4);
            if ($head === "PK\x03\x04") {
                $entries = SafeZip::entries($path, $maxTotal);
                $wanted = array_filter($entries, static fn ($n) => Formats::isImageName(basename($n)));
                $fromEntries(SafeZip::extractMany($path, $wanted, $work . '/images' . $i, $this->maxBytes), false);
            } else {
                $images[] = ['name' => basename((string) $d['name']), 'path' => $path];
            }
        }
        return [$productsSrc, $assetsSrc, $images];
    }

    // ================================================================ products

    /**
     * @param array{headers: list<string>, raw_headers: list<string>, rows: list<array{row: int, values: array<string, string>}>} $csv
     * @return list<array<string, mixed>> product plans in file order
     */
    private function planProducts(array $csv): array
    {
        $file = 'products.csv';
        foreach ($csv['headers'] as $i => $h) {
            if (str_starts_with($h, 'feature:')) {
                $key = substr($h, 8);
                if (!preg_match(ArticleData::KEY, $key)) {
                    $this->ignoredColumns[$h] = true;
                    $this->issue($file, null, $csv['raw_headers'][$i], 'warning', 'validation_failed', 'The feature key "' . $key . '" must be lowercase letters, digits and _; the column was ignored.');
                } elseif (!isset(ArticleData::FEATURES[$key])) {
                    $this->issue($file, null, $h, 'warning', 'validation_failed', 'The feature "' . $key . '" is not in the feature registry; its cells are stored as text.');
                }
                continue;
            }
            if ($h === '' || $h === 'image_files' || $this->knownProductColumn($h)) {
                continue;
            }
            $this->issue($file, null, $csv['raw_headers'][$i], 'warning', 'validation_failed', 'Unknown column "' . $csv['raw_headers'][$i] . '" was ignored.');
        }
        foreach (array_keys($this->ignoredColumns) as $h) {
            $csv['headers'] = array_values(array_filter($csv['headers'], static fn ($x) => $x !== $h));
            foreach ($csv['rows'] as &$r) {
                unset($r['values'][$h]);
            }
            unset($r);
        }
        if (!in_array('style_number', $csv['headers'], true)) {
            throw new ApiError('import_invalid', 'products.csv needs a style_number column.');
        }
        $this->recordPresentColumns($csv['headers']);
        $this->loadBrands();
        $groups = [];
        foreach ($csv['rows'] as $r) {
            $v = $r['values'];
            $style = $v['style_number'] ?? '';
            if ($style === '') {
                $this->issue($file, $r['row'], 'style_number', 'error', 'validation_failed', 'style_number is required.');
                continue;
            }
            if (!$this->articleCellsValid($r)) {
                continue;
            }
            $ext = ($v['product_id'] ?? '') !== '' ? $v['product_id'] : $style;
            if (!isset($groups[$ext])) {
                $groups[$ext] = ['ext' => $ext, 'first_row' => $r['row'], 'values' => [], 'rows' => []];
            }
            foreach ($v as $col => $val) {
                if ($val === '' || !$this->isProductColumn($col)) {
                    continue;
                }
                if (!isset($groups[$ext]['values'][$col])) {
                    $groups[$ext]['values'][$col] = [$val, $r['row']];
                } elseif ($groups[$ext]['values'][$col][0] !== $val) {
                    $this->issue($file, $r['row'], $col, 'warning', 'validation_failed', 'A different value than on row ' . $groups[$ext]['values'][$col][1] . ' of this product; the first value is used.', $val);
                }
            }
            $groups[$ext]['rows'][] = $r;
        }
        $plans = [];
        foreach ($groups as $g) {
            $p = $this->buildProduct($g);
            if ($p !== null) {
                $plans[] = $p;
            }
        }
        return $plans;
    }

    /** @var array<string, true> feature columns with an unusable key */
    private array $ignoredColumns = [];

    /**
     * The article data cells of one row (import-format.md §1.6): a cell that does not follow
     * the rules is an error validation_failed for that row, with the column, and the row is
     * skipped. Variant cells on a row without a variant are ignored with a warning.
     *
     * @param array{row: int, values: array<string, string>} $r
     */
    private function articleCellsValid(array $r): bool
    {
        $file = 'products.csv';
        $v = $r['values'];
        $ok = true;
        $fail = function (string $col, string $detail) use ($file, $r, $v, &$ok): void {
            $this->issue($file, $r['row'], $col, 'error', 'validation_failed', $detail, $v[$col] ?? '');
            $ok = false;
        };
        foreach ($v as $col => $cell) {
            if ($cell === '') {
                continue;
            }
            try {
                if ($col === 'contains_animal_origin' && Formats::bool($cell) === null) {
                    $fail($col, 'Write yes or no (or leave the cell empty when it is not known).');
                } elseif ($col === 'footwear_materials') {
                    ArticleData::parseFootwear($cell);
                } elseif ($col === 'certifications') {
                    ArticleData::parseCertifications($cell);
                } elseif (str_starts_with($col, 'feature:')) {
                    ArticleData::parseFeature(substr($col, 8), $cell);
                }
            } catch (\InvalidArgumentException $e) {
                $fail($col, $e->getMessage());
            }
        }
        $hasVariant = false;
        foreach (['gtin', 'variant_id', 'sku', 'color_code', 'size'] as $c) {
            if (($v[$c] ?? '') !== '') {
                $hasVariant = true;
            }
        }
        foreach (self::VARIANT_ONLY as $col) {
            $cell = $v[$col] ?? '';
            if ($cell === '') {
                continue;
            }
            if (!$hasVariant) {
                $this->issue($file, $r['row'], $col, 'warning', 'validation_failed', $col . ' is ignored on a row without a size or colour (gtin, variant_id, sku, color_code or size).', $cell);
                continue;
            }
            try {
                match (true) {
                    $col === 'color_family' => ArticleData::parseColorFamily($cell),
                    $col === 'size_length' => mb_strlen($cell, 'UTF-8') > 64 ? throw new \InvalidArgumentException('At most 64 characters.') : $cell,
                    $col === 'strap_length_cm' => ArticleData::parseStrapLength($cell),
                    default => ArticleData::parseMeasurement($cell),
                };
            } catch (\InvalidArgumentException $e) {
                $fail($col, $e->getMessage());
            }
        }
        return $ok;
    }

    private function knownProductColumn(string $h): bool
    {
        $base = explode(':', $h, 2)[0];
        if (str_starts_with($h, 'attr:') || str_starts_with($h, 'feature:')) {
            return true;
        }
        if (str_contains($h, ':')) {
            return in_array($base, ['name', 'description', 'color_name'], true);
        }
        return in_array($h, self::PRODUCT_COLS, true) || in_array($h, self::VARIANT_COLS, true);
    }

    private function isProductColumn(string $h): bool
    {
        $base = explode(':', $h, 2)[0];
        return str_starts_with($h, 'attr:') || str_starts_with($h, 'feature:') || in_array($base, self::PRODUCT_COLS, true);
    }

    private function loadBrands(): void
    {
        if ($this->brands !== []) {
            return;
        }
        foreach ($this->app->db->all('SELECT external_id, name FROM obx_brands') as $b) {
            $entry = ['ext' => (string) $b['external_id'], 'name' => (string) $b['name']];
            $this->brands[mb_strtolower((string) $b['name'], 'UTF-8')] = $entry;
            $this->brands[mb_strtolower((string) $b['external_id'], 'UTF-8')] = $entry;
        }
    }

    /**
     * @param array<string, mixed> $g
     * @return array<string, mixed>|null
     */
    private function buildProduct(array $g): ?array
    {
        $file = 'products.csv';
        $val = static fn (string $c): ?string => $g['values'][$c][0] ?? null;
        $row = static fn (string $c): int => $g['values'][$c][1] ?? $g['first_row'];
        $warnings = [];

        // Brand.
        $brandValue = $val('brand');
        $brandExt = null;
        $brandNew = null;
        if ($brandValue !== null) {
            $hit = $this->brands[mb_strtolower($brandValue, 'UTF-8')] ?? null;
            if ($hit !== null) {
                $brandExt = $hit['ext'];
            } else {
                $brandExt = Str::slug($brandValue);
                if ($brandExt === '') {
                    $this->issue($file, $row('brand'), 'brand', 'error', 'validation_failed', 'The brand name has no letters or digits.', $brandValue);
                    return null;
                }
                $brandNew = $brandValue;
                $this->brands[mb_strtolower($brandValue, 'UTF-8')] = ['ext' => $brandExt, 'name' => $brandValue];
                $this->brands[$brandExt] = ['ext' => $brandExt, 'name' => $brandValue];
            }
        } else {
            $count = (int) $this->app->db->value('SELECT COUNT(*) FROM obx_brands');
            if ($count !== 1) {
                $this->issue($file, $g['first_row'], 'brand', 'error', 'validation_failed', 'Fill in the brand column: this portal has more than one brand.');
                return null;
            }
            $brandExt = (string) $this->app->db->value('SELECT external_id FROM obx_brands');
        }

        $name = $this->localized($g['values'], 'name');
        if ($name === null) {
            $this->issue($file, $g['first_row'], 'name', 'error', 'validation_failed', 'A name is required (column name or name:<language>).');
            return null;
        }
        $data = [
            'brand_external_id' => $brandExt,
            'style_number' => $val('style_number'),
            'name' => $name,
            'description' => $this->localized($g['values'], 'description'),
        ];
        foreach (['item_type', 'item_subtype', 'target_group'] as $k) {
            $v = $val($k);
            if ($v !== null) {
                $key = Formats::key($v);
                if ($key === '') {
                    $this->issue($file, $row($k), $k, 'warning', 'validation_failed', 'Not a usable key; left empty.', $v);
                } else {
                    if ($key !== $v) {
                        $warnings[] = [$k, 'Written as "' . $key . '".', $v];
                    }
                    $data[$k] = $key;
                }
            }
        }
        foreach (['season', 'collection'] as $k) {
            if ($val($k) !== null) {
                $data[$k] = $val($k);
            }
        }
        if (($v = $val('gpc_brick')) !== null) {
            if (!preg_match('/^\d{8}$/', $v)) {
                $this->issue($file, $row('gpc_brick'), 'gpc_brick', 'warning', 'validation_failed', 'A GPC brick has 8 digits; left empty.', $v);
            } else {
                $data['gpc_brick'] = $v;
            }
        }
        if (($v = $val('materials')) !== null) {
            $mw = [];
            $m = Formats::parseMaterials($v, $mw);
            if ($m === null) {
                $this->issue($file, $row('materials'), 'materials', 'warning', 'validation_failed', 'Materials could not be read (use e.g. "outer: 65% cotton, 35% polyamide; lining: 100% polyester"); left empty.', $v);
            } else {
                $data['materials'] = $m;
                foreach ($mw as $w) {
                    $this->issue($file, $row('materials'), 'materials', 'warning', 'validation_failed', $w, $v);
                }
            }
        }
        // 0.2 article data (import-format.md §1.6); the rows were checked in articleCellsValid.
        if (($v = $val('contains_animal_origin')) !== null) {
            $data['contains_animal_origin'] = Formats::bool($v);
        }
        if (($v = $val('footwear_materials')) !== null) {
            $data['footwear_materials'] = ArticleData::parseFootwear($v);
        }
        if (($v = $val('certifications')) !== null) {
            $data['certifications'] = ArticleData::parseCertifications($v);
        }
        $features = [];
        foreach ($g['values'] as $col => [$cell]) {
            if (str_starts_with($col, 'feature:')) {
                $features[substr($col, 8)] = ArticleData::parseFeature(substr($col, 8), $cell);
            }
        }
        if ($features !== []) {
            $data['features'] = ArticleData::orderFeatures($features);
        }
        if (($v = $val('care')) !== null) {
            $care = [];
            foreach (explode('|', $v) as $code) {
                $code = strtolower(trim($code));
                if ($code === '') {
                    continue;
                }
                if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code)) {
                    $this->issue($file, $row('care'), 'care', 'error', 'validation_failed', 'Unknown care code "' . $code . '".', $v);
                    return null;
                }
                if (!in_array($code, Formats::CARE_CODES, true)) {
                    $this->issue($file, $row('care'), 'care', 'warning', 'validation_failed', 'Care code "' . $code . '" is not in the recommended list; kept.', $v);
                }
                $care[] = ['code' => $code];
            }
            if ($care !== []) {
                $data['care'] = $care;
            }
        }
        if (($v = $val('country_of_origin')) !== null) {
            $c = strtoupper($v);
            if (!preg_match('/^[A-Z]{2}$/', $c)) {
                $this->issue($file, $row('country_of_origin'), 'country_of_origin', 'warning', 'validation_failed', 'Use a two-letter country code (e.g. PT); left empty.', $v);
            } else {
                $data['country_of_origin'] = $c;
            }
        }
        if (($v = $val('dpp_url')) !== null) {
            if (!preg_match('#^https?://\S+$#i', $v)) {
                $this->issue($file, $row('dpp_url'), 'dpp_url', 'warning', 'validation_failed', 'Not a web address; left empty.', $v);
            } else {
                $data['dpp_url'] = $v;
            }
        }
        $attrs = [];
        foreach ($g['values'] as $col => [$v]) {
            if (str_starts_with($col, 'attr:')) {
                $key = substr($col, 5);
                if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
                    $attrs[$key] = $v;
                }
            }
        }
        if ($attrs !== []) {
            ksort($attrs);
            $data['attributes'] = $attrs;
        }
        if (($v = $val('status')) !== null) {
            $s = strtolower($v);
            if (!in_array($s, ['published', 'unpublished'], true)) {
                $this->issue($file, $row('status'), 'status', 'warning', 'validation_failed', 'Use published or unpublished; the current status is kept.', $v);
            } else {
                $data['status'] = $s;
            }
        }
        foreach ($warnings as [$col, $msg, $orig]) {
            $this->issue($file, $row($col), $col, 'warning', 'validation_failed', $msg, $orig);
        }
        $variants = [];
        foreach ($g['rows'] as $r) {
            $v = $this->buildVariant($r, (string) $data['style_number'], $g['ext']);
            if ($v !== null) {
                $variants[] = $v;
            }
        }
        return [
            'ext' => $g['ext'],
            'row' => $g['first_row'],
            'brand_ext' => $brandExt,
            'brand_new' => $brandNew,
            'data' => $data,
            'variants' => $variants,
        ];
    }

    /**
     * @param array<string, array{0: string, 1: int}>|array<string, string> $values
     * @return array<string, string>|null
     */
    private function localized(array $values, string $base): ?array
    {
        $out = [];
        $plain = $values[$base] ?? null;
        if ($plain !== null) {
            $out[$this->lang] = is_array($plain) ? $plain[0] : $plain;
        }
        foreach ($values as $col => $v) {
            if (str_starts_with($col, $base . ':')) {
                $tag = substr($col, strlen($base) + 1);
                $text = is_array($v) ? $v[0] : $v;
                if ($text !== '' && preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $tag)) {
                    $out[$tag] = $text;
                }
            }
        }
        if ($out === []) {
            return null;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * @param array{row: int, values: array<string, string>} $r
     * @return array<string, mixed>|null
     */
    private function buildVariant(array $r, string $style, string $productExt): ?array
    {
        $v = $r['values'];
        $file = 'products.csv';
        $any = false;
        foreach (['gtin', 'variant_id', 'sku', 'color_code', 'size'] as $c) {
            if (($v[$c] ?? '') !== '') {
                $any = true;
            }
        }
        if (!$any) {
            return null;
        }
        $color = $v['color_code'] ?? '';
        if ($color === '') {
            $this->issue($file, $r['row'], 'color_code', 'error', 'validation_failed', 'color_code is required for a size/colour row.');
            return null;
        }
        $gtin = null;
        $rawGtin = $v['gtin'] ?? '';
        if ($rawGtin !== '') {
            $g = (string) preg_replace('/\s+/', '', $rawGtin);
            if (preg_match('/[eE][+\-]?\d|[.,]/', $g)) {
                $this->issue($file, $r['row'], 'gtin', 'error', 'invalid_gtin', 'This EAN looks changed by a spreadsheet (scientific notation). Format the column as text and export again.', $rawGtin);
                return null;
            }
            if (!preg_match('/^(\d{8}|\d{12,14})$/', $g)) {
                $this->issue($file, $r['row'], 'gtin', 'error', 'invalid_gtin', 'An EAN has 8, 12, 13 or 14 digits.', $rawGtin);
                return null;
            }
            if (!Gtin::valid($g)) {
                $this->issue($file, $r['row'], 'gtin', 'error', 'invalid_gtin', 'The check digit of this EAN is wrong.', $rawGtin);
                return null;
            }
            $gtin = $g;
        }
        $size = ($v['size'] ?? '') !== '' ? $v['size'] : null;
        $ext = ($v['variant_id'] ?? '') !== '' ? $v['variant_id'] : ($gtin ?? ($style . '-' . $color . ($size !== null ? '-' . $size : '')));
        $family = null;
        if (($v['color_family'] ?? '') !== '') {
            $family = ArticleData::parseColorFamily($v['color_family']);
            $fk = $productExt . "\0" . $color;
            if (isset($this->families[$fk]) && $this->families[$fk] !== $family) {
                $this->issue($file, $r['row'], 'color_family', 'warning', 'validation_failed', 'Differs from an earlier row of colour ' . $color . ' (' . $this->families[$fk] . '); every size of one colour should have the same colour family.', $v['color_family']);
            }
            $this->families[$fk] ??= $family;
        }
        $measurements = [];
        foreach (ArticleData::MEASUREMENTS as $k) {
            if (($v[$k] ?? '') !== '') {
                $measurements[$k] = $k === 'strap_length_cm' ? ArticleData::parseStrapLength($v[$k]) : ArticleData::parseMeasurement($v[$k]);
            }
        }
        $data = [
            'product_external_id' => $productExt,
            'gtin' => $gtin,
            'sku' => ($v['sku'] ?? '') !== '' ? $v['sku'] : null,
            'color' => [
                'code' => $color,
                'name' => $this->localized($v, 'color_name'),
                'supplier_color' => ($v['supplier_color'] ?? '') !== '' ? $v['supplier_color'] : null,
                'family' => $family,
            ],
            'size' => $size,
            'size_length' => ($v['size_length'] ?? '') !== '' ? $v['size_length'] : null,
            'size_system' => ($v['size_system'] ?? '') !== '' ? $v['size_system'] : null,
        ];
        if ($measurements !== []) {
            $data['measurements'] = $measurements;
        }
        if (($s = strtolower($v['variant_status'] ?? '')) !== '') {
            if (in_array($s, ['published', 'unpublished'], true)) {
                $data['status'] = $s;
            } else {
                $this->issue($file, $r['row'], 'variant_status', 'warning', 'validation_failed', 'Use published or unpublished; the current status is kept.', $v['variant_status']);
            }
        }
        return ['ext' => $ext, 'row' => $r['row'], 'data' => $data];
    }

    // ================================================================ images

    /**
     * @param list<array{name: string, path: string}> $images
     * @param list<mixed> $manifest
     * @return array<string, array<string, mixed>> by file name
     */
    private function imageEntries(array $images, array $manifest): array
    {
        $out = [];
        $dupes = [];
        foreach ($images as $img) {
            $name = $img['name'];
            if (isset($out[$name])) {
                $dupes[$name] = true;
                continue;
            }
            $out[$name] = ['name' => $name, 'path' => $img['path'], 'sha' => null, 'renditions' => null];
        }
        foreach ($manifest as $i => $m) {
            $name = is_array($m) ? basename(str_replace('\\', '/', (string) ($m['file'] ?? ''))) : '';
            $sha = is_array($m) ? ($m['sha256'] ?? null) : null;
            if ($name === '' || !is_string($sha) || !preg_match('/^[a-f0-9]{64}$/', $sha)) {
                $this->issue('images_manifest', null, null, 'error', 'validation_failed', 'Manifest entry ' . $i . ' needs file and sha256.');
                continue;
            }
            if (isset($out[$name])) {
                $dupes[$name] = true;
                continue;
            }
            $rend = null;
            if (is_array($m['renditions'] ?? null)) {
                $rend = [];
                foreach (['web', 'square', 'thumb'] as $rn) {
                    $ref = $m['renditions'][$rn] ?? null;
                    if (is_array($ref) && is_string($ref['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $ref['sha256'])) {
                        $rend[$rn] = ['sha256' => $ref['sha256'], 'width' => is_int($ref['width'] ?? null) ? $ref['width'] : null, 'height' => is_int($ref['height'] ?? null) ? $ref['height'] : null];
                    }
                }
            }
            $out[$name] = ['name' => $name, 'path' => null, 'sha' => $sha, 'renditions' => $rend];
        }
        foreach (array_keys($dupes) as $name) {
            $this->issue($name, null, null, 'error', 'validation_failed', 'This file name occurs more than once in the import.');
            unset($out[$name]);
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $plan
     * @param array<string, array<string, mixed>> $images
     * @param array<string, mixed>|null $assetsCsv
     * @return array<string, list<array<string, mixed>>> by product external id
     */
    private function planAssets(array $plan, array $images, ?array $assetsCsv): array
    {
        // Catalog lookup: style (lower) -> [product ext, style spelling]; colours per product.
        $styles = [];
        $colors = [];
        foreach ($this->app->db->all('SELECT id, external_id, style_number FROM obx_products') as $p) {
            $styles[mb_strtolower((string) $p['style_number'], 'UTF-8')] = ['ext' => (string) $p['external_id'], 'style' => (string) $p['style_number']];
        }
        foreach ($this->app->db->all('SELECT p.external_id AS pext, v.color_code FROM obx_variants v JOIN obx_products p ON p.id = v.product_id WHERE v.color_code IS NOT NULL') as $v) {
            $colors[(string) $v['pext']][mb_strtolower((string) $v['color_code'], 'UTF-8')] = (string) $v['color_code'];
        }
        $productExts = [];
        foreach ($plan as $p) {
            $styles[mb_strtolower((string) $p['data']['style_number'], 'UTF-8')] = ['ext' => $p['ext'], 'style' => (string) $p['data']['style_number']];
            $productExts[mb_strtolower($p['ext'], 'UTF-8')] = $p['ext'];
            foreach ($p['variants'] as $v) {
                $colors[$p['ext']][mb_strtolower((string) $v['data']['color']['code'], 'UTF-8')] ??= (string) $v['data']['color']['code'];
            }
        }
        foreach ($this->app->db->column('SELECT external_id FROM obx_products') as $pe) {
            $productExts[mb_strtolower((string) $pe, 'UTF-8')] ??= (string) $pe;
        }

        $overrides = [];
        if ($assetsCsv !== null) {
            foreach ($assetsCsv['headers'] as $i => $h) {
                $base = explode(':', $h, 2)[0];
                if ($h !== '' && !in_array($h, self::ASSET_COLS, true) && $base !== 'alt_text') {
                    $this->issue('assets.csv', null, $assetsCsv['raw_headers'][$i], 'warning', 'validation_failed', 'Unknown column "' . $assetsCsv['raw_headers'][$i] . '" was ignored.');
                }
            }
            foreach ($assetsCsv['rows'] as $r) {
                $f = basename(str_replace('\\', '/', $r['values']['file'] ?? ''));
                if ($f === '') {
                    $this->issue('assets.csv', $r['row'], 'file', 'error', 'validation_failed', 'The file column is required.');
                    continue;
                }
                if (!isset($images[$f])) {
                    $this->issue('assets.csv', $r['row'], 'file', 'error', 'files_missing', 'The image "' . $f . '" is not part of this import.', $f);
                    continue;
                }
                $overrides[$f] = $r;
            }
        }

        $byExt = [];
        foreach ($images as $name => $img) {
            $o = $overrides[$name] ?? null;
            $ov = $o['values'] ?? [];
            $parsed = Formats::parseFilename($name);
            $styleIn = ($ov['style_number'] ?? '') !== '' ? $ov['style_number'] : null;
            $productIn = ($ov['product_id'] ?? '') !== '' ? $ov['product_id'] : null;
            $posIn = ($ov['position'] ?? '') !== '' ? $ov['position'] : null;
            if ($parsed === null && ($productIn === null && $styleIn === null || $posIn === null)) {
                $this->issue($name, null, null, 'error', 'validation_failed', 'The file name does not follow {style}_{colour}_{position}.jpg and assets.csv does not describe it.');
                continue;
            }
            // Product.
            $productExt = null;
            $styleSpelling = null;
            if ($productIn !== null) {
                $productExt = $productExts[mb_strtolower($productIn, 'UTF-8')] ?? null;
            } else {
                $s = $styles[mb_strtolower($styleIn ?? (string) $parsed['style'], 'UTF-8')] ?? null;
                if ($s !== null) {
                    $productExt = $s['ext'];
                    $styleSpelling = $s['style'];
                }
            }
            if ($productExt === null) {
                $this->issue($name, null, null, 'error', 'unknown_parent', 'No product with style number "' . ($productIn ?? $styleIn ?? $parsed['style']) . '" in this import or on the portal.');
                continue;
            }
            if ($styleSpelling === null) {
                foreach ($styles as $s) {
                    if ($s['ext'] === $productExt) {
                        $styleSpelling = $s['style'];
                        break;
                    }
                }
                $styleSpelling ??= $productExt;
            }
            // Colour.
            $fromCsv = array_key_exists('color_code', $ov) && $o !== null && ($parsed === null || $ov['color_code'] !== '');
            $colorRaw = $fromCsv ? $ov['color_code'] : ($parsed['color'] ?? '');
            // "ALL" in a file name (any case) is an image for every colour (import-format.md);
            // only assets.csv can address a colour whose code really is ALL.
            if (!$fromCsv && strcasecmp($colorRaw, 'ALL') === 0) {
                $colorRaw = '';
            }
            $color = null;
            if ($colorRaw !== '') {
                $color = $colors[$productExt][mb_strtolower($colorRaw, 'UTF-8')] ?? null;
                if ($color === null) {
                    $this->issue($name, null, null, 'error', 'unknown_parent', 'Product "' . $styleSpelling . '" has no colour "' . $colorRaw . '".');
                    continue;
                }
            }
            // Position.
            $position = $parsed['position'] ?? null;
            if ($posIn !== null) {
                if (!preg_match('/^[1-9][0-9]{0,2}$/', $posIn)) {
                    $this->issue('assets.csv', $o['row'], 'position', 'error', 'validation_failed', 'A position from 1 to 999.', $posIn);
                    continue;
                }
                $position = (int) $posIn;
            }
            $role = $parsed['role'] ?? 'packshot';
            $view = $parsed['view'] ?? null;
            if (($ov['role'] ?? '') !== '') {
                $r = strtolower($ov['role']);
                if (!in_array($r, Validator::ROLES, true)) {
                    $this->issue('assets.csv', $o['row'], 'role', 'error', 'validation_failed', 'Unknown role.', $ov['role']);
                    continue;
                }
                $role = $r;
            }
            if (($ov['view'] ?? '') !== '') {
                $vw = strtolower($ov['view']);
                if (!in_array($vw, Validator::VIEWS, true)) {
                    $this->issue('assets.csv', $o['row'], 'view', 'error', 'validation_failed', 'Unknown view.', $ov['view']);
                    continue;
                }
                $view = $vw;
            }
            $ext = ($ov['asset_id'] ?? '') !== '' ? $ov['asset_id'] : $styleSpelling . '_' . ($color ?? 'ALL') . '_' . $position;
            $data = [
                'product_external_id' => $productExt,
                'color_code' => $color,
                'role' => $role,
                'view' => $view,
                'position' => $position,
                'filename' => $name,
            ];
            if ($o !== null) {
                $extra = $this->assetOverrides($o);
                if ($extra === null) {
                    continue;
                }
                $data += $extra;
            }
            if (isset($byExt[$ext])) {
                $this->issue($name, null, null, 'error', 'validation_failed', 'Resolves to the same image as "' . $byExt[$ext]['file'] . '" (' . $ext . ').');
                $this->issue($byExt[$ext]['file'], null, null, 'error', 'validation_failed', 'Resolves to the same image as "' . $name . '" (' . $ext . ').');
                $byExt[$ext]['dupe'] = true;
                continue;
            }
            $byExt[$ext] = ['ext' => $ext, 'file' => $name, 'image' => $img, 'data' => $data, 'product' => $productExt, 'dupe' => false];
        }
        $out = [];
        foreach ($byExt as $a) {
            if (!$a['dupe']) {
                $out[$a['product']][] = $a;
            }
        }
        return $out;
    }

    /**
     * Rights, provenance, alt text and status from an assets.csv row.
     *
     * @param array{row: int, values: array<string, string>} $o
     * @return array<string, mixed>|null
     */
    private function assetOverrides(array $o): ?array
    {
        $v = $o['values'];
        $row = $o['row'];
        $out = [];
        $alt = $this->localized($v, 'alt_text');
        if ($alt !== null) {
            $out['alt_text'] = $alt;
        }
        $rights = [];
        foreach (['license', 'license_text', 'credit'] as $k) {
            if (($v[$k] ?? '') !== '') {
                $rights[$k] = $v[$k];
            }
        }
        foreach (['channels', 'territories'] as $k) {
            if (($v[$k] ?? '') !== '') {
                $list = array_values(array_filter(array_map('trim', explode('|', $v[$k])), static fn ($x) => $x !== ''));
                $rights[$k] = $k === 'channels' ? array_map('strtolower', $list) : array_map('strtoupper', $list);
            }
        }
        foreach (['embargo_until', 'expires_at'] as $k) {
            if (($v[$k] ?? '') !== '') {
                $n = Clock::normalize($v[$k]);
                if ($n === null) {
                    $this->issue('assets.csv', $row, $k, 'error', 'validation_failed', 'Use a date like 2030-01-01 or 2030-01-01T00:00:00Z.', $v[$k]);
                    return null;
                }
                $rights[$k] = $n;
            }
        }
        if (($v['model_release'] ?? '') !== '') {
            $b = Formats::bool($v['model_release']);
            if ($b === null) {
                $this->issue('assets.csv', $row, 'model_release', 'error', 'validation_failed', 'Use yes or no.', $v['model_release']);
                return null;
            }
            $rights['model_release'] = $b;
        }
        if ($rights !== []) {
            $out['rights'] = $rights;
        }
        $prov = [];
        foreach (['ai_generated', 'ai_edited'] as $k) {
            if (($v[$k] ?? '') !== '') {
                $b = Formats::bool($v[$k]);
                if ($b === null) {
                    $this->issue('assets.csv', $row, $k, 'error', 'validation_failed', 'Use yes or no.', $v[$k]);
                    return null;
                }
                $prov[$k] = $b;
            }
        }
        foreach (['source', 'c2pa', 'c2pa_manifest_url', 'generator'] as $k) {
            if (($v[$k] ?? '') !== '') {
                $prov[$k] = in_array($k, ['source', 'c2pa'], true) ? strtolower($v[$k]) : $v[$k];
            }
        }
        if ($prov !== []) {
            $out['provenance'] = $prov;
        }
        if (($v['status'] ?? '') !== '') {
            $s = strtolower($v['status']);
            if (!in_array($s, ['published', 'unpublished'], true)) {
                $this->issue('assets.csv', $row, 'status', 'error', 'validation_failed', 'Use published or unpublished.', $v['status']);
                return null;
            }
            $out['status'] = $s;
        }
        // Validate early so problems point at the assets.csv row.
        try {
            $val = new Validator();
            $val->asset(['product_external_id' => 'x', 'role' => 'packshot', 'position' => 1] + array_intersect_key($out, ['rights' => 1, 'provenance' => 1, 'alt_text' => 1]));
        } catch (ApiError $e) {
            $col = trim((string) ($e->errors[0]['pointer'] ?? ''), '/');
            $this->issue('assets.csv', $row, $col !== '' ? explode('/', $col)[count(explode('/', $col)) - 1] : null, 'error', $e->obxError, $e->getMessage());
            return null;
        }
        return $out;
    }

    // ================================================================ mirror

    /** @param list<array<string, mixed>> $plan */
    private function checkMirror(array $plan): void
    {
        $brandExts = array_values(array_unique(array_map(static fn ($p) => (string) $p['brand_ext'], $plan)));
        if ($brandExts === []) {
            return;
        }
        $inFile = array_fill_keys(array_map(static fn ($p) => (string) $p['ext'], $plan), true);
        $rows = $this->app->db->all(
            'SELECT p.external_id FROM obx_products p JOIN obx_brands b ON b.id = p.brand_id WHERE p.status = \'published\' AND b.external_id IN (' .
            implode(', ', array_fill(0, count($brandExts), '?')) . ')',
            $brandExts
        );
        $missing = array_values(array_filter(array_map(static fn ($r) => (string) $r['external_id'], $rows), static fn ($e) => !isset($inFile[$e])));
        if (count($rows) > 0 && count($missing) * 2 > count($rows) && empty($this->options['allow_mass_unpublish'])) {
            throw new ApiError('mass_unpublish_refused', 'This mirror import would take ' . count($missing) . ' of ' . count($rows) . ' products offline. That looks like an incomplete export, so nothing was changed. Tick "allow mass unpublish" if this is intended.');
        }
        $this->mirrorUnpublish = $missing;
    }

    /** @var list<string> */
    private array $mirrorUnpublish = [];

    /** @var array<string, string> product external id + "\0" + colour code => first colour family */
    private array $families = [];

    /**
     * Which columns products.csv has (import rule 9): plain columns, the languages of each text
     * column (the plain one is the import's default language), the attr:<key> and the
     * feature:<key> columns.
     *
     * @var array{plain: array<string, true>, langs: array<string, array<string, true>>, attrs: array<string, true>, features: array<string, true>}
     */
    private array $present = ['plain' => [], 'langs' => [], 'attrs' => [], 'features' => []];

    /** Fields of the stored product and variant model, by the products.csv column they come from. */
    private const PRODUCT_FIELDS = [
        ['name', 'name', 'text'], ['description', 'description', 'text'], ['item_type', 'item_type', 'plain'],
        ['item_subtype', 'item_subtype', 'plain'],
        ['target_group', 'target_group', 'plain'], ['season', 'season', 'plain'], ['collection', 'collection', 'plain'],
        ['gpc_brick', 'gpc_brick', 'plain'], ['materials', 'materials', 'plain'],
        ['contains_animal_origin', 'contains_animal_origin', 'plain'], ['footwear_materials', 'footwear_materials', 'plain'],
        ['care', 'care', 'plain'], ['country_of_origin', 'country_of_origin', 'plain'],
        ['certifications', 'certifications', 'plain'], ['dpp_url', 'dpp_url', 'plain'],
        // Per key (rule 9): feature:<key> columns and the measurement columns.
        ['features', 'feature', 'features'], ['attributes', 'attr', 'attrs'],
    ];
    private const VARIANT_FIELDS = [
        ['gtin', 'gtin', 'plain'], ['sku', 'sku', 'plain'], ['size', 'size', 'plain'], ['size_length', 'size_length', 'plain'],
        ['size_system', 'size_system', 'plain'], ['color.name', 'color_name', 'text'], ['color.family', 'color_family', 'plain'],
        ['color.supplier_color', 'supplier_color', 'plain'], ['measurements', 'measurements', 'measurements'],
    ];

    /** @param list<string> $headers normalised products.csv header */
    private function recordPresentColumns(array $headers): void
    {
        $p = ['plain' => [], 'langs' => ['name' => [], 'description' => [], 'color_name' => []], 'attrs' => [], 'features' => []];
        foreach ($headers as $h) {
            if ($h === '') {
                continue;
            }
            if (str_contains($h, ':')) {
                [$base, $tag] = explode(':', $h, 2);
                if ($base === 'attr') {
                    $p['attrs'][$tag] = true;
                } elseif ($base === 'feature') {
                    $p['features'][$tag] = true;
                } elseif (isset($p['langs'][$base])) {
                    $p['langs'][$base][$tag] = true;
                }
                continue;
            }
            $p['plain'][$h] = true;
            if (isset($p['langs'][$h])) {
                $p['langs'][$h][$this->lang] = true;
            }
        }
        $this->present = $p;
    }

    /**
     * Import rule 9 (import-format.md): for a product or variant the node already has, a field
     * whose column the file does not have keeps its stored value (texts per language; attributes,
     * features and measurements per key); an empty cell in a column the file has still removes
     * the value. When the last feature or measurement is removed, the object is null.
     *
     * @param array<string, mixed> $data what this import built
     * @param array<string, mixed> $stored the stored write model
     * @param list<array{0: string, 1: string, 2: string}> $fields
     * @return array<string, mixed>
     */
    private function keepAbsentColumns(array $data, array $stored, array $fields): array
    {
        foreach ($fields as [$path, $column, $kind]) {
            $parts = explode('.', $path);
            $key = (string) array_pop($parts);
            $was = $stored;
            foreach ($parts as $seg) {
                $was = is_array($was[$seg] ?? null) ? $was[$seg] : null;
            }
            if (!is_array($was) || !array_key_exists($key, $was) || $was[$key] === null) {
                continue;
            }
            $old = $was[$key];
            $parent = &$data;
            foreach ($parts as $seg) {
                if (!isset($parent[$seg]) || !is_array($parent[$seg])) {
                    unset($parent);
                    continue 2;
                }
                $parent = &$parent[$seg];
            }
            if ($kind === 'plain') {
                if (!array_key_exists($key, $parent) && !isset($this->present['plain'][$column])) {
                    $parent[$key] = $old;
                }
            } elseif (is_array($old)) {
                $now = is_array($parent[$key] ?? null) ? $parent[$key] : [];
                foreach ($old as $k => $v) {
                    $absent = match ($kind) {
                        'attrs' => !isset($this->present['attrs'][$k]),
                        'features' => !isset($this->present['features'][$k]),
                        'measurements' => !isset($this->present['plain'][$k]),
                        default => !isset($this->present['langs'][$column][$k]),
                    };
                    if (!array_key_exists($k, $now) && $absent) {
                        $now[$k] = $v;
                    }
                }
                if ($now !== []) {
                    if ($kind === 'text') {
                        ksort($now, SORT_STRING);
                    }
                    $parent[$key] = $now;
                }
            }
            unset($parent);
        }
        return $data;
    }

    // ================================================================ apply

    /**
     * @param list<array<string, mixed>> $plan
     * @param array<string, list<array<string, mixed>>> $assets
     */
    private function apply(array $plan, array $assets): void
    {
        $w = $this->app->writer;
        $ctx = ['brands' => $this->restriction, 'op' => 'upsert'];
        $done = 0;
        $total = count($plan) + count($assets);
        if ($this->jobId !== null) {
            $this->app->jobs->progress($this->jobId, 0, $total);
        }
        $createdBrands = [];
        foreach ($plan as $p) {
            $file = 'products.csv';
            if ($p['brand_new'] !== null && !isset($createdBrands[$p['brand_ext']])) {
                try {
                    if ($w->load('brand', $p['brand_ext']) === null) {
                        $this->count($w->upsert('brand', $p['brand_ext'], ['name' => $p['brand_new']], $ctx));
                    }
                    $createdBrands[$p['brand_ext']] = true;
                } catch (ApiError $e) {
                    $this->issue($file, $p['row'], 'brand', 'error', $e->obxError, $e->getMessage(), $p['brand_new']);
                    continue;
                }
            }
            try {
                $stored = $w->load('product', $p['ext']);
                if ($stored !== null) {
                    $p['data'] = $this->keepAbsentColumns($p['data'], Json::decode((string) $stored['input'], []), self::PRODUCT_FIELDS);
                }
                $this->count($w->upsert('product', $p['ext'], $p['data'], $ctx));
            } catch (ApiError $e) {
                $this->issue($file, $p['row'], self::column($e), 'error', $e->obxError, $e->getMessage());
                unset($assets[$p['ext']]);
                continue;
            }
            // Rule 10: the product as stored (the file's values plus what rule 9 kept), reported
            // at the product's first row; never blocking.
            $stored = $w->load('product', $p['ext']);
            foreach (ArticleData::labelWarnings($stored !== null ? (array) Json::decode((string) $stored['input'], []) : []) as $lw) {
                $this->issue($file, $p['row'], $lw['column'], 'warning', $lw['code'], $lw['detail']);
            }
            foreach ($p['variants'] as $v) {
                try {
                    $stored = $w->load('variant', $v['ext']);
                    if ($stored !== null) {
                        $v['data'] = $this->keepAbsentColumns($v['data'], Json::decode((string) $stored['input'], []), self::VARIANT_FIELDS);
                    }
                    $this->count($w->upsert('variant', $v['ext'], $v['data'], $ctx));
                } catch (ApiError $e) {
                    $this->issue($file, $v['row'], self::column($e), 'error', $e->obxError, $e->getMessage(), $e->obxError === 'invalid_gtin' || $e->obxError === 'gtin_conflict' ? (string) ($v['data']['gtin'] ?? '') : null);
                }
            }
            if (isset($assets[$p['ext']])) {
                $this->applyAssets($p['ext'], $assets[$p['ext']], $ctx);
                unset($assets[$p['ext']]);
            }
            $this->progress(++$done);
        }
        foreach ($assets as $productExt => $list) {
            $this->applyAssets((string) $productExt, $list, $ctx);
            $this->progress(++$done);
        }
        foreach ($this->mirrorUnpublish as $ext) {
            try {
                $r = $w->setStatus('product', $ext, false, $ctx);
                if ($r['result'] === 'updated') {
                    $this->counts['unpublished']++;
                }
            } catch (ApiError $e) {
                $this->issue('products.csv', null, null, 'error', $e->obxError, 'Could not unpublish "' . $ext . '": ' . $e->getMessage());
            }
        }
    }

    private function progress(int $done): void
    {
        if ($this->jobId !== null && ($done % 10 === 0)) {
            $this->app->jobs->progress($this->jobId, $done);
        }
    }

    private static function column(ApiError $e): ?string
    {
        $p = trim((string) ($e->errors[0]['pointer'] ?? ''), '/');
        if ($p === '') {
            return null;
        }
        $parts = explode('/', $p);
        return $parts[0];
    }

    /** @param array<string, mixed> $result */
    private function count(array $result): void
    {
        $r = $result['result'] ?? '';
        if (isset($this->counts[$r])) {
            $this->counts[$r]++;
        }
    }

    /**
     * Applies the images of one product: blobs first, then all assets per colour atomically.
     *
     * @param list<array<string, mixed>> $list
     * @param array<string, mixed> $ctx
     */
    private function applyAssets(string $productExt, array $list, array $ctx): void
    {
        $product = $this->app->writer->load('product', $productExt);
        if ($product === null) {
            foreach ($list as $a) {
                $this->issue($a['file'], null, null, 'error', 'unknown_parent', 'Product "' . $productExt . '" could not be imported.');
            }
            return;
        }
        // Resolve bytes.
        $ready = [];
        foreach ($list as $a) {
            $img = $a['image'];
            try {
                if ($img['path'] !== null) {
                    $blob = $this->app->blobs->storeCopy($img['path']);
                    $files = ['original' => ['sha256' => $blob['sha256']]];
                } else {
                    if (!$this->app->blobs->has((string) $img['sha'])) {
                        throw new ApiError('blob_missing', 'Upload this image first (PUT /blobs/' . $img['sha'] . ').');
                    }
                    $files = ['original' => ['sha256' => $img['sha']]];
                    foreach ((array) $img['renditions'] as $rn => $ref) {
                        $files[$rn] = $ref;
                    }
                }
                $a['data']['files'] = $files;
                $ready[] = $a;
            } catch (ApiError $e) {
                $this->issue($a['file'], null, null, 'error', $e->obxError, $e->getMessage());
            }
        }
        // Group by colour and check positions against assets that are not part of this import.
        $groups = [];
        foreach ($ready as $a) {
            $groups[(string) ($a['data']['color_code'] ?? "\0")][] = $a;
        }
        foreach ($groups as $colorKey => $group) {
            $color = $colorKey === "\0" ? null : (string) $colorKey;
            $exts = array_map(static fn ($a) => $a['ext'], $group);
            $existing = $color === null
                ? $this->app->db->all('SELECT external_id, position FROM obx_assets WHERE product_id = ? AND color_code IS NULL', [$product['id']])
                : $this->app->db->all('SELECT external_id, position FROM obx_assets WHERE product_id = ? AND color_code = ?', [$product['id'], $color]);
            $taken = [];
            foreach ($existing as $e) {
                if (!in_array($e['external_id'], $exts, true)) {
                    $taken[(int) $e['position']] = (string) $e['external_id'];
                }
            }
            $seen = [];
            $apply = [];
            foreach ($group as $a) {
                $pos = (int) $a['data']['position'];
                if (isset($taken[$pos])) {
                    $this->issue($a['file'], null, 'position', 'error', 'position_conflict', 'Position ' . $pos . ' is already used by image "' . $taken[$pos] . '".');
                    continue;
                }
                if (isset($seen[$pos])) {
                    $this->issue($a['file'], null, 'position', 'error', 'position_conflict', 'Position ' . $pos . ' is also used by "' . $seen[$pos] . '" in this import.');
                    continue;
                }
                $seen[$pos] = $a['file'];
                $apply[] = $a;
            }
            $this->app->db->tx(function () use ($apply, $ctx) {
                foreach ($apply as $a) {
                    try {
                        $stored = $this->app->writer->load('asset', $a['ext']);
                        if ($stored !== null) {
                            $a['data'] = self::keepImageFacts($a['data'], Json::decode((string) $stored['input'], []));
                        }
                        $this->count($this->app->writer->upsert('asset', $a['ext'], $a['data'], $ctx + ['skip_position' => true]));
                    } catch (ApiError $e) {
                        $this->issue($a['file'], null, self::column($e), 'error', $e->obxError, $e->getMessage());
                    }
                }
            });
        }
    }

    /**
     * Import rule 8 (import-format.md): for an image the node already has, alt text (per
     * language), rights and provenance fields that the import does not state keep their stored
     * values. The file name and the assets.csv row still decide the file, product, colour,
     * position, role and view; a PUT without status keeps the stored status anyway. So a nightly
     * import of the images alone never lifts an embargo or clears an AI label set in the admin.
     *
     * @param array<string, mixed> $data the asset input this import built
     * @param array<string, mixed> $stored the stored write model
     * @return array<string, mixed>
     */
    private static function keepImageFacts(array $data, array $stored): array
    {
        foreach (['alt_text', 'rights', 'provenance'] as $k) {
            $old = $stored[$k] ?? null;
            if (!is_array($old) || $old === []) {
                continue;
            }
            $stated = $data[$k] ?? null;
            $data[$k] = is_array($stated) ? array_replace($old, $stated) : $old;
        }
        return $data;
    }
}

/** @internal thrown to roll back a dry-run import */
final class DryRunRollback extends \RuntimeException
{
}
