<?php

declare(strict_types=1);

namespace Obx\Node\Catalog;

use Obx\Node\Db\Db;
use Obx\Node\Http\ApiError;
use Obx\Node\Images\ImageInfo;
use Obx\Node\Images\Renderer;
use Obx\Node\Images\Renditions;
use Obx\Node\Services\Blobs;
use Obx\Node\Services\Settings;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Ids;
use Obx\Node\Support\Json;

/**
 * The one write path of the node: OBX Ingest semantics (docs/ingest.md) shared by the Ingest
 * API, the admin catalog endpoints, batches and imports. Every write reconciles the protocol
 * visibility and representation of the affected objects and appends change-feed entries
 * exactly as docs/ingest.md §8 prescribes.
 *
 * Context keys ($ctx): brands (?list<string> token brand restriction), if_match (?string),
 * op (result label), index (?int batch index), skip_position (bool, imports apply positions
 * of one product colour atomically and check them up front).
 */
final class Writer
{
    public const TABLES = ['brand' => 'obx_brands', 'product' => 'obx_products', 'variant' => 'obx_variants', 'asset' => 'obx_assets'];
    private const PREFIX = ['brand' => 'brd', 'product' => 'prd', 'variant' => 'var', 'asset' => 'ast'];

    /** Skip server-side rendering (dry-run imports; the transaction is rolled back). */
    public bool $noRender = false;

    public function __construct(
        private Db $db,
        private Settings $settings,
        private Blobs $blobs,
        private Renditions $renditions,
        private ChangeLog $log,
    ) {
    }

    public function serverRenders(): bool
    {
        return $this->renditions->serverGenerated();
    }

    // ================================================================ public operations

    /**
     * PUT: create or replace.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, mixed> IngestResult plus "_status" (HTTP status)
     */
    public function upsert(string $kind, string $externalId, mixed $data, array $ctx = []): array
    {
        $ext = Validator::externalId($externalId);
        $v = new Validator();
        $files = null;
        [$model, $status] = match ($kind) {
            'brand' => $v->brand($data),
            'product' => $v->product($data),
            'variant' => $v->variant($data),
            'asset' => (function () use ($v, $data, &$files) {
                [$m, $s, $files] = $v->asset($data);
                return [$m, $s];
            })(),
            default => throw new ApiError('not_found', 'Unknown object kind.'),
        };
        $ctx['op'] ??= 'upsert';
        return $this->db->tx(fn () => $this->write($kind, $ext, $model, $status, $files, $ctx, $v->warnings));
    }

    /**
     * PATCH: JSON merge patch of the stored write model.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function patch(string $kind, string $externalId, mixed $patch, array $ctx = []): array
    {
        $ext = Validator::externalId($externalId);
        if (!Json::isObject($patch)) {
            throw new ApiError('validation_failed', 'A merge patch must be a JSON object.', [['pointer' => '', 'detail' => 'Must be an object.']]);
        }
        $ctx['op'] ??= 'patch';
        return $this->db->tx(function () use ($kind, $ext, $patch, $ctx) {
            $row = $this->load($kind, $ext);
            if ($row === null) {
                throw new ApiError('not_found', 'No ' . $kind . ' with external id "' . $ext . '".');
            }
            $current = Json::decode((string) $row['input'], []);
            $current['status'] = $row['status'];
            if ($kind === 'asset' && array_key_exists('files', $patch)) {
                $current['files'] = self::filesForPatch(Json::decode((string) $row['files'], []));
            }
            $target = Validator::mergePatch($current, $patch);
            $v = new Validator();
            $files = null;
            [$model, $status] = match ($kind) {
                'brand' => $v->brand($target),
                'product' => $v->product($target),
                'variant' => $v->variant($target),
                'asset' => (function () use ($v, $target, &$files) {
                    [$m, $s, $files] = $v->asset($target);
                    return [$m, $s];
                })(),
                default => throw new ApiError('not_found', 'Unknown object kind.'),
            };
            return $this->write($kind, $ext, $model, $status ?? (string) $row['status'], $files, $ctx, $v->warnings);
        });
    }

    /** publish / unpublish = patch of status. @return array<string, mixed> */
    public function setStatus(string $kind, string $externalId, bool $publish, array $ctx = []): array
    {
        $ctx['op'] ??= $publish ? 'publish' : 'unpublish';
        return $this->patch($kind, $externalId, ['status' => $publish ? 'published' : 'unpublished'], $ctx);
    }

