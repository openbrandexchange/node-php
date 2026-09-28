<?php

declare(strict_types=1);

namespace Obx\Node\Controllers;

use Obx\Node\Catalog\Viewer;
use Obx\Node\Catalog\Writer;
use Obx\Node\Db\Db;
use Obx\Node\Http\ApiError;
use Obx\Node\Http\Request;
use Obx\Node\Http\Response;
use Obx\Node\Services\Downloads;
use Obx\Node\Services\GtinLookup;
use Obx\Node\Services\Retailers;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Json;
use Obx\Node\Support\Str;

/**
 * The retailer portal ("<Brand> · Beeldbank"). Admins may call it too and see a preview of
 * everything published; their downloads are not counted.
 */
final class PortalController extends Controller
{
    private const FILTERS = ['brand_id', 'item_type', 'color', 'season', 'collection', 'target_group'];

    /** @return array{0: array<string, mixed>, 1: Viewer, 2: ?array<string, mixed>} session, viewer, retailer */
    private function viewer(Request $req): array
    {
        $s = $this->app->auth->user($req);
        if ($s['user']['role'] === 'admin') {
            return [$s, Viewer::preview(true, 'v:' . $s['user']['id']), null];
        }
        $v = $this->app->grants->portalViewer($s['retailer'], (string) $s['user']['id'], (string) $s['user']['name']);
        return [$s, $v, $s['retailer']];
    }

    // ================================================================ viewer & browse

    public function viewerResponse(Request $req): Response
    {
        [$s, $v, $retailer] = $this->viewer($req);
        $sel = $this->selectionIds((string) $s['user']['id'], $v);
        return Response::json([
            'preview' => $v->preview,
            'retailer' => $retailer !== null ? ['id' => $retailer['id'], 'name' => $retailer['name']] : null,
            'brands' => array_map(fn ($r) => $this->app->reader->brand($r), $this->app->reader->brandRows($v)),
            'permissions' => [
                'original_download' => $v->originalAllowed,
                'article_data_export' => $v->preview || (bool) $this->app->settings->get('portal', 'allow_article_data_export'),
                'api_self_service' => !$v->preview && (bool) $this->app->settings->get('portal', 'retailer_api_self_service'),
            ],
            'branding' => (new SessionController($this->app))->brandingData(),
            'languages' => $this->app->settings->languages(),
            'selection_count' => count($sel),
            'limits' => ['selection_max_products' => 1000, 'sync_zip_max_images' => Downloads::SYNC_ZIP_MAX, 'gtin_lookup_max' => 2000],
        ]);
    }

