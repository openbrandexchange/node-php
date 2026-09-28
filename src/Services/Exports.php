<?php

declare(strict_types=1);

namespace Obx\Node\Services;

use Obx\Node\App;
use Obx\Node\Catalog\Viewer;
use Obx\Node\Config;
use Obx\Node\Db\Db;
use Obx\Node\Http\ApiError;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Gtin;
use Obx\Node\Support\Ids;
use Obx\Node\Support\Json;
use Obx\Node\Support\Str;
use Obx\Node\Zip\ZipWriter;

/**
 * Bulk exports (protocol capability "exports"): an NDJSON manifest plus independent zip parts.
 */
final class Exports
{
    public const KEEP_SECONDS = 48 * 3600;

    public function __construct(private App $app)
    {
    }

    private function dir(string $id): string
    {
        return $this->app->config->dataDir() . '/exports/' . $id;
    }

    /**
     * @param array{client: array<string, mixed>, retailer: array<string, mixed>, scopes: list<string>} $a
     * @param array<string, mixed> $body
     * @return array<string, mixed> Export
     */
    public function create(array $a, array $body, ?string $idemKey): array
    {
        $db = $this->app->db;
        $clientId = (string) $a['client']['id'];
        if ($idemKey !== null && $idemKey !== '') {
            $prev = $db->one('SELECT * FROM obx_exports WHERE client_id = ? AND idem_key = ? AND created_ts > ?', [$clientId, $idemKey, Clock::time() - 86400]);
            if ($prev !== null) {
                return $this->json($prev);
            }
        }
        $renditions = $body['renditions'] ?? null;
        if (!is_array($renditions) || !array_is_list($renditions) || array_diff($renditions, ['original', 'web', 'square', 'thumb']) !== []) {
            throw ApiError::validation('/renditions', 'A list of renditions (original, web, square, thumb); [] for data only.');
        }
        $renditions = array_values(array_unique($renditions));
        if (in_array('original', $renditions, true) && !in_array('media:original', $a['scopes'], true)) {
            throw new ApiError('original_not_allowed', 'Originals are not available to this client.');
        }
        if ($renditions !== [] && !in_array('media:read', $a['scopes'], true)) {
            throw new ApiError('insufficient_scope', 'Exporting files needs the media:read scope.');
        }
        $filter = $body['filter'] ?? [];
        if (!is_array($filter)) {
            throw ApiError::validation('/filter', 'Must be an object.');
        }
        foreach (['brand_ids', 'product_ids', 'gtins'] as $k) {
            if (isset($filter[$k]) && (!is_array($filter[$k]) || !array_is_list($filter[$k]) || count($filter[$k]) > 10000)) {
                throw ApiError::validation('/filter/' . $k, 'A list (at most 10000).');
            }
        }
        if (isset($filter['updated_since']) && Clock::parse((string) $filter['updated_since']) === null) {
            throw ApiError::validation('/filter/updated_since', 'An RFC 3339 date-time.');
        }
        $running = $db->one("SELECT id FROM obx_exports WHERE client_id = ? AND status IN ('queued', 'running')", [$clientId]);
        if ($running !== null) {
            throw new ApiError('conflict', 'Export ' . $running['id'] . ' of this client is still running.');
        }
        $row = [
            'id' => Ids::new('exp'),
            'client_id' => $clientId,
            'retailer_id' => $a['retailer']['id'],
            'status' => 'queued',
            'request' => Json::encode([
                'filter' => $filter,
                'renditions' => $renditions,
                'include_data' => ($body['include_data'] ?? true) !== false,
                'scopes' => $a['scopes'],
            ]),
            'idem_key' => $idemKey,
            'parts' => '[]',
            'counts' => null,
            'error' => null,
            'progress_done' => 0,
            'progress_total' => null,
            'created_at' => Clock::iso(),
            'created_ts' => Clock::time(),
            'finished_at' => null,
            'expires_at' => null,
            'expires_ts' => null,
        ];
        $db->insert('obx_exports', $row);
        $this->app->jobs->create('export', 'client', $clientId, ['export_id' => $row['id']]);
        return $this->json($row);
    }