    /**
     * DELETE (idempotent). Products cascade to variants and assets; brands must be empty.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function remove(string $kind, string $externalId, array $ctx = []): array
    {
        $ext = Validator::externalId($externalId);
        $ctx['op'] ??= 'delete';
        return $this->db->tx(function () use ($kind, $ext, $ctx) {
            $row = $this->load($kind, $ext);
            if ($row === null) {
                return $this->result($ctx, $kind, $ext, 'unchanged', null, null, []);
            }
            $this->checkIfMatch($row, $ctx);
            $brandExt = $this->brandExternalIdOf($kind, $row);
            $this->checkRestriction($brandExt, $ctx);
            $now = Clock::iso();
            if ($kind === 'brand') {
                if ((int) $this->db->value('SELECT COUNT(*) FROM obx_products WHERE brand_id = ?', [$row['id']]) > 0) {
                    throw new ApiError('conflict', 'This brand still has products. Delete or move them first.');
                }
                if ((int) $row['visible'] === 1) {
                    $this->log->append('brand', 'delete', $row['id'], $row['external_id'], $row['id'], null, $now);
                }
                $this->db->delete('obx_brands', ['id' => $row['id']]);
            } elseif ($kind === 'product') {
                if ((int) $row['visible'] === 1) {
                    $this->log->append('product', 'delete', $row['id'], $row['external_id'], $row['brand_id'], null, $now);
                }
                $variants = $this->db->all('SELECT * FROM obx_variants WHERE product_id = ?', [$row['id']]);
                usort($variants, static fn ($a, $b) => strcmp((string) $a['external_id'], (string) $b['external_id']));
                foreach ($variants as $vr) {
                    if ((int) $vr['visible'] === 1) {
                        $this->log->append('variant', 'delete', $vr['id'], $vr['external_id'], $row['brand_id'], $row['id'], $now);
                    }
                }
                $assets = $this->db->all('SELECT * FROM obx_assets WHERE product_id = ?', [$row['id']]);
                usort($assets, [self::class, 'assetOrderCmp']);
                foreach ($assets as $a) {
                    if ((int) $a['visible'] === 1) {
                        $this->log->append('asset', 'delete', $a['id'], $a['external_id'], $row['brand_id'], $row['id'], $now);
                    }
                }
                $this->db->delete('obx_variants', ['product_id' => $row['id']]);
                $this->db->delete('obx_assets', ['product_id' => $row['id']]);
                $this->db->delete('obx_products', ['id' => $row['id']]);
            } else {
                $product = $this->db->one('SELECT * FROM obx_products WHERE id = ?', [$row['product_id']]);
                if ((int) $row['visible'] === 1) {
                    $this->log->append($kind, 'delete', $row['id'], $row['external_id'], $product['brand_id'] ?? null, $row['product_id'], $now);
                }
                $this->db->delete(self::TABLES[$kind], ['id' => $row['id']]);
                if ($product !== null) {
                    $ids = ['products' => [$product['id']]];
                    if ($kind === 'variant') {
                        $ids['assets'] = $this->db->column('SELECT id FROM obx_assets WHERE product_id = ?', [$product['id']]);
                    }
                    $this->sync($ids, touchProducts: [$product['id']]);
                }
            }
            return $this->result($ctx, $kind, $ext, 'deleted', $row['id'], null, [], 200, null);
        });
    }

    /**
     * Assigns positions 1..n to all assets of one product colour atomically.
     *
     * @param list<string> $assetExternalIds
     * @param array<string, mixed> $ctx
     * @return list<array<string, mixed>>
     */
    public function assetOrder(string $productExternalId, ?string $colorCode, array $assetExternalIds, array $ctx = []): array
    {
        $ext = Validator::externalId($productExternalId);
        return $this->db->tx(function () use ($ext, $colorCode, $assetExternalIds, $ctx) {
            $product = $this->load('product', $ext);
            if ($product === null) {
                throw new ApiError('not_found', 'No product with external id "' . $ext . '".');
            }
            $this->checkRestriction($this->brandExternalIdOf('product', $product), $ctx);
            $assets = $colorCode === null
                ? $this->db->all('SELECT * FROM obx_assets WHERE product_id = ? AND color_code IS NULL', [$product['id']])
                : $this->db->all('SELECT * FROM obx_assets WHERE product_id = ? AND color_code = ?', [$product['id'], $colorCode]);
            $byExt = [];
            foreach ($assets as $a) {
                $byExt[(string) $a['external_id']] = $a;
            }
            $given = array_values(array_map('strval', $assetExternalIds));
            if (count($given) !== count(array_unique($given)) || count($given) !== count($byExt) || array_diff($given, array_keys($byExt)) !== []) {
                throw ApiError::validation('/asset_external_ids', 'List every asset of this product and colour exactly once (' . count($byExt) . ' assets).');
            }
            $now = Clock::iso();
            $changed = [];
            $results = [];
            foreach ($given as $i => $aext) {
                $row = $byExt[$aext];
                $input = Json::decode((string) $row['input'], []);
                $newPos = $i + 1;
                if ((int) $row['position'] !== $newPos) {
                    $input['position'] = $newPos;
                    $etag = $this->etag('asset', $input, (string) $row['status'], (string) $row['files'], (string) $row['pending']);
                    $this->db->update('obx_assets', [
                        'input' => Json::encode($input),
                        'position' => $newPos,
                        'etag' => $etag,
                        'rec_updated_at' => $now,
                    ], ['id' => $row['id']]);
                    $changed[] = $row['id'];
                    $results[] = ['row' => $row['id'], 'ext' => $aext, 'result' => 'updated', 'etag' => $etag];
                } else {
                    $results[] = ['row' => $row['id'], 'ext' => $aext, 'result' => 'unchanged', 'etag' => $row['etag']];
                }
            }
            if ($changed !== []) {
                $this->sync(['assets' => $changed]);
            }
            $out = [];
            foreach ($results as $r) {
                $fresh = $this->db->one('SELECT * FROM obx_assets WHERE id = ?', [$r['row']]);
                $out[] = $this->result($ctx + ['op' => 'upsert'], 'asset', $r['ext'], $r['result'], $r['row'], $fresh, []);
            }
            return $out;
        });
    }

