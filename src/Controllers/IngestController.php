<?php

declare(strict_types=1);

namespace Obx\Node\Controllers;

use Obx\Node\Http\ApiError;
use Obx\Node\Http\Request;
use Obx\Node\Http\Response;
use Obx\Node\Images\ImageInfo;
use Obx\Node\Ingest\Batch;
use Obx\Node\Ingest\Uploads;
use Obx\Node\Support\Json;

/**
 * OBX Ingest API: the brand's own automation publishes here with an ingest token.
 */
final class IngestController extends Controller
{
    /** @return array<string, mixed> token row */
    private function token(Request $req, ?string $scope): array
    {
        $t = $this->app->auth->ingest($req, $scope);
        $this->app->defer(fn () => $this->app->webhooks->notifyChanges());
        return $t;
    }

    /** @param array<string, mixed> $t @return array<string, mixed> */
    private function ctx(array $t, Request $req): array
    {
        return [
            'brands' => $t['brand_external_ids'],
            'scopes' => $t['scopes'],
            'if_match' => $req->header('if-match'),
        ];
    }

    public function info(Request $req): Response
    {
        $t = $this->token($req, null);
        $server = $this->app->renditions->serverGenerated();
        $brands = [];
        foreach ($this->app->db->all('SELECT external_id, name FROM obx_brands ORDER BY external_id') as $b) {
            if ($t['brand_external_ids'] === null || in_array($b['external_id'], $t['brand_external_ids'], true)) {
                $brands[] = ['external_id' => $b['external_id'], 'name' => $b['name']];
            }
        }
        return Response::json([
            'token' => [
                'id' => $t['id'],
                'name' => $t['name'],
                'scopes' => $t['scopes'],
                'brand_external_ids' => $t['brand_external_ids'],
                'expires_at' => $t['expires_at'],
            ],
            'limits' => [
                'max_upload_bytes' => max(26214400, (int) $this->app->config->get('max_upload_bytes')),
                'max_object_bytes' => Uploads::MAX_OBJECT_BYTES,
                'part_bytes' => (int) $this->app->config->get('part_bytes'),
                'max_batch_operations' => Batch::MAX_OPERATIONS,
                'max_import_bytes' => 524288000,
            ],
            'renditions' => [
                'server_generated' => $server,
                'required' => $server ? ['original'] : ['original', 'web', 'square', 'thumb'],
            ],
            'accepted_content_types' => ImageInfo::ACCEPTED,
            'default_status' => $this->app->settings->defaultStatus(),
            'brands' => $brands,
            'languages' => $this->app->settings->languages(),
        ]);
    }

    public function get(Request $req, string $kind): Response
    {
        $this->token($req, 'ingest:read');
        $row = $this->app->writer->load($kind, trim((string) $req->params['external_id']));
        if ($row === null) {
            throw new ApiError('not_found', 'No ' . $kind . ' with this external id.');
        }
        $rec = $this->app->writer->record($kind, $row);
        return Response::json($rec)->header('ETag', (string) $row['etag']);
    }

    public function put(Request $req, string $kind): Response
    {
        $t = $this->token($req, 'ingest:write');
        $this->requireJson($req);
        return $this->writeResponse($this->app->writer->upsert($kind, (string) $req->params['external_id'], $req->json(), $this->ctx($t, $req)));
    }

    public function patch(Request $req, string $kind): Response
    {
        $t = $this->token($req, 'ingest:write');
        $this->requireJson($req, true);
        $patch = $req->json();
        $ctx = $this->ctx($t, $req);
        if (is_array($patch) && array_keys($patch) === ['status'] && in_array($patch['status'], ['published', 'unpublished'], true)) {
            $ctx['op'] = $patch['status'] === 'published' ? 'publish' : 'unpublish';
        }
        return $this->writeResponse($this->app->writer->patch($kind, (string) $req->params['external_id'], $patch, $ctx));
    }

    public function delete(Request $req, string $kind): Response
    {
        $t = $this->token($req, 'ingest:delete');
        return $this->writeResponse($this->app->writer->remove($kind, (string) $req->params['external_id'], $this->ctx($t, $req)));
    }

    private function requireJson(Request $req, bool $patch = false): void
    {
        $ct = $req->contentType();
        $ok = $ct === '' || $ct === 'application/json' || $ct === 'application/merge-patch+json' || str_ends_with($ct, '+json');
        if (!$ok) {
            throw new ApiError('unsupported_media_type', 'Send JSON (' . ($patch ? 'application/merge-patch+json' : 'application/json') . ').');
        }
    }

    public function assetOrder(Request $req): Response
    {
        $t = $this->token($req, 'ingest:write');
        $body = $req->jsonObject();
        if (!array_key_exists('color_code', $body) || !is_array($body['asset_external_ids'] ?? null)) {
            throw new ApiError('validation_failed', 'Send color_code (or null) and asset_external_ids.', [['pointer' => '/asset_external_ids', 'detail' => 'Required.']]);
        }
        $color = $body['color_code'] !== null ? (string) $body['color_code'] : null;
        $results = $this->app->writer->assetOrder((string) $req->params['external_id'], $color, $body['asset_external_ids'], $this->ctx($t, $req));
        return Response::json(array_map(static function ($r) {
            unset($r['_status']);
            return $r;
        }, $results));
    }