    /** @param array{client: array<string, mixed>, retailer: array<string, mixed>} $a @return array<string, mixed> */
    public function get(array $a, string $id): array
    {
        $row = $this->app->db->one('SELECT * FROM obx_exports WHERE id = ? AND retailer_id = ?', [$id, $a['retailer']['id']]);
        if ($row === null) {
            throw new ApiError('not_found', 'No such export.');
        }
        return $this->json($row);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function json(array $row): array
    {
        $status = $row['status'];
        if ($status === 'succeeded' && $row['expires_ts'] !== null && (int) $row['expires_ts'] < Clock::time()) {
            $status = 'expired';
        }
        $parts = [];
        $manifest = null;
        if ($status === 'succeeded') {
            $actor = 'c:' . $row['client_id'];
            [$manifest] = $this->app->reader->signedPath('/obx/v1/exports/' . $row['id'] . '/manifest', $actor);
            foreach (Json::decode((string) $row['parts'], []) as $p) {
                [$url] = $this->app->reader->signedPath('/obx/v1/exports/' . $row['id'] . '/parts/' . $p['number'], $actor);
                $parts[] = ['number' => $p['number'], 'url' => $url, 'bytes' => $p['bytes'], 'sha256' => $p['sha256'], 'files' => $p['files']];
            }
        }
        return [
            'id' => $row['id'],
            'status' => $status,
            'created_at' => $row['created_at'],
            'finished_at' => $row['finished_at'],
            'expires_at' => $row['expires_at'],
            'progress' => ['done' => (int) $row['progress_done'], 'total' => $row['progress_total'] !== null ? (int) $row['progress_total'] : null],
            'manifest_url' => $manifest,
            'parts' => $parts,
            'error' => $row['error'] !== null ? Json::decode((string) $row['error']) : null,
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $payload
     */
    public function build(array $job, array $payload): ?array
    {
        $db = $this->app->db;
        $id = (string) $payload['export_id'];
        $row = $db->one('SELECT * FROM obx_exports WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        $db->update('obx_exports', ['status' => 'running'], ['id' => $id]);
        $req = Json::decode((string) $row['request'], []);
        $client = $db->one('SELECT * FROM obx_api_clients WHERE id = ?', [$row['client_id']]);
        $retailer = $db->one('SELECT * FROM obx_retailers WHERE id = ?', [$row['retailer_id']]);
        if ($client === null || $retailer === null || $client['status'] !== 'active' || $retailer['status'] !== 'active') {
            throw new ApiError('access_revoked', 'Access for this client was revoked.');
        }
        $scopes = array_values(array_intersect((array) $req['scopes'], $this->app->grants->scopes($retailer)));
        $v = $this->app->grants->apiViewer($retailer, $scopes, (string) $client['id'], (string) $client['name']);
        $hw = $this->app->changes->latestSeq();
        $productIds = $this->select((array) ($req['filter'] ?? []), $v);
        $db->update('obx_exports', ['progress_total' => count($productIds)], ['id' => $id]);
        $dir = $this->dir($id);
        @mkdir($dir, 0770, true);
        $renditions = (array) $req['renditions'];
        if (in_array('original', $renditions, true) && !$v->originalAllowed) {
            throw new ApiError('original_not_allowed', 'Originals are not available to this client.');
        }
        $includeData = (bool) ($req['include_data'] ?? true);
        $body = fopen($dir . '/body.ndjson', 'wb');
        $fileLines = fopen($dir . '/files.ndjson', 'wb');
        $counts = ['products' => 0, 'variants' => 0, 'assets' => 0, 'files' => 0];
        $partLimit = max(1024 * 1024, (int) $this->app->config->get('export_part_bytes'));
        $parts = [];
        $partNo = 0;
        $zip = null;
        $zipFh = null;
        $zipFiles = 0;
        $closePart = function () use (&$zip, &$zipFh, &$parts, &$partNo, &$zipFiles, $dir): void {
            if ($zip === null) {
                return;
            }
            $zip->finish();
            fclose($zipFh);
            $path = $dir . '/part-' . $partNo . '.zip';
            $parts[] = ['number' => $partNo, 'bytes' => (int) filesize($path), 'sha256' => (string) hash_file('sha256', $path), 'files' => $zipFiles];
            $zip = null;
            $zipFh = null;
        };
        if ($includeData) {
            $brandIds = $this->app->reader->brandIds($v);
            $want = isset($req['filter']['brand_ids']) && is_array($req['filter']['brand_ids']) ? array_intersect($brandIds, $req['filter']['brand_ids']) : $brandIds;
            foreach ($this->app->reader->brandRows($v) as $b) {
                if (in_array($b['id'], $want, true)) {
                    fwrite($body, Json::encode(['type' => 'brand', 'data' => $this->app->reader->brand($b)]) . "\n");
                }
            }
        }
        $done = 0;
        foreach (array_chunk($productIds, 100) as $chunk) {
            $rows = $db->all('SELECT * FROM obx_products WHERE id IN (' . Db::in($chunk) . ') ORDER BY id', $chunk);
            foreach ($this->app->reader->details($rows, $v) as $d) {
                $variants = $d['variants'];
                $assets = $d['assets'];
                unset($d['variants'], $d['assets']);
                $counts['products']++;
                $counts['variants'] += count($variants);
                $counts['assets'] += count($assets);
                if ($includeData) {
                    fwrite($body, Json::encode(['type' => 'product', 'data' => $d]) . "\n");
                    foreach ($variants as $var) {
                        fwrite($body, Json::encode(['type' => 'variant', 'data' => $var]) . "\n");
                    }
                    foreach ($assets as $as) {
                        fwrite($body, Json::encode(['type' => 'asset', 'data' => $as]) . "\n");
                    }
                }
                foreach ($assets as $as) {
                    foreach ($renditions as $rn) {
                        $r = $as['renditions'][$rn] ?? null;
                        $path = $r !== null ? $this->app->blobs->path((string) $r['sha256']) : null;
                        if ($path === null) {
                            continue;
                        }
                        $size = (int) $r['bytes'];
                        if ($zip !== null && $zip->bytes() + $size + 1024 > $partLimit && $zipFiles > 0) {
                            $closePart();
                        }
                        if ($zip === null) {
                            $partNo++;
                            $zipFh = fopen($dir . '/part-' . $partNo . '.zip', 'wb');
                            $zip = new ZipWriter($zipFh);
                            $zipFiles = 0;
                        }
                        $entry = $zip->uniqueName(Str::safeFilename((string) $d['style_number']) . '/' . $rn . '/' . Str::renditionFilename((string) $as['filename'], $rn));
                        $zip->addFile($entry, $path);
                        $zipFiles++;
                        $counts['files']++;
                        fwrite($fileLines, Json::encode([
                            'type' => 'file', 'asset_id' => $as['id'], 'rendition' => $rn, 'part' => $partNo,
                            'path' => $entry, 'bytes' => $size, 'sha256' => $r['sha256'],
                        ]) . "\n");
                    }
                }
                $done++;
            }
            $db->update('obx_exports', ['progress_done' => $done], ['id' => $id]);
            $this->app->jobs->progress((string) $job['id'], $done, count($productIds));
        }
        $closePart();
        fclose($body);
        fclose($fileLines);
        $manifest = fopen($dir . '/manifest.ndjson', 'wb');
        fwrite($manifest, Json::encode([
            'type' => 'header',
            'export_id' => $id,
            'created_at' => $row['created_at'],
            'obx_version' => Config::OBX_VERSION,
            'changes_cursor' => $this->app->feed->liveCursor($hw),
            'counts' => $counts,
        ]) . "\n");
        foreach ([$dir . '/body.ndjson', $dir . '/files.ndjson'] as $f) {
            $in = fopen($f, 'rb');
            stream_copy_to_stream($in, $manifest);
            fclose($in);
            @unlink($f);
        }
        fclose($manifest);
        $expires = Clock::time() + self::KEEP_SECONDS;
        $db->update('obx_exports', [
            'status' => 'succeeded',
            'finished_at' => Clock::iso(),
            'expires_at' => Clock::iso((float) $expires),
            'expires_ts' => $expires,
            'parts' => Json::encode($parts),
            'counts' => Json::encode($counts),
            'progress_done' => count($productIds),
        ], ['id' => $id]);
        $this->app->webhooks->enqueue('client', (string) $row['client_id'], 'export.completed', ['export_id' => $id, 'status' => 'succeeded']);
        return $counts;
    }

    /**
     * Product ids matching the export filter, visible to $v.
     *
     * @param array<string, mixed> $filter
     * @return list<string>
     */
    private function select(array $filter, Viewer $v): array
    {
        $brandIds = $this->app->reader->brandIds($v);
        if (isset($filter['brand_ids']) && is_array($filter['brand_ids'])) {
            $brandIds = array_values(array_intersect($brandIds, array_map('strval', $filter['brand_ids'])));
        }
        if ($brandIds === []) {
            return [];
        }
        $where = ['p.visible = 1', 'p.brand_id IN (' . Db::in($brandIds) . ')'];
        $params = $brandIds;
        if (isset($filter['product_ids']) && is_array($filter['product_ids'])) {
            $ids = array_values(array_map('strval', $filter['product_ids']));
            if ($ids === []) {
                return [];
            }
            $where[] = 'p.id IN (' . Db::in($ids) . ')';
            $params = array_merge($params, $ids);
        }
        if (isset($filter['gtins']) && is_array($filter['gtins'])) {
            $g = array_values(array_filter(array_map(static fn ($x) => Gtin::normalizeLookup((string) $x), $filter['gtins'])));
            if ($g === []) {
                return [];
            }
            $where[] = 'p.id IN (SELECT v.product_id FROM obx_variants v WHERE v.visible = 1 AND v.gtin IN (' . Db::in($g) . '))';
            $params = array_merge($params, $g);
        }
        if (isset($filter['updated_since'])) {
            $where[] = 'p.content_updated_at >= ?';
            $params[] = Clock::iso((float) Clock::parse((string) $filter['updated_since']));
        }
        return array_map('strval', $this->app->db->column('SELECT p.id FROM obx_products p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id', $params));
    }

    /** @param array<string, mixed> $row */
    public function manifestPath(array $row): string
    {
        $p = $this->dir((string) $row['id']) . '/manifest.ndjson';
        if (!is_file($p)) {
            throw new ApiError('export_expired', 'The export files are gone. Start a new export.');
        }
        return $p;
    }

    /** @param array<string, mixed> $row @return array{0: string, 1: string} */
    public function partPath(array $row, int $n): array
    {
        foreach (Json::decode((string) $row['parts'], []) as $p) {
            if ((int) $p['number'] === $n) {
                $path = $this->dir((string) $row['id']) . '/part-' . $n . '.zip';
                if (!is_file($path)) {
                    throw new ApiError('export_expired', 'The export files are gone. Start a new export.');
                }
                return [$path, (string) $p['sha256']];
            }
        }
        throw new ApiError('not_found', 'No such part.');
    }

    /** @param array<string, mixed> $job @param array<string, mixed> $problem */
    public function fail(array $job, array $problem): void
    {
        $payload = Json::decode((string) $job['payload'], []);
        $id = (string) ($payload['export_id'] ?? '');
        $this->app->db->update('obx_exports', ['status' => 'failed', 'finished_at' => Clock::iso(), 'error' => Json::encode($problem)], ['id' => $id]);
        $row = $this->app->db->one('SELECT client_id FROM obx_exports WHERE id = ?', [$id]);
        if ($row !== null) {
            $this->app->webhooks->enqueue('client', (string) $row['client_id'], 'export.completed', ['export_id' => $id, 'status' => 'failed']);
        }
    }

    public function expire(): void
    {
        foreach ($this->app->db->all("SELECT id FROM obx_exports WHERE status = 'succeeded' AND expires_ts < ?", [Clock::time()]) as $e) {
            $dir = $this->dir((string) $e['id']);
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
            $this->app->db->update('obx_exports', ['status' => 'expired'], ['id' => $e['id']]);
        }
    }
}