    /**
     * Attaches an uploaded blob as one file of an asset (Ingest PUT /assets/{id}/files/{rendition}).
     *
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function putFile(string $assetExternalId, string $rendition, string $sha, array $ctx = []): array
    {
        $ext = Validator::externalId($assetExternalId);
        $ctx['op'] ??= 'upsert';
        return $this->db->tx(function () use ($ext, $rendition, $sha, $ctx) {
            $row = $this->load('asset', $ext);
            if ($row === null) {
                throw new ApiError('not_found', 'Create the asset metadata first: no asset with external id "' . $ext . '".');
            }
            $model = Json::decode((string) $row['input'], []);
            return $this->write('asset', $ext, $model, (string) $row['status'], [$rendition => ['sha256' => $sha, 'width' => null, 'height' => null]], $ctx, [], true);
        });
    }

    // ================================================================ core write

    /**
     * @param array<string, mixed> $model
     * @param array<string, array<string, mixed>>|null $files
     * @param array<string, mixed> $ctx
     * @param list<string> $warnings
     * @return array<string, mixed>
     */
    private function write(string $kind, string $ext, array $model, ?string $status, ?array $files, array $ctx, array $warnings, bool $fileOnly = false): array
    {
        $row = $this->load($kind, $ext);
        $this->checkIfMatch($row, $ctx);
        $now = Clock::iso();
        $set = [];
        $sync = [];
        $touch = [];
        // Label completeness (docs/ingest.md §3.4): reported, never blocking.
        $details = [];
        if ($kind === 'product' && !$fileOnly) {
            foreach (ArticleData::labelWarnings($model) as $lw) {
                $warnings[] = $lw['code'] . ': ' . $lw['detail'];
                $details[] = ['code' => $lw['code'], 'pointer' => $lw['pointer'], 'detail' => $lw['detail']];
            }
        }
        switch ($kind) {
            case 'brand':
                $this->checkRestriction($ext, $ctx);
                break;
            case 'product':
                $brand = $this->resolveBrand($model['brand_external_id']);
                $model['brand_external_id'] = $brand['external_id'];
                $this->checkRestriction((string) $brand['external_id'], $ctx);
                if ($row !== null && $row['brand_id'] !== $brand['id']) {
                    $this->checkRestriction($this->brandExternalIdOf('product', $row), $ctx);
                }
                $set['brand_id'] = $brand['id'];
                $set['style_number'] = $model['style_number'];
                $set['item_type'] = $model['item_type'];
                $set['season'] = $model['season'];
                $set['collection'] = $model['collection'];
                $set['target_group'] = $model['target_group'];
                break;
            case 'variant':
                $product = $this->load('product', (string) $model['product_external_id']);
                if ($product === null) {
                    throw new ApiError('unknown_parent', 'Product "' . $model['product_external_id'] . '" does not exist. Create it first.', [['pointer' => '/product_external_id', 'detail' => 'Unknown product.', 'obx_error' => 'unknown_parent']]);
                }
                $this->checkRestriction($this->brandExternalIdOf('product', $product), $ctx);
                $gtin14 = $model['gtin'] !== null ? str_pad((string) $model['gtin'], 14, '0', STR_PAD_LEFT) : null;
                if ($gtin14 !== null) {
                    $other = $this->db->one('SELECT external_id FROM obx_variants WHERE gtin = ? AND external_id <> ?', [$gtin14, $ext]);
                    if ($other !== null) {
                        throw new ApiError('gtin_conflict', 'GTIN ' . $model['gtin'] . ' already belongs to variant "' . $other['external_id'] . '".', [['pointer' => '/gtin', 'detail' => 'Already used by another variant.', 'obx_error' => 'gtin_conflict']]);
                    }
                }
                $set['product_id'] = $product['id'];
                $set['gtin'] = $gtin14;
                $set['color_code'] = $model['color']['code'] ?? null;
                if ($row !== null && $row['product_id'] !== $product['id']) {
                    $sync['products'][] = $row['product_id'];
                    $touch[] = $row['product_id'];
                }
                $sync['products'][] = $product['id'];
                $sync['assets'] = $this->db->column("SELECT id FROM obx_assets WHERE product_id = ? AND input LIKE '%variant_external_ids\":[%'", [$product['id']]);
                break;
            case 'asset':
                // Keep only the rights and provenance fields that differ from the defaults, so
                // a record read with defaults applied and written back is unchanged, and an
                // asset keeps following the node's default rights.
                $model['rights'] = self::withoutDefaults($model['rights'] ?? null, $this->settings->defaultRights());
                $aiGenerated = (bool) ($model['provenance']['ai_generated'] ?? false);
                $model['provenance'] = self::withoutDefaults($model['provenance'] ?? null, [
                    'ai_generated' => false, 'ai_edited' => false, 'source' => $aiGenerated ? 'generated' : 'photo',
                    'c2pa' => 'none', 'c2pa_manifest_url' => null, 'generator' => null,
                ]);
                $product = $this->load('product', (string) $model['product_external_id']);
                if ($product === null) {
                    throw new ApiError('unknown_parent', 'Product "' . $model['product_external_id'] . '" does not exist. Create it first.', [['pointer' => '/product_external_id', 'detail' => 'Unknown product.', 'obx_error' => 'unknown_parent']]);
                }
                $this->checkRestriction($this->brandExternalIdOf('product', $product), $ctx);
                if (empty($ctx['skip_position'])) {
                    $this->checkPosition((string) $product['id'], $model['color_code'], (int) $model['position'], $ext);
                }
                $set['product_id'] = $product['id'];
                $set['color_code'] = $model['color_code'];
                $set['position'] = $model['position'];
                if ($row !== null && $row['product_id'] !== $product['id']) {
                    $touch[] = $row['product_id'];
                }
                $live = Json::decode((string) ($row['files'] ?? ''), []) ?: [];
                $pending = Json::decode((string) ($row['pending'] ?? ''), null);
                if ($files !== null) {
                    [$live, $pending] = $this->applyFiles($live, is_array($pending) ? $pending : null, $files, $warnings);
                }
                $set['files'] = Json::encode(Json::obj($live));
                $set['pending'] = is_array($pending) && $pending !== [] ? Json::encode($pending) : null;
                $set['complete'] = self::complete($live) ? 1 : 0;
                break;
        }
        $status ??= $row !== null ? (string) $row['status'] : $this->settings->defaultStatus();
        $inputJson = Json::encode($model);
        $etag = $this->etag($kind, $model, $status, (string) ($set['files'] ?? ''), (string) ($set['pending'] ?? ''));
        if ($row !== null && $row['etag'] === $etag && $this->sameColumns($row, $set)) {
            return $this->result($ctx, $kind, $ext, 'unchanged', $row['id'], $row, $warnings, 200, 'auto', $details);
        }
        $set['input'] = $inputJson;
        $set['status'] = $status;
        $set['etag'] = $etag;
        $set['rec_updated_at'] = $now;
        if ($kind === 'brand') {
            $set['name'] = $model['name'];
        }
        if ($row === null) {
            $id = Ids::new(self::PREFIX[$kind]);
            $set += [
                'id' => $id,
                'external_id' => $ext,
                'rep' => '{}',
                'rep_hash' => '',
                'visible' => 0,
                'last_seq' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($kind === 'product') {
                $set['content_updated_at'] = $now;
            }
            $this->db->insert(self::TABLES[$kind], $set);
            $result = 'created';
        } else {
            $id = (string) $row['id'];
            $this->db->update(self::TABLES[$kind], $set, ['id' => $id]);
            $result = 'updated';
        }
        $sync[$kind . 's'][] = $id;
        if ($kind === 'brand' || $kind === 'product') {
            $this->addDescendants($kind, $id, $sync);
        }
        $this->sync($sync, $touch);
        $fresh = $this->db->one('SELECT * FROM ' . self::TABLES[$kind] . ' WHERE id = ?', [$id]);
        return $this->result($ctx, $kind, $ext, $result, $id, $fresh, $warnings, $result === 'created' ? 201 : 200, 'auto', $details);
    }

    /** @param array<string, list<mixed>> $sync */
    private function addDescendants(string $kind, string $id, array &$sync): void
    {
        $productIds = $kind === 'brand' ? $this->db->column('SELECT id FROM obx_products WHERE brand_id = ?', [$id]) : [$id];
        foreach ($productIds as $pid) {
            $sync['products'][] = $pid;
        }
        if ($productIds === []) {
            return;
        }
        foreach (array_chunk($productIds, 500) as $chunk) {
            foreach ($this->db->column('SELECT id FROM obx_variants WHERE product_id IN (' . Db::in($chunk) . ')', $chunk) as $vid) {
                $sync['variants'][] = $vid;
            }
            foreach ($this->db->column('SELECT id FROM obx_assets WHERE product_id IN (' . Db::in($chunk) . ')', $chunk) as $aid) {
                $sync['assets'][] = $aid;
            }
        }
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $set */
    private function sameColumns(array $row, array $set): bool
    {
        foreach ($set as $k => $v) {
            if (!array_key_exists($k, $row)) {
                continue;
            }
            $cur = $row[$k];
            if ((string) ($cur ?? "\0null") !== (string) ($v ?? "\0null")) {
                return false;
            }
        }
        return true;
    }

    /**
     * Files semantics (docs/ingest.md §4): server-rendering nodes derive web/square/thumb from the
     * original; client-rendering nodes keep serving the previous complete set until a new one is
     * complete, then swap atomically.
     *
     * @param array<string, array<string, mixed>> $live
     * @param array<string, array<string, mixed>>|null $pending
     * @param array<string, array<string, mixed>> $given
     * @param list<string> $warnings
     * @return array{0: array<string, array<string, mixed>>, 1: ?array<string, array<string, mixed>>}
     */
    private function applyFiles(array $live, ?array $pending, array $given, array &$warnings): array
    {
        $resolved = [];
        foreach ($given as $name => $ref) {
            $blob = $this->blobs->get((string) $ref['sha256']);
            if ($blob === null) {
                throw new ApiError('blob_missing', 'File ' . $ref['sha256'] . ' (' . $name . ') has not been uploaded. Upload it with PUT /blobs/{sha256} first.', [['pointer' => '/files/' . $name . '/sha256', 'detail' => 'Unknown sha256.', 'obx_error' => 'blob_missing']]);
            }
            $ct = (string) $blob['content_type'];
            $w = $blob['width'] !== null ? (int) $blob['width'] : ($ref['width'] ?? null);
            $h = $blob['height'] !== null ? (int) $blob['height'] : ($ref['height'] ?? null);
            if ($name === 'original') {
                if (!in_array($ct, ImageInfo::ACCEPTED, true) && !($ct === 'image/tiff' && $w !== null && $h !== null)) {
                    throw ApiError::validation('/files/original', 'Originals must be JPEG, PNG or WebP.');
                }
            } else {
                if ($ct !== 'image/jpeg') {
                    throw ApiError::validation('/files/' . $name, 'Renditions must be JPEG.');
                }
                $max = $name === 'thumb' ? Renderer::THUMB_MAX : Renderer::WEB_MAX;
                if ($w === null || $h === null || max($w, $h) > $max || ($name === 'square' && $w !== $h)) {
                    throw ApiError::validation('/files/' . $name, $name === 'square' ? 'The square rendition must be square and at most 2000 px.' : 'The ' . $name . ' rendition must be at most ' . $max . ' px on its longest side.');
                }
            }
            if ($w === null || $h === null) {
                throw ApiError::validation('/files/' . $name, 'width and height are required for this file type.');
            }
            $resolved[$name] = ['sha256' => (string) $ref['sha256'], 'width' => $w, 'height' => $h];
        }

        if ($this->serverRenders() && isset($resolved['original'])) {
            $orig = $resolved['original'];
            if (count($resolved) > 1) {
                $warnings[] = 'This node renders web, square and thumb itself; uploaded renditions were ignored.';
            }
            if ($this->noRender) {
                $r = ['web' => $orig, 'square' => $orig, 'thumb' => $orig];
            } else {
                $r = $this->renditions->ensure($orig['sha256']);
            }
            return [self::ordered(['original' => $orig] + $r), null];
        }
        if ($pending !== null && !isset($resolved['original'])) {
            $pending = array_replace($pending, $resolved);
            return self::complete($pending) ? [self::ordered($pending), null] : [$live, self::ordered($pending)];
        }
        if (isset($resolved['original']) && ($live['original']['sha256'] ?? null) !== $resolved['original']['sha256']) {
            $new = $resolved;
        } else {
            $new = array_replace($live, $resolved);
        }
        $new = self::ordered($new);
        if (self::complete($new)) {
            return [$new, null];
        }
        if (self::complete($live)) {
            return [$live, $new];
        }
        return [$new, null];
    }

    /** @param array<string, mixed> $files */
    private static function ordered(array $files): array
    {
        $out = [];
        foreach (Validator::RENDITIONS as $n) {
            if (isset($files[$n])) {
                $out[$n] = $files[$n];
            }
        }
        return $out;
    }

    /** @param array<string, mixed>|null $files */
    public static function complete(?array $files): bool
    {
        return is_array($files) && isset($files['original'], $files['web'], $files['square'], $files['thumb']);
    }

    /** @param array<string, mixed> $files @return array<string, mixed> */
    private static function filesForPatch(array $files): array
    {
        $out = [];
        foreach ($files as $n => $f) {
            $out[$n] = ['sha256' => $f['sha256'], 'width' => $f['width'] ?? null, 'height' => $f['height'] ?? null];
        }
        return $out;
    }

    private function checkPosition(string $productId, ?string $color, int $position, string $ext): void
    {
        $sql = 'SELECT external_id FROM obx_assets WHERE product_id = ? AND position = ? AND external_id <> ? AND ' .
            ($color === null ? 'color_code IS NULL' : 'color_code = ?');
        $params = [$productId, $position, $ext];
        if ($color !== null) {
            $params[] = $color;
        }
        $other = $this->db->one($sql, $params);
        if ($other !== null) {
            throw new ApiError('position_conflict', 'Position ' . $position . ' is already taken by asset "' . $other['external_id'] . '". Reuse that external id to replace the image, or reorder with asset-order.', [['pointer' => '/position', 'detail' => 'Taken by ' . $other['external_id'] . '.', 'obx_error' => 'position_conflict']]);
        }
    }

    /** @return array<string, mixed> */
    private function resolveBrand(?string $brandExternalId): array
    {
        if ($brandExternalId === null) {
            $brands = $this->db->all('SELECT * FROM obx_brands LIMIT 2');
            if (count($brands) === 1) {
                return $brands[0];
            }
            throw new ApiError('validation_failed', 'brand_external_id is required because this node has ' . (count($brands) === 0 ? 'no' : 'more than one') . ' brand.', [['pointer' => '/brand_external_id', 'detail' => 'Required.']]);
        }
        $brand = $this->load('brand', $brandExternalId);
        if ($brand === null) {
            throw new ApiError('unknown_parent', 'Brand "' . $brandExternalId . '" does not exist. Create it first.', [['pointer' => '/brand_external_id', 'detail' => 'Unknown brand.', 'obx_error' => 'unknown_parent']]);
        }
        return $brand;
    }

    /** @param array<string, mixed> $ctx */
    private function checkRestriction(?string $brandExternalId, array $ctx): void
    {
        $allowed = $ctx['brands'] ?? null;
        if ($allowed === null) {
            return;
        }
        if ($brandExternalId === null || !in_array($brandExternalId, $allowed, true)) {
            throw new ApiError('forbidden', 'This token may only write brands: ' . implode(', ', $allowed) . '.');
        }
    }

    /** @param array<string, mixed>|null $row @param array<string, mixed> $ctx */
    private function checkIfMatch(?array $row, array $ctx): void
    {
        $ifMatch = $ctx['if_match'] ?? null;
        if ($ifMatch === null || $ifMatch === '' || $ifMatch === '*' && $row !== null) {
            return;
        }
        $tags = array_map('trim', explode(',', (string) $ifMatch));
        if ($row === null || !in_array($row['etag'], $tags, true) && !in_array('W/' . $row['etag'], $tags, true)) {
            throw new ApiError('precondition_failed', 'The object changed since you read it (If-Match).');
        }
    }

    /** @param array<string, mixed> $row */
    public function brandExternalIdOf(string $kind, array $row): ?string
    {
        return match ($kind) {
            'brand' => (string) $row['external_id'],
            'product' => $this->db->value('SELECT external_id FROM obx_brands WHERE id = ?', [$row['brand_id']]),
            default => $this->db->value('SELECT b.external_id FROM obx_products p JOIN obx_brands b ON b.id = p.brand_id WHERE p.id = ?', [$row['product_id']]),
        };
    }

    /** @return array<string, mixed>|null */
    public function load(string $kind, string $ext): ?array
    {
        return $this->db->one('SELECT * FROM ' . self::TABLES[$kind] . ' WHERE external_id = ?', [$ext]);
    }

    /** @param array<string, mixed> $model */
    private function etag(string $kind, array $model, string $status, string $files, string $pending): string
    {
        return self::etagOf($kind, $model, $status, $files, $pending);
    }

    // ================================================================ reconcile

    /**
     * Recomputes representation and visibility of the given objects (parents that are not in
     * the set are taken as stored) and writes change entries for every visible effect.
     *
     * @param array<string, list<string>> $ids keys brands, products, variants, assets
     * @param list<string> $touchProducts products whose content_updated_at/search must refresh
     */
    public function sync(array $ids, array $touchProducts = []): void
    {
        $now = Clock::iso();
        $nowTs = Clock::time();
        $brands = $this->rows('obx_brands', array_values(array_unique($ids['brands'] ?? [])));
        $products = $this->rows('obx_products', array_values(array_unique($ids['products'] ?? [])));
        $variants = $this->rows('obx_variants', array_values(array_unique($ids['variants'] ?? [])));
        $assets = $this->rows('obx_assets', array_values(array_unique($ids['assets'] ?? [])));

        // Parents needed but not in the set: load them (their stored state is current).
        $needProducts = [];
        foreach (array_merge($variants, $assets) as $r) {
            if (!isset($products[$r['product_id']])) {
                $needProducts[$r['product_id']] = true;
            }
        }
        $parentProducts = $this->rows('obx_products', array_keys($needProducts));
        $allProducts = $products + $parentProducts;
        $needBrands = [];
        foreach ($allProducts as $p) {
            if (!isset($brands[$p['brand_id']])) {
                $needBrands[$p['brand_id']] = true;
            }
        }
        $parentBrands = $this->rows('obx_brands', array_keys($needBrands));
        $allBrands = $brands + $parentBrands;

        $brandVisible = [];
        foreach ($allBrands as $id => $b) {
            $brandVisible[$id] = isset($brands[$id]) ? $b['status'] === 'published' : (int) $b['visible'] === 1;
        }
        $productVisible = [];
        foreach ($allProducts as $id => $p) {
            $productVisible[$id] = isset($products[$id])
                ? $p['status'] === 'published' && ($brandVisible[$p['brand_id']] ?? false)
                : (int) $p['visible'] === 1;
        }

        $touched = array_fill_keys($touchProducts, true);
        $defaults = $this->settings->defaultRights();

        uasort($brands, static fn ($a, $b) => strcmp((string) $a['external_id'], (string) $b['external_id']));
        foreach ($brands as $id => $b) {
            $rep = Representation::brand($b);
            $this->apply('brand', 'obx_brands', $b, $rep, $brandVisible[$id], $id, null, $now);
        }
        uasort($products, static fn ($a, $b) => strcmp((string) $a['external_id'], (string) $b['external_id']));
        foreach ($products as $id => $p) {
            $rep = Representation::product($p);
            if ($this->apply('product', 'obx_products', $p, $rep, $productVisible[$id], (string) $p['brand_id'], null, $now)) {
                $touched[$id] = true;
            }
        }
        uasort($variants, static fn ($a, $b) => strcmp((string) $a['external_id'], (string) $b['external_id']));
        foreach ($variants as $id => $v) {
            $p = $allProducts[$v['product_id']] ?? null;
            if ($p === null) {
                continue;
            }
            $rep = Representation::variant($v, (string) $p['brand_id']);
            $vis = $v['status'] === 'published' && ($productVisible[$v['product_id']] ?? false);
            if ($this->apply('variant', 'obx_variants', $v, $rep, $vis, (string) $p['brand_id'], (string) $v['product_id'], $now)) {
                $touched[$v['product_id']] = true;
            }
        }
        uasort($assets, function ($a, $b) use ($allProducts) {
            $pa = (string) ($allProducts[$a['product_id']]['external_id'] ?? '');
            $pb = (string) ($allProducts[$b['product_id']]['external_id'] ?? '');
            return strcmp($pa, $pb) ?: self::assetOrderCmp($a, $b);
        });
        $variantIdMap = [];
        foreach ($assets as $id => $a) {
            $p = $allProducts[$a['product_id']] ?? null;
            if ($p === null) {
                continue;
            }
            $input = Json::decode((string) $a['input'], []);
            $variantIds = null;
            if (is_array($input['variant_external_ids'] ?? null)) {
                $variantIds = [];
                foreach ($input['variant_external_ids'] as $vext) {
                    if (!array_key_exists($vext, $variantIdMap)) {
                        $variantIdMap[$vext] = $this->db->value('SELECT id FROM obx_variants WHERE external_id = ? AND product_id = ?', [$vext, $a['product_id']]);
                    }
                    if ($variantIdMap[$vext] !== null) {
                        $variantIds[] = (string) $variantIdMap[$vext];
                    }
                }
            }
            $files = Json::decode((string) $a['files'], []) ?: [];
            $blobMeta = [];
            foreach ($files as $name => $f) {
                $blobMeta[$name] = $this->blobs->get((string) $f['sha256']);
            }
            $rights = array_replace($defaults, (array) ($input['rights'] ?? []));
            $expiresTs = isset($rights['expires_at']) ? (int) floor((float) Clock::parse((string) $rights['expires_at'])) : null;
            $complete = self::complete($files) && !in_array(null, $blobMeta, true);
            $rep = $complete ? Representation::asset($a, $input, (string) $p['brand_id'], $variantIds, $files, $blobMeta, $rights) : null;
            $vis = $complete && $a['status'] === 'published' && ($productVisible[$a['product_id']] ?? false)
                && ($expiresTs === null || $expiresTs > $nowTs);
            $extra = ['complete' => $complete ? 1 : 0, 'expires_ts' => $expiresTs];
            if ($this->apply('asset', 'obx_assets', $a, $rep, $vis, (string) $p['brand_id'], (string) $a['product_id'], $now, $extra)) {
                $touched[$a['product_id']] = true;
            }
        }

        foreach (array_keys($touched) as $pid) {
            $this->db->run('UPDATE obx_products SET content_updated_at = ? WHERE id = ?', [$now, $pid]);
        }
        $searchFor = array_unique(array_merge(array_keys($products), array_keys($touched), array_map(static fn ($v) => (string) $v['product_id'], $variants)));
        foreach ($searchFor as $pid) {
            $this->refreshSearch((string) $pid);
        }
    }

    /**
     * Applies new state to one row; returns true when a change entry was written.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $rep
     * @param array<string, mixed> $extra
     */
    private function apply(string $type, string $table, array $row, ?array $rep, bool $visible, ?string $brandId, ?string $productId, string $now, array $extra = []): bool
    {
        $repJson = $rep !== null ? Json::encode($rep) : null;
        $hash = $rep !== null ? hash('sha256', Json::canonical($rep)) : '';
        $wasVisible = (int) $row['visible'] === 1;
        $repChanged = $hash !== (string) $row['rep_hash'];
        $updatedAt = (string) $row['updated_at'];
        if ($repChanged || ($visible && !$wasVisible)) {
            $updatedAt = $now;
        }
        $op = null;
        if ($visible && (!$wasVisible || $repChanged)) {
            $op = 'upsert';
        } elseif (!$visible && $wasVisible) {
            $op = 'delete';
        }
        $set = ['rep' => $repJson ?? ($table === 'obx_assets' ? null : '{}'), 'rep_hash' => $hash, 'visible' => $visible ? 1 : 0, 'updated_at' => $updatedAt] + $extra;
        if ($op !== null) {
            $set['last_seq'] = $this->log->append($type, $op, (string) $row['id'], (string) $row['external_id'], $brandId ?? ($type === 'brand' ? (string) $row['id'] : null), $productId, $op === 'upsert' ? $updatedAt : $now);
        }
        $changed = false;
        foreach ($set as $k => $v) {
            if ((string) ($row[$k] ?? "\0null") !== (string) ($v ?? "\0null")) {
                $changed = true;
                break;
            }
        }
        if ($changed) {
            $this->db->update($table, $set, ['id' => $row['id']]);
        }
        return $op !== null;
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    public static function assetOrderCmp(array $a, array $b): int
    {
        $ca = $a['color_code'];
        $cb = $b['color_code'];
        if ($ca === null && $cb !== null) {
            return -1;
        }
        if ($ca !== null && $cb === null) {
            return 1;
        }
        return strcmp((string) $ca, (string) $cb) ?: ((int) $a['position'] <=> (int) $b['position']) ?: strcmp((string) $a['external_id'], (string) $b['external_id']);
    }

    /**
     * @param list<string> $ids
     * @return array<string, array<string, mixed>>
     */
    private function rows(string $table, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->db->all('SELECT * FROM ' . $table . ' WHERE id IN (' . Db::in($chunk) . ')', $chunk) as $r) {
                $out[(string) $r['id']] = $r;
            }
        }
        return $out;
    }

    private function refreshSearch(string $productId): void
    {
        $p = $this->db->one('SELECT style_number, input FROM obx_products WHERE id = ?', [$productId]);
        if ($p === null) {
            return;
        }
        $input = Json::decode((string) $p['input'], []);
        $parts = [(string) $p['style_number']];
        foreach ((array) ($input['name'] ?? []) as $n) {
            $parts[] = (string) $n;
        }
        foreach ($this->db->all('SELECT input, gtin FROM obx_variants WHERE product_id = ?', [$productId]) as $v) {
            $vi = Json::decode((string) $v['input'], []);
            $parts[] = (string) ($vi['gtin'] ?? '');
            $parts[] = (string) ($v['gtin'] ?? '');
            $parts[] = (string) ($vi['sku'] ?? '');
            $parts[] = (string) ($vi['color']['code'] ?? '');
            foreach ((array) ($vi['color']['name'] ?? []) as $cn) {
                $parts[] = (string) $cn;
            }
        }
        $search = mb_strtolower(implode(' ', array_unique(array_filter($parts, static fn ($s) => $s !== ''))), 'UTF-8');
        $this->db->run('UPDATE obx_products SET search = ? WHERE id = ?', [$search, $productId]);
    }

    /** Re-evaluates every asset (after a change of the default rights). */
    public function resyncAllAssets(): void
    {
        $this->db->tx(function () {
            $ids = $this->db->column('SELECT id FROM obx_assets');
            foreach (array_chunk($ids, 500) as $chunk) {
                $this->sync(['assets' => array_map('strval', $chunk)]);
            }
        });
    }

    /** Writes delete entries for assets whose rights expired. Returns the number handled. */
    public function expireAssets(): int
    {
        $ids = $this->db->column('SELECT id FROM obx_assets WHERE visible = 1 AND expires_ts IS NOT NULL AND expires_ts <= ?', [Clock::time()]);
        if ($ids === []) {
            return 0;
        }
        $this->db->tx(fn () => $this->sync(['assets' => array_map('strval', $ids)]));
        return count($ids);
    }

    // ================================================================ records & results

    /**
     * IngestRecord of a stored object.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function record(string $kind, array $row): array
    {
        $data = Json::decode((string) $row['input'], []);
        $data['status'] = $row['status'];
        $out = [
            'kind' => $kind,
            'id' => $row['id'],
            'external_id' => $row['external_id'],
            'status' => $row['status'],
            'visibility' => $this->visibility($kind, $row),
            'etag' => $row['etag'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['rec_updated_at'],
            'data' => $data,
        ];
        if ($kind === 'asset') {
            $files = Json::decode((string) $row['files'], []) ?: [];
            $data['files'] = Json::obj($files);
            // "The stored write model with defaults applied" (IngestRecord.data):
            // effective rights and provenance, like the protocol Asset shows them.
            $data['rights'] = Representation::rights(array_replace($this->settings->defaultRights(), (array) ($data['rights'] ?? [])));
            $data['provenance'] = Representation::provenance($data);
            $out['data'] = $data;
            $missing = [];
            foreach ($this->requiredFiles() as $n) {
                if (!isset($files[$n])) {
                    $missing[] = $n;
                }
            }
            $out['missing_files'] = $missing;
        }
        return $out;
    }

    /**
     * The fields of $given that differ from $defaults (null when none do).
     *
     * @param array<string, mixed>|null $given
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>|null
     */
    public static function withoutDefaults(?array $given, array $defaults): ?array
    {
        if ($given === null) {
            return null;
        }
        $out = [];
        foreach ($given as $k => $v) {
            if (!array_key_exists($k, $defaults) || $defaults[$k] !== $v) {
                $out[$k] = $v;
            }
        }
        return $out === [] ? null : $out;
    }

    /** @return list<string> */
    public function requiredFiles(): array
    {
        return $this->serverRenders() ? ['original', 'web', 'square', 'thumb'] : ['original', 'web', 'square', 'thumb'];
    }

    /** @param array<string, mixed> $row */
    public function visibility(string $kind, array $row): string
    {
        if ($kind === 'asset' && (int) $row['complete'] !== 1) {
            return 'pending_files';
        }
        if ((int) $row['visible'] === 1 && !($kind === 'asset' && $row['expires_ts'] !== null && (int) $row['expires_ts'] <= Clock::time())) {
            return 'visible';
        }
        return 'hidden';
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed>|null $row
     * @param list<string> $warnings
     * @param list<array{code: string, pointer: ?string, detail: string}> $details warnings with a stable code
     * @return array<string, mixed>
     */
    private function result(array $ctx, string $kind, string $ext, string $result, ?string $id, ?array $row, array $warnings, int $status = 200, ?string $visibility = 'auto', array $details = []): array
    {
        $out = [
            'index' => $ctx['index'] ?? null,
            'op' => $ctx['op'] ?? 'upsert',
            'kind' => $kind,
            'external_id' => $ext,
            'result' => $result,
            'id' => $id,
            'visibility' => $row !== null && $visibility === 'auto' ? $this->visibility($kind, $row) : null,
            'etag' => $row['etag'] ?? null,
            'error' => null,
            'warnings' => array_values(array_unique($warnings)),
            '_status' => $status,
        ];
        if ($details !== []) {
            // IngestResult.warning_details: present only when there are any.
            $out['warning_details'] = $details;
        }
        return $out;
    }

    /** ETag of a stored record (the canonical write model, status and, for assets, files). */
    public static function etagOf(string $kind, array $model, string $status, string $files, string $pending): string
    {
        $record = ['kind' => $kind, 'input' => $model, 'status' => $status];
        if ($kind === 'asset') {
            $record['files'] = Json::decode($files, null);
            $record['pending'] = Json::decode($pending, null);
        }
        return '"' . hash('sha256', Json::canonical($record)) . '"';
    }

    /**
     * Error result for batches and imports.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public static function errorResult(array $ctx, string $kind, string $ext, ApiError $e): array
    {
        return [
            'index' => $ctx['index'] ?? null,
            'op' => $ctx['op'] ?? 'upsert',
            'kind' => $kind,
            'external_id' => $ext,
            'result' => 'error',
            'id' => null,
            'visibility' => null,
            'etag' => null,
            'error' => $e->problem(),
            'warnings' => [],
        ];
    }
}