    /** Stores an uploaded body as a blob after checking the declared hash. @return array<string, mixed> */
    public function storeBody(Request $req, ?string $expectedSha, array $accepted = ImageInfo::ACCEPTED): array
    {
        $max = max(26214400, (int) $this->app->config->get('max_upload_bytes'));
        $tmp = $req->bodyToFile($this->app->blobs->tmpDir(), $max);
        $declared = $req->header('obx-content-sha256');
        if ($declared !== null && $declared !== '' && !hash_equals(strtolower($declared), $tmp['sha256'])) {
            @unlink($tmp['path']);
            throw new ApiError('checksum_mismatch', 'The uploaded bytes do not match OBX-Content-SHA256.');
        }
        if ($expectedSha !== null && !hash_equals($expectedSha, $tmp['sha256'])) {
            @unlink($tmp['path']);
            throw new ApiError('checksum_mismatch', 'The uploaded bytes do not match the sha256 in the URL.');
        }
        return $this->app->blobs->store($tmp['path'], $tmp['sha256'], $tmp['bytes'], $accepted);
    }

    public function putFile(Request $req): Response
    {
        $t = $this->token($req, 'ingest:write');
        return $this->putFileWith($req, $this->ctx($t, $req));
    }

    /** @param array<string, mixed> $ctx */
    public function putFileWith(Request $req, array $ctx): Response
    {
        $rendition = (string) $req->params['rendition'];
        if (!in_array($rendition, ['original', 'web', 'square', 'thumb'], true)) {
            throw new ApiError('not_found', 'Unknown rendition; use original, web, square or thumb.');
        }
        $ext = trim((string) $req->params['external_id']);
        if ($this->app->writer->load('asset', $ext) === null) {
            throw new ApiError('not_found', 'Create the asset metadata first (PUT /assets/' . $ext . ').');
        }
        $blob = $this->storeBody($req, null, $rendition === 'original' ? ImageInfo::ACCEPTED : ['image/jpeg']);
        return $this->writeResponse($this->app->writer->putFile($ext, $rendition, $blob['sha256'], $ctx));
    }

    public function headBlob(Request $req): Response
    {
        $this->token($req, 'ingest:read');
        return $this->blobHead((string) $req->params['sha256']);
    }

    public function blobHead(string $sha): Response
    {
        $b = preg_match('/^[a-f0-9]{64}$/', $sha) ? $this->app->blobs->get($sha) : null;
        if ($b === null) {
            return new Response(404);
        }
        return (new Response(200))->header('Content-Type', (string) $b['content_type'])->header('OBX-Content-SHA256', $sha)->header('Content-Length', (string) $b['bytes']);
    }

    public function putBlob(Request $req): Response
    {
        $this->token($req, 'ingest:write');
        return $this->blobPut($req);
    }