    /**
     * WHERE clause for the portal filters, optionally leaving one facet out.
     *
     * @param array<string, mixed> $f
     * @return array{0: string, 1: list<mixed>}|null null when nothing can match
     */
    private function where(array $f, Viewer $v, ?string $except = null): ?array
    {
        $brandIds = $this->app->reader->brandIds($v);
        if ($brandIds === []) {
            return null;
        }
        $where = ['p.visible = 1', 'p.brand_id IN (' . Db::in($brandIds) . ')'];
        $params = $brandIds;
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = 'p.search LIKE ?';
            $params[] = '%' . mb_strtolower($q, 'UTF-8') . '%';
        }
        $cols = ['brand_id' => 'p.brand_id', 'item_type' => 'p.item_type', 'season' => 'p.season', 'collection' => 'p.collection', 'target_group' => 'p.target_group'];
        foreach ($cols as $key => $col) {
            $vals = array_values(array_filter((array) ($f[$key] ?? []), static fn ($x) => is_string($x) && $x !== ''));
            if ($vals === [] || $key === $except) {
                continue;
            }
            $where[] = $col . ' IN (' . Db::in($vals) . ')';
            $params = array_merge($params, $vals);
        }
        $colors = array_values(array_filter((array) ($f['color'] ?? []), static fn ($x) => is_string($x) && $x !== ''));
        if ($colors !== [] && $except !== 'color') {
            $where[] = 'EXISTS (SELECT 1 FROM obx_variants cv WHERE cv.product_id = p.id AND cv.visible = 1 AND cv.color_code IN (' . Db::in($colors) . '))';
            $params = array_merge($params, $colors);
        }
        $since = $f['updated_since'] ?? null;
        if (is_string($since) && $since !== '') {
            $ts = Clock::parse($since);
            if ($ts === null) {
                throw new ApiError('invalid_request', 'updated_since must be a date-time.');
            }
            $where[] = 'p.content_updated_at >= ?';
            $params[] = Clock::iso($ts);
        }
        return [implode(' AND ', $where), $params];
    }

    /** @return array<string, mixed> */
    private function filterFromQuery(Request $req): array
    {
        $f = ['q' => $req->q('q'), 'updated_since' => $req->q('updated_since')];
        foreach (self::FILTERS as $k) {
            $f[$k] = $req->qList($k);
        }
        return $f;
    }

    /** @param array<string, mixed> $f @return list<string> */
    private function filteredIds(array $f, Viewer $v, int $max = 100000): array
    {
        $w = $this->where($f, $v);
        if ($w === null) {
            return [];
        }
        return array_map('strval', $this->app->db->column('SELECT p.id FROM obx_products p WHERE ' . $w[0] . ' ORDER BY p.style_number, p.id LIMIT ' . $max, $w[1]));
    }

    public function products(Request $req): Response
    {
        [, $v] = $this->viewer($req);
        $f = $this->filterFromQuery($req);
        $limit = $this->limit($req, 50, 200);
        $offset = $this->offset($req, 'portal');
        $w = $this->where($f, $v);
        if ($w === null) {
            return Response::json(['items' => [], 'next_cursor' => null, 'total' => 0]);
        }
        $total = (int) $this->app->db->value('SELECT COUNT(*) FROM obx_products p WHERE ' . $w[0], $w[1]);
        $order = $req->q('sort') === 'newest' ? 'p.content_updated_at DESC, p.id' : 'p.style_number, p.id';
        $rows = $this->app->db->all('SELECT p.* FROM obx_products p WHERE ' . $w[0] . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset, $w[1]);
        return Response::json([
            'items' => $this->summaries($rows, $v),
            'next_cursor' => $this->nextCursor('portal', $offset, count($rows), $limit, $total),
            'total' => $total,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function summaries(array $rows, Viewer $v): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn ($r) => (string) $r['id'], $rows);
        $in = Db::in($ids);
        $variants = [];
        foreach ($this->app->db->all('SELECT product_id, color_code, rep FROM obx_variants WHERE visible = 1 AND product_id IN (' . $in . ') ORDER BY external_id', $ids) as $r) {
            $variants[$r['product_id']][] = $r;
        }
        $assets = [];
        foreach ($this->app->db->all('SELECT * FROM obx_assets WHERE visible = 1 AND (expires_ts IS NULL OR expires_ts > ?) AND product_id IN (' . $in . ')', array_merge([Clock::time()], $ids)) as $r) {
            $assets[$r['product_id']][] = $r;
        }
        $brands = [];
        foreach ($this->app->db->all('SELECT id, name FROM obx_brands') as $b) {
            $brands[$b['id']] = $b['name'];
        }
        $out = [];
        foreach ($rows as $r) {
            $rep = Json::decode((string) $r['rep'], []);
            $as = $assets[$r['id']] ?? [];
            usort($as, [Writer::class, 'assetOrderCmp']);
            $thumb = null;
            $colorThumbs = [];
            foreach ($as as $a) {
                [$url] = $this->app->reader->renditionUrl((string) $a['id'], 'thumb', $v->actor);
                $thumb ??= $url;
                $c = (string) ($a['color_code'] ?? '');
                $colorThumbs[$c] ??= $url;
            }
            $colors = [];
            foreach ($variants[$r['id']] ?? [] as $var) {
                $code = (string) $var['color_code'];
                if (!isset($colors[$code])) {
                    $vr = Json::decode((string) $var['rep'], []);
                    $colors[$code] = ['code' => $code, 'name' => $vr['color']['name'] ?? null, 'thumb_url' => $colorThumbs[$code] ?? null];
                }
            }
            ksort($colors, SORT_STRING);
            $out[] = [
                'id' => $r['id'],
                'style_number' => $r['style_number'],
                'name' => $rep['name'] ?? [],
                'brand' => ['id' => $r['brand_id'], 'name' => $brands[$r['brand_id']] ?? ''],
                'item_type' => $rep['item_type'] ?? null,
                'target_group' => $rep['target_group'] ?? null,
                'season' => $rep['season'] ?? null,
                'collection' => $rep['collection'] ?? null,
                'thumb_url' => $thumb,
                'colors' => array_values($colors),
                'image_count' => count($as),
                'variant_count' => count($variants[$r['id']] ?? []),
                'updated_at' => $r['content_updated_at'],
            ];
        }
        return $out;
    }

    public function facets(Request $req): Response
    {
        [, $v] = $this->viewer($req);
        $f = $this->filterFromQuery($req);
        $out = ['brands' => [], 'item_types' => [], 'colors' => [], 'seasons' => [], 'collections' => [], 'target_groups' => []];
        $cols = ['brands' => ['brand_id', 'p.brand_id'], 'item_types' => ['item_type', 'p.item_type'], 'seasons' => ['season', 'p.season'], 'collections' => ['collection', 'p.collection'], 'target_groups' => ['target_group', 'p.target_group']];
        $brandNames = [];
        foreach ($this->app->db->all('SELECT id, name FROM obx_brands') as $b) {
            $brandNames[$b['id']] = $b['name'];
        }
        foreach ($cols as $facet => [$key, $col]) {
            $w = $this->where($f, $v, $key);
            if ($w === null) {
                continue;
            }
            $rows = $this->app->db->all('SELECT ' . $col . ' AS value, COUNT(*) AS n FROM obx_products p WHERE ' . $w[0] . ' AND ' . $col . ' IS NOT NULL GROUP BY ' . $col . ' ORDER BY ' . $col, $w[1]);
            foreach ($rows as $r) {
                $item = ['value' => (string) $r['value'], 'count' => (int) $r['n']];
                if ($facet === 'brands') {
                    $item['label'] = $brandNames[$r['value']] ?? (string) $r['value'];
                }
                $out[$facet][] = $item;
            }
            if ($facet === 'brands') {
                usort($out['brands'], static fn ($a, $b) => strcasecmp((string) $a['label'], (string) $b['label']));
            }
        }
        $w = $this->where($f, $v, 'color');
        if ($w !== null) {
            $rows = $this->app->db->all('SELECT v.color_code AS value, COUNT(DISTINCT p.id) AS n, MIN(v.rep) AS rep FROM obx_products p JOIN obx_variants v ON v.product_id = p.id AND v.visible = 1 WHERE ' . $w[0] . ' AND v.color_code IS NOT NULL GROUP BY v.color_code ORDER BY v.color_code', $w[1]);
            foreach ($rows as $r) {
                $rep = Json::decode((string) $r['rep'], []);
                $item = ['value' => (string) $r['value'], 'count' => (int) $r['n']];
                if (!empty($rep['color']['name'])) {
                    $item['label'] = $rep['color']['name'];
                }
                $out['colors'][] = $item;
            }
        }
        return Response::json($out);
    }

    public function product(Request $req): Response
    {
        [, $v] = $this->viewer($req);
        $d = $this->app->reader->detail((string) $req->params['id'], $v);
        if ($d === null) {
            throw new ApiError('not_found', 'This product is not available.');
        }
        return Response::json($d);
    }

    public function gtinLookup(Request $req): Response
    {
        [, $v] = $this->viewer($req);
        $in = $req->jsonObject();
        $codes = [];
        if (isset($in['gtins'])) {
            if (!is_array($in['gtins']) || !array_is_list($in['gtins'])) {
                throw ApiError::validation('/gtins', 'A list of codes.');
            }
            $codes = array_map('strval', $in['gtins']);
        } elseif (isset($in['text'])) {
            if (!is_string($in['text']) || strlen($in['text']) > 100000) {
                throw ApiError::validation('/text', 'Paste up to 100000 characters.');
            }
            $codes = array_values(array_filter(preg_split('/[^0-9]+/', $in['text']) ?: [], static fn ($c) => $c !== ''));
        }
        if (count($codes) > 2000) {
            throw new ApiError('limit_exceeded', 'Paste at most 2000 EANs at once.');
        }
        return Response::json((new GtinLookup($this->app))->lookup($codes, $v));
    }

    // ================================================================ selection

    /** @return list<string> visible selected ids */
    private function selectionIds(string $userId, Viewer $v): array
    {
        $raw = $this->app->db->value('SELECT product_ids FROM obx_selections WHERE user_id = ?', [$userId]);
        $ids = Json::decode(is_string($raw) ? $raw : null, []);
        if (!is_array($ids) || $ids === []) {
            return [];
        }
        $brandIds = $this->app->reader->brandIds($v);
        if ($brandIds === []) {
            return [];
        }
        $visible = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->app->db->column('SELECT id FROM obx_products WHERE visible = 1 AND id IN (' . Db::in($chunk) . ') AND brand_id IN (' . Db::in($brandIds) . ')', array_merge($chunk, $brandIds)) as $id) {
                $visible[(string) $id] = true;
            }
        }
        return array_values(array_filter($ids, static fn ($id) => isset($visible[$id])));
    }

    private function selectionJson(string $userId, Viewer $v): array
    {
        $ids = $this->selectionIds($userId, $v);
        $count = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $count += (int) $this->app->db->value('SELECT COUNT(*) FROM obx_assets WHERE visible = 1 AND (expires_ts IS NULL OR expires_ts > ?) AND product_id IN (' . Db::in($chunk) . ')', array_merge([Clock::time()], $chunk));
        }
        $updated = $this->app->db->value('SELECT updated_at FROM obx_selections WHERE user_id = ?', [$userId]);
        return ['product_ids' => $ids, 'image_count' => $count, 'updated_at' => $updated];
    }

    public function selection(Request $req): Response
    {
        [$s, $v] = $this->viewer($req);
        return Response::json($this->selectionJson((string) $s['user']['id'], $v));
    }

    public function putSelection(Request $req): Response
    {
        [$s, $v] = $this->viewer($req);
        $in = $req->jsonObject();
        $ids = $in['product_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids)) {
            throw new ApiError('invalid_request', 'Send {"product_ids": [...]}.');
        }
        if (count($ids) > 1000) {
            throw new ApiError('limit_exceeded', 'A selection holds at most 1000 products.');
        }
        $ids = array_values(array_unique(array_map('strval', $ids)));
        $this->app->db->delete('obx_selections', ['user_id' => $s['user']['id']]);
        $this->app->db->insert('obx_selections', ['user_id' => $s['user']['id'], 'product_ids' => Json::encode($ids), 'updated_at' => Clock::iso()]);
        $visible = $this->selectionIds((string) $s['user']['id'], $v);
        if (count($visible) !== count($ids)) {
            $this->app->db->update('obx_selections', ['product_ids' => Json::encode($visible)], ['user_id' => $s['user']['id']]);
        }
        return Response::json($this->selectionJson((string) $s['user']['id'], $v));
    }

    public function clearSelection(Request $req): Response
    {
        [$s] = $this->viewer($req);
        $this->app->db->delete('obx_selections', ['user_id' => $s['user']['id']]);
        return Response::empty(204);
    }

    // ================================================================ downloads

    /** @param array<string, mixed> $s @param array<string, mixed> $extra */
    private function log(array $s, ?array $retailer, Viewer $v, string $kind, array $extra): void
    {
        if ($v->preview || $retailer === null) {
            return;
        }
        $this->app->stats->record($kind, $retailer, 'user', (string) $s['user']['id'], (string) $s['user']['name'], $extra);
    }

    public function assetDownload(Request $req): Response
    {
        [$s, $v, $retailer] = $this->viewer($req);
        $row = $this->app->reader->visibleAssetRow((string) $req->params['id'], $v);
        if ($row === null) {
            throw new ApiError('not_found', 'This image is not available.');
        }
        $variant = Downloads::defaultVariant($req->q('variant'), $v);
        $f = $this->app->downloads->file($row, $variant);
        $res = $this->serveFile($req, $f['path'], $f['type'], $f['sha'], 'attachment', $f['name'], 'private, no-store');
        if ($res->status === 200 || ($res->status === 206 && $res->fileOffset === 0)) {
            $this->log($s, $retailer, $v, 'image', ['variant' => $variant, 'asset_id' => $row['id'], 'product_id' => $row['product_id'], 'files' => 1, 'bytes' => $f['bytes']]);
        }
        return $res;
    }

    public function productZip(Request $req): Response
    {
        [$s, $v, $retailer] = $this->viewer($req);
        $row = $this->app->reader->visibleProductRow((string) $req->params['id'], $v);
        if ($row === null) {
            throw new ApiError('not_found', 'This product is not available.');
        }
        $variant = Downloads::defaultVariant($req->q('variant'), $v);
        $assets = $this->app->downloads->assetsOf([(string) $row['id']], $v);
        $this->log($s, $retailer, $v, 'product_zip', ['variant' => $variant, 'product_id' => $row['id'], 'files' => count($assets)]);
        return $this->app->downloads->zipResponse($assets, $variant, false, Str::safeFilename((string) $row['style_number']) . ($variant !== 'original' ? '_' . $variant : '') . '.zip');
    }

    public function selectionZip(Request $req): Response
    {
        [$s, $v, $retailer] = $this->viewer($req);
        $variant = Downloads::defaultVariant($req->q('variant'), $v);
        $ids = $this->selectionIds((string) $s['user']['id'], $v);
        $assets = $this->app->downloads->assetsOf($ids, $v);
        if (count($assets) > Downloads::SYNC_ZIP_MAX) {
            throw new ApiError('limit_exceeded', 'Your selection has ' . count($assets) . ' images; direct downloads are limited to ' . Downloads::SYNC_ZIP_MAX . '. Start a background download instead.');
        }
        $this->log($s, $retailer, $v, 'selection_zip', ['variant' => $variant, 'files' => count($assets)]);
        return $this->app->downloads->zipResponse($assets, $variant, true, 'selection' . ($variant !== 'original' ? '_' . $variant : '') . '.zip');
    }

    private function csvAllowed(Viewer $v): void
    {
        if (!$v->preview && !$this->app->settings->get('portal', 'allow_article_data_export')) {
            throw new ApiError('forbidden', 'The brand does not offer article data downloads.');
        }
    }

    private function delimiter(Request $req): string
    {
        return $req->q('delimiter') === 'semicolon' ? ';' : ',';
    }

    private function csvResponse(string $csv, string $name): Response
    {
        $res = Response::text($csv, 'text/csv; charset=utf-8');
        $res->header('Content-Disposition', Str::contentDisposition('attachment', $name));
        $res->header('Cache-Control', 'private, no-store');
        return $res;
    }

    public function selectionCsv(Request $req): Response
    {
        [$s, $v, $retailer] = $this->viewer($req);
        $this->csvAllowed($v);
        $ids = $this->selectionIds((string) $s['user']['id'], $v);
        $this->log($s, $retailer, $v, 'csv', ['files' => 0]);
        return $this->csvResponse($this->app->downloads->csv($ids, $v, $this->delimiter($req)), 'selection.csv');
    }

    public function filterCsv(Request $req): Response
    {
        [$s, $v, $retailer] = $this->viewer($req);
        $this->csvAllowed($v);
        $ids = $this->filteredIds($this->filterFromQuery($req), $v);
        $this->log($s, $retailer, $v, 'csv', ['files' => 0]);
        return $this->csvResponse($this->app->downloads->csv($ids, $v, $this->delimiter($req)), 'products.csv');
    }

    public function downloadJobs(Request $req): Response
    {
        [$s] = $this->viewer($req);
        $rows = $this->app->db->all('SELECT * FROM obx_download_jobs WHERE user_id = ? AND created_ts > ? ORDER BY created_at DESC', [$s['user']['id'], Clock::time() - 7 * 86400]);
        return Response::json(['items' => array_map(fn ($d) => $this->app->downloads->json($d), $rows)]);
    }

    public function createDownloadJob(Request $req): Response
    {
        [$s, $v, $retailer] = $this->viewer($req);
        $in = $req->jsonObject();
        $source = $in['source'] ?? null;
        if (!in_array($source, ['selection', 'products', 'filter'], true)) {
            throw ApiError::validation('/source', 'selection, products or filter.');
        }
        $variant = $in['variant'] ?? null;
        if (!in_array($variant, ['original', 'web', 'square'], true)) {
            throw ApiError::validation('/variant', 'original, web or square.');
        }
        Downloads::renditionFor($variant, $v);
        $ids = match ($source) {
            'selection' => $this->selectionIds((string) $s['user']['id'], $v),
            'products' => $this->visibleOf(is_array($in['product_ids'] ?? null) ? array_map('strval', $in['product_ids']) : [], $v),
            'filter' => $this->filteredIds(is_array($in['filter'] ?? null) ? $in['filter'] : [], $v),
        };
        if (count($ids) > 10000) {
            throw new ApiError('limit_exceeded', 'At most 10000 products per download.');
        }
        if ($ids === []) {
            throw ApiError::validation('/source', 'There are no products to download.');
        }
        $includeCsv = ($in['include_data_csv'] ?? true) !== false;
        $job = $this->app->downloads->createJob($s['user'], $retailer['id'] ?? null, $source, $variant, $includeCsv, $ids);
        return Response::json($job, 202);
    }

    /** @param list<string> $ids @return list<string> */
    private function visibleOf(array $ids, Viewer $v): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $brandIds = $this->app->reader->brandIds($v);
        if ($brandIds === []) {
            return [];
        }
        $ok = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->app->db->column('SELECT id FROM obx_products WHERE visible = 1 AND id IN (' . Db::in($chunk) . ') AND brand_id IN (' . Db::in($brandIds) . ')', array_merge($chunk, $brandIds)) as $id) {
                $ok[(string) $id] = true;
            }
        }
        return array_values(array_filter($ids, static fn ($i) => isset($ok[$i])));
    }

    /** @return array<string, mixed> */
    private function downloadRow(Request $req, array $s): array
    {
        $d = $this->app->db->one('SELECT * FROM obx_download_jobs WHERE id = ? AND user_id = ?', [$req->params['id'], $s['user']['id']]);
        if ($d === null) {
            throw new ApiError('not_found', 'No such download.');
        }
        return $d;
    }

    public function downloadJob(Request $req): Response
    {
        [$s] = $this->viewer($req);
        return Response::json($this->app->downloads->json($this->downloadRow($req, $s)));
    }

    public function downloadJobFile(Request $req): Response
    {
        [$s, $v, $retailer] = $this->viewer($req);
        $d = $this->downloadRow($req, $s);
        $json = $this->app->downloads->json($d);
        if ($json['status'] === 'expired') {
            throw new ApiError('export_expired', 'This download has expired. Start it again.');
        }
        if ($json['status'] !== 'succeeded') {
            throw new ApiError('not_found', 'The download is not ready yet.');
        }
        $path = $this->app->downloads->path((string) $d['id']);
        if (!is_file($path)) {
            throw new ApiError('export_expired', 'This download has expired. Start it again.');
        }
        $res = $this->serveFile($req, $path, 'application/zip', hash('sha256', (string) $d['id'] . ':' . filesize($path)), 'attachment', 'download-' . substr((string) $d['created_at'], 0, 10) . '.zip', 'private, no-store');
        // The ETag identifies this job's file; it is not a content hash, so do not claim one.
        unset($res->headers['Obx-Content-Sha256']);
        if ($res->status === 200 || ($res->status === 206 && $res->fileOffset === 0)) {
            $this->log($s, $retailer, $v, 'bulk_zip', ['variant' => $d['variant'], 'files' => (int) $d['files'], 'bytes' => (int) $d['bytes']]);
        }
        return $res;
    }

    // ================================================================ self-service API keys

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function retailerOnly(Request $req): array
    {
        $s = $this->app->auth->user($req, 'retailer');
        return [$s, $s['retailer']];
    }

    public function apiClients(Request $req): Response
    {
        [, $retailer] = $this->retailerOnly($req);
        $rows = $this->app->db->all('SELECT * FROM obx_api_clients WHERE retailer_id = ? ORDER BY created_at', [$retailer['id']]);
        return Response::json(['items' => array_map([Retailers::class, 'clientJson'], $rows)]);
    }

    public function createApiClient(Request $req): Response
    {
        [, $retailer] = $this->retailerOnly($req);
        if (!$this->app->settings->get('portal', 'retailer_api_self_service')) {
            throw new ApiError('forbidden', 'Ask the brand for an API key for your software.');
        }
        $active = (int) $this->app->db->value("SELECT COUNT(*) FROM obx_api_clients WHERE retailer_id = ? AND status = 'active'", [$retailer['id']]);
        if ($active >= 5) {
            throw new ApiError('conflict', 'You can have at most 5 active API keys. Revoke one first.');
        }
        $in = $req->jsonObject();
        $scopes = isset($in['scopes']) && is_array($in['scopes']) ? array_values(array_map('strval', $in['scopes'])) : null;
        $r = new Retailers($this->app);
        return Response::json($r->withSecret($r->createClient((string) $retailer['id'], (string) ($in['name'] ?? ''), $scopes)), 201);
    }

    public function rotateApiClient(Request $req): Response
    {
        [, $retailer] = $this->retailerOnly($req);
        $r = new Retailers($this->app);
        return Response::json($r->withSecret($r->rotateClient((string) $req->params['id'], (string) $retailer['id'])));
    }

    public function revokeApiClient(Request $req): Response
    {
        [, $retailer] = $this->retailerOnly($req);
        (new Retailers($this->app))->revokeClient((string) $req->params['id'], (string) $retailer['id']);
        return Response::empty(204);
    }
}
