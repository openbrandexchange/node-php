<?php

declare(strict_types=1);

namespace Obx\Node\Controllers;

use Obx\Node\Catalog\Writer;
use Obx\Node\Http\ApiError;
use Obx\Node\Http\Request;
use Obx\Node\Http\Response;
use Obx\Node\Support\Json;

/**
 * Admin catalog: the same write semantics as the Ingest API, with the admin session.
 */
final class CatalogAdminController extends Controller
{
    private function admin(Request $req): void
    {
        $this->app->auth->user($req, 'admin');
        $this->app->defer(fn () => $this->app->webhooks->notifyChanges());
    }

    /** @return array<string, mixed> */
    private function ctx(Request $req): array
    {
        return ['brands' => null, 'if_match' => $req->header('if-match')];
    }

    public function brands(Request $req): Response
    {
        $this->admin($req);
        $rows = $this->app->db->all('SELECT * FROM obx_brands ORDER BY name, external_id');
        return Response::json(['items' => array_map(fn ($r) => $this->app->writer->record('brand', $r), $rows)]);
    }

    public function products(Request $req): Response
    {
        $this->admin($req);
        $limit = $this->limit($req, 50, 200);
        $offset = $this->offset($req, 'admin-products');
        $where = [];
        $params = [];
        if (($q = $req->q('q')) !== null && trim($q) !== '') {
            $where[] = 'p.search LIKE ?';
            $params[] = '%' . mb_strtolower(trim($q), 'UTF-8') . '%';
        }
        if (($brand = $req->q('brand')) !== null && $brand !== '') {
            $where[] = 'b.external_id = ?';
            $params[] = $brand;
        }
        if (($st = $req->q('status')) !== null && in_array($st, ['published', 'unpublished'], true)) {
            $where[] = 'p.status = ?';
            $params[] = $st;
        }
        if (($vis = $req->q('visibility')) !== null) {
            if ($vis === 'visible') {
                $where[] = 'p.visible = 1';
            } elseif ($vis === 'hidden') {
                $where[] = 'p.visible = 0';
            } elseif ($vis === 'pending_files') {
                $where[] = 'EXISTS (SELECT 1 FROM obx_assets a WHERE a.product_id = p.id AND a.complete = 0)';
            }
        }
        $order = match ($req->q('sort')) {
            'style_number' => 'p.style_number, p.id',
            'name' => 'p.search, p.id',
            default => 'p.rec_updated_at DESC, p.id',
        };
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $this->app->db->value('SELECT COUNT(*) FROM obx_products p JOIN obx_brands b ON b.id = p.brand_id' . $w, $params);
        $rows = $this->app->db->all('SELECT p.*, b.external_id AS brand_ext FROM obx_products p JOIN obx_brands b ON b.id = p.brand_id' . $w . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset, $params);
        $items = [];
        $admin = $this->app->auth->session($req)['user'];
        foreach ($rows as $r) {
            $counts = $this->app->db->one('SELECT
                (SELECT COUNT(*) FROM obx_variants WHERE product_id = ?) AS variants,
                (SELECT COUNT(*) FROM obx_assets WHERE product_id = ?) AS assets,
                (SELECT COUNT(*) FROM obx_assets WHERE product_id = ? AND complete = 0) AS pending', [$r['id'], $r['id'], $r['id']]);
            $first = $this->app->db->all('SELECT * FROM obx_assets WHERE product_id = ?', [$r['id']]);
            usort($first, [Writer::class, 'assetOrderCmp']);
            $thumb = null;
            foreach ($first as $a) {
                $files = Json::decode((string) $a['files'], []);
                if (isset($files['thumb'])) {
                    [$thumb] = $this->app->reader->renditionUrl((string) $a['id'], 'thumb', 'a:' . $admin['id']);
                    break;
                }
            }
            $input = Json::decode((string) $r['input'], []);
            $items[] = [
                'id' => $r['id'],
                'external_id' => $r['external_id'],
                'brand_external_id' => $r['brand_ext'],
                'style_number' => $r['style_number'],
                'name' => $input['name'] ?? [],
                'status' => $r['status'],
                'visibility' => (int) $r['visible'] === 1 ? 'visible' : 'hidden',
                'variant_count' => (int) $counts['variants'],
                'asset_count' => (int) $counts['assets'],
                'pending_asset_count' => (int) $counts['pending'],
                'thumb_url' => $thumb,
                'updated_at' => $r['rec_updated_at'],
            ];
        }
        return Response::json(['items' => $items, 'next_cursor' => $this->nextCursor('admin-products', $offset, count($rows), $limit, $total), 'total' => $total]);
    }

    public function product(Request $req): Response
    {
        $this->admin($req);
        $w = $this->app->writer;
        $p = $w->load('product', trim((string) $req->params['external_id']));
        if ($p === null) {
            throw new ApiError('not_found', 'No such product.');
        }
        $admin = $this->app->auth->session($req)['user'];
        $variants = $this->app->db->all('SELECT * FROM obx_variants WHERE product_id = ?', [$p['id']]);
        usort($variants, static fn ($a, $b) => strcmp((string) $a['external_id'], (string) $b['external_id']));
        $assets = $this->app->db->all('SELECT * FROM obx_assets WHERE product_id = ?', [$p['id']]);
        usort($assets, [Writer::class, 'assetOrderCmp']);
        $out = [];
        foreach ($assets as $a) {
            $renditions = [];
            foreach (Json::decode((string) $a['files'], []) as $name => $f) {
                $blob = $this->app->blobs->get((string) $f['sha256']);
                if ($blob === null) {
                    continue;
                }
                [$url, $exp] = $this->app->reader->renditionUrl((string) $a['id'], (string) $name, 'a:' . $admin['id']);
                $renditions[$name] = [
                    'name' => $name,
                    'url' => $url,
                    'url_expires_at' => $exp,
                    'content_type' => $blob['content_type'],
                    'width' => (int) ($f['width'] ?? $blob['width']),
                    'height' => (int) ($f['height'] ?? $blob['height']),
                    'bytes' => (int) $blob['bytes'],
                    'sha256' => $blob['sha256'],
                ];
            }
            $out[] = ['record' => $w->record('asset', $a), 'renditions' => Json::obj($renditions)];
        }
        return Response::json([
            'record' => $w->record('product', $p),
            'variants' => array_map(fn ($v) => $w->record('variant', $v), $variants),
            'assets' => $out,
        ]);
    }

    public function put(Request $req, string $kind): Response
    {
        $this->admin($req);
        return $this->writeResponse($this->app->writer->upsert($kind, (string) $req->params['external_id'], $req->json(), $this->ctx($req)));
    }

    public function patch(Request $req, string $kind): Response
    {
        $this->admin($req);
        $patch = $req->json();
        $ctx = $this->ctx($req);
        if (is_array($patch) && array_keys($patch) === ['status']) {
            $ctx['op'] = $patch['status'] === 'published' ? 'publish' : 'unpublish';
        }
        return $this->writeResponse($this->app->writer->patch($kind, (string) $req->params['external_id'], $patch, $ctx));
    }

    public function delete(Request $req, string $kind): Response
    {
        $this->admin($req);
        return $this->writeResponse($this->app->writer->remove($kind, (string) $req->params['external_id'], $this->ctx($req)));
    }

    public function assetOrder(Request $req): Response
    {
        $this->admin($req);
        $body = $req->jsonObject();
        if (!array_key_exists('color_code', $body) || !is_array($body['asset_external_ids'] ?? null)) {
            throw ApiError::validation('/asset_external_ids', 'Send color_code (or null) and asset_external_ids.');
        }
        $results = $this->app->writer->assetOrder((string) $req->params['external_id'], $body['color_code'] !== null ? (string) $body['color_code'] : null, $body['asset_external_ids'], $this->ctx($req));
        return Response::json(array_map(static function ($r) {
            unset($r['_status']);
            return $r;
        }, $results));
    }

    public function putFile(Request $req): Response
    {
        $this->admin($req);
        return (new IngestController($this->app))->putFileWith($req, $this->ctx($req));
    }

    public function headBlob(Request $req): Response
    {
        $this->app->auth->user($req, 'admin');
        return (new IngestController($this->app))->blobHead((string) $req->params['sha256']);
    }

    public function putBlob(Request $req): Response
    {
        $this->admin($req);
        return (new IngestController($this->app))->blobPut($req);
    }
}