    public function blobPut(Request $req): Response
    {
        $sha = (string) $req->params['sha256'];
        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
            throw ApiError::validation('/sha256', 'A lowercase hex sha256.');
        }
        $existing = $this->app->blobs->get($sha);
        if ($existing !== null && ($req->contentLength() ?? 1) === 0) {
            return Response::json(\Obx\Node\Services\Blobs::describe($existing, false), 200);
        }
        // Even when the node has these bytes, a sent body must match the hash in the URL.
        $blob = $this->storeBody($req, $sha);
        return Response::json($blob, $blob['created'] ? 201 : 200);
    }

    private function owner(array $t): string
    {
        return 'ingest:' . $t['id'];
    }

    public function createUpload(Request $req): Response
    {
        $t = $this->token($req, 'ingest:write');
        [$status, $body] = (new Uploads($this->app))->create($req->jsonObject(), $this->owner($t));
        return Response::json($body, $status);
    }

    public function getUpload(Request $req): Response
    {
        $t = $this->token($req, 'ingest:read');
        $u = new Uploads($this->app);
        return Response::json($u->json($u->get((string) $req->params['id'], $this->owner($t))));
    }

    public function abortUpload(Request $req): Response
    {
        $t = $this->token($req, 'ingest:write');
        (new Uploads($this->app))->abort((string) $req->params['id'], $this->owner($t));
        return Response::empty(204);
    }

    public function putPart(Request $req): Response
    {
        $t = $this->token($req, 'ingest:write');
        $n = (string) $req->params['number'];
        if (!ctype_digit($n)) {
            throw new ApiError('not_found', 'Unknown part.');
        }
        return Response::json((new Uploads($this->app))->putPart((string) $req->params['id'], $this->owner($t), (int) $n, $req));
    }

    public function completeUpload(Request $req): Response
    {
        $t = $this->token($req, 'ingest:write');
        return Response::json((new Uploads($this->app))->complete((string) $req->params['id'], $this->owner($t)));
    }

    // ---------------------------------------------------------------- batch & imports

    public function batch(Request $req): Response
    {
        $t = $this->token($req, 'ingest:write');
        $key = $req->header('idempotency-key');
        $scopeKey = 'batch:' . $t['id'] . ':' . $key;
        if ($key !== null && $key !== '') {
            $prev = $this->app->db->one('SELECT * FROM obx_idempotency WHERE k = ? AND created_ts > ?', [hash('sha256', $scopeKey), time() - 86400]);
            if ($prev !== null) {
                return Response::json(Json::decode((string) $prev['response']), (int) $prev['status']);
            }
        }
        $ops = Batch::parse($req->body(64 * 1024 * 1024), $req->contentType());
        if (count($ops) > Batch::MAX_OPERATIONS) {
            throw new ApiError('limit_exceeded', 'At most ' . Batch::MAX_OPERATIONS . ' operations per batch.');
        }
        $ctx = ['brands' => $t['brand_external_ids'], 'scopes' => $t['scopes']];
        $async = str_contains(strtolower((string) $req->header('prefer')), 'respond-async');
        if ($async) {
            $job = $this->app->jobs->create('batch', 'ingest_token', (string) $t['id'], ['ops' => $ops, 'ctx' => $ctx], $key, count($ops));
            $body = $this->app->jobs->ingestJson($job);
            $status = 202;
            $res = Response::json($body, 202)->header('Location', $this->app->baseUrl() . '/obx/v1/ingest/jobs/' . $job['id']);
        } else {
            @set_time_limit(0);
            [$results, $summary] = (new Batch($this->app))->execute($ops, $ctx);
            $body = ['results' => $results, 'summary' => $summary];
            $status = 200;
            $res = Response::json($body, 200);
        }
        if ($key !== null && $key !== '') {
            try {
                $this->app->db->insert('obx_idempotency', ['k' => hash('sha256', $scopeKey), 'status' => $status, 'response' => Json::encode($body), 'created_ts' => time()]);
            } catch (\PDOException) {
            }
        }
        return $res;
    }

    public function import(Request $req): Response
    {
        $t = $this->token($req, 'ingest:write');
        $key = $req->header('idempotency-key');
        if ($key !== null && $key !== '') {
            $prev = $this->app->jobs->byIdempotencyKey('import', 'ingest_token', (string) $t['id'], $key);
            if ($prev !== null) {
                return Response::json($this->app->jobs->ingestJson($prev), 202);
            }
        }
        $options = $this->app->importer->parseOptions($req->post['options'] ?? null);
        if ($options['mode'] === 'mirror' && !in_array('ingest:delete', $t['scopes'], true)) {
            throw new ApiError('insufficient_scope', 'Mirror imports unpublish products and need the ingest:delete scope.');
        }
        $job = $this->app->importer->queueFromRequest($req, $options, $t['brand_external_ids'], 'api', 'ingest_token', (string) $t['id'], $key);
        return Response::json($this->app->jobs->ingestJson($job), 202)->header('Location', $this->app->baseUrl() . '/obx/v1/ingest/jobs/' . $job['id']);
    }

    public function jobs(Request $req): Response
    {
        $this->token($req, 'ingest:read');
        $limit = $this->limit($req, 25, 100);
        $offset = $this->offset($req, 'jobs');
        $status = $req->q('status');
        $sql = "SELECT * FROM obx_jobs WHERE kind IN ('batch', 'import')";
        $params = [];
        if ($status !== null && in_array($status, ['queued', 'running', 'succeeded', 'failed'], true)) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        $rows = $this->app->db->all($sql . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $params);
        return Response::json([
            'items' => array_map(fn ($r) => $this->app->jobs->ingestJson($r), $rows),
            'next_cursor' => $this->nextCursor('jobs', $offset, count($rows), $limit),
        ]);
    }

    public function job(Request $req): Response
    {
        $this->token($req, 'ingest:read');
        $job = $this->app->jobs->require((string) $req->params['id']);
        if (!in_array($job['kind'], ['batch', 'import'], true)) {
            throw new ApiError('not_found', 'No such job.');
        }
        return Response::json($this->app->jobs->ingestJson($job));
    }

    public function jobResults(Request $req): Response
    {
        $this->token($req, 'ingest:read');
        $job = $this->app->jobs->require((string) $req->params['id']);
        $limit = $this->limit($req, 100, 1000);
        $offset = $this->offset($req, 'results');
        $errorsOnly = in_array(strtolower((string) $req->q('errors_only')), ['1', 'true', 'yes'], true);
        if ($job['kind'] === 'import') {
            $run = $this->app->db->one('SELECT id FROM obx_import_runs WHERE job_id = ?', [$job['id']]);
            $page = $this->app->importer->errors((string) ($run['id'] ?? ''), $offset, $limit, $errorsOnly);
            return Response::json(['items' => $page['items'], 'next_cursor' => $page['next'] !== null ? $this->app->crypto->seal('list:results', ['o' => $page['next']]) : null]);
        }
        $page = $this->app->jobs->results((string) $job['id'], $errorsOnly, $offset, $limit);
        return Response::json(['items' => $page['items'], 'next_cursor' => $page['next'] !== null ? $this->app->crypto->seal('list:results', ['o' => $page['next']]) : null]);
    }
}
