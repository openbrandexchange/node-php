<?php

declare(strict_types=1);

namespace Obx\Node\Controllers;

use Obx\Node\Catalog\Viewer;
use Obx\Node\Db\Db;
use Obx\Node\Http\ApiError;
use Obx\Node\Http\Request;
use Obx\Node\Http\Response;
use Obx\Node\Security\Crypto;
use Obx\Node\Services\GtinLookup;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Json;
use Obx\Node\Support\Str;

/**
 * OBX Protocol (read side) for retailers' software.
 */
final class ProtocolController extends Controller
{
    // ================================================================ access

    public function createAccessRequest(Request $req): Response
    {
        $this->app->limiter->hit('access-request:' . $this->app->clientIp($req), 5, 3600);
        if (!$this->app->settings->get('portal', 'access_requests_enabled')) {
            throw new ApiError('forbidden', 'This brand does not accept access requests.');
        }
        $input = $req->json(200000);
        $lang = \Obx\Node\Support\Lang::negotiate($req->header('accept-language'));
        $ar = $this->app->accessRequests->create($input, $lang);
        return Response::json($ar, 202);
    }

    public function getAccessRequest(Request $req): Response
    {
        $token = $req->bearer();
        if ($token === null) {
            throw new ApiError('unauthorized', 'Send the request_token as Bearer token.');
        }
        $this->app->limiter->hit('access-poll:' . hash('sha256', $token), 10, 60);
        return Response::json($this->app->accessRequests->poll((string) $req->params['id'], $token));
    }

    public function token(Request $req): Response
    {
        $this->app->limiter->hit('token:' . $this->app->clientIp($req), 30, 60);
        $form = $req->post;
        if ($form === [] && $req->contentType() === 'application/x-www-form-urlencoded') {
            parse_str($req->body(65536), $form);
        }
        $id = null;
        $secret = null;
        $auth = $req->header('authorization');
        if ($auth !== null && preg_match('/^Basic\s+(\S+)$/i', trim($auth), $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);
                $id = urldecode($id);
                $secret = urldecode($secret);
            }
        } elseif (isset($form['client_id'], $form['client_secret'])) {
            $id = (string) $form['client_id'];
            $secret = (string) $form['client_secret'];
        }
        if (($form['grant_type'] ?? null) !== 'client_credentials') {
            return self::oauthError('unsupported_grant_type', 'Use grant_type=client_credentials.', 400);
        }
        if ($id === null || $secret === null) {
            return self::oauthError('invalid_client', 'Client authentication failed.', 401);
        }
        $db = $this->app->db;
        $client = $db->one('SELECT * FROM obx_api_clients WHERE client_id = ?', [$id]);
        $retailer = $client !== null ? $db->one('SELECT * FROM obx_retailers WHERE id = ?', [$client['retailer_id']]) : null;
        $ok = $client !== null && hash_equals((string) $client['secret_hash'], Crypto::hashToken($secret));
        if (!$ok || $client['status'] !== 'active' || $retailer === null || $retailer['status'] !== 'active') {
            return self::oauthError('invalid_client', 'Client authentication failed.', 401);
        }
        $allowed = array_values(array_intersect(Json::decode((string) $client['scopes'], []), $this->app->grants->scopes($retailer)));
        $requested = isset($form['scope']) && trim((string) $form['scope']) !== '' ? array_values(array_unique(preg_split('/\s+/', trim((string) $form['scope'])))) : $allowed;
        if (array_diff($requested, $allowed) !== []) {
            return self::oauthError('invalid_scope', 'Requested scope is not available to this client: ' . implode(' ', array_diff($requested, $allowed)) . '.', 400);
        }
        $token = Crypto::token('obxt_', 32);
        $db->insert('obx_access_tokens', [
            'token_hash' => Crypto::hashToken($token),
            'client_id' => $client['id'],
            'scopes' => Json::encode($requested),
            'expires_ts' => Clock::time() + 3600,
            'created_ts' => Clock::time(),
        ]);
        $db->update('obx_api_clients', ['last_used_at' => Clock::iso()], ['id' => $client['id']]);
        $res = Response::json(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => implode(' ', $requested)]);
        $res->header('Cache-Control', 'no-store');
        $res->header('Pragma', 'no-cache');
        return $res;
    }

    private static function oauthError(string $code, string $description, int $status): Response
    {
        $res = Response::json(['error' => $code, 'error_description' => $description], $status);
        if ($status === 401) {
            $res->header('WWW-Authenticate', 'Basic realm="obx"');
        }
        $res->header('Cache-Control', 'no-store');
        return $res;
    }

    public function grant(Request $req): Response
    {
        $a = $this->app->auth->apiClient($req, null);
        return Response::json($this->app->grants->grant($a['retailer']));
    }

    // ================================================================ catalog

    /** @param array{client: array<string, mixed>, retailer: array<string, mixed>, scopes: list<string>} $a */
    private function viewer(array $a): Viewer
    {
        return $this->app->grants->apiViewer($a['retailer'], $a['scopes'], (string) $a['client']['id'], (string) $a['client']['name']);
    }

    public function changes(Request $req): Response
    {
        $a = $this->app->auth->apiClient($req, 'catalog:read');
        $limit = $this->limit($req, 100, 500);
        $page = $this->app->feed->page($req->q('cursor'), $limit, $this->viewer($a), $a['retailer']);
        return Response::json($page);
    }

    public function brands(Request $req): Response
    {
        $a = $this->app->auth->apiClient($req, 'catalog:read');
        $items = array_map(fn ($r) => $this->app->reader->brand($r), $this->app->reader->brandRows($this->viewer($a)));
        return Response::json(['items' => $items]);
    }

    public function brand(Request $req): Response
    {
        $a = $this->app->auth->apiClient($req, 'catalog:read');
        foreach ($this->app->reader->brandRows($this->viewer($a)) as $r) {
            if ($r['id'] === $req->params['id']) {
                return Response::json($this->app->reader->brand($r));
            }
        }
        throw new ApiError('not_found', 'No such brand.');
    }

    public function products(Request $req): Response
    {
        $a = $this->app->auth->apiClient($req, 'catalog:read');
        $v = $this->viewer($a);
        $limit = $this->limit($req, 25, 100);
        $brandIds = $this->app->reader->brandIds($v);
        $ids = $req->qList('ids');
        if (count($ids) > 100) {
            throw new ApiError('limit_exceeded', 'At most 100 ids per request.');
        }
        $where = ['p.visible = 1'];
        $params = [];
        if ($brandIds === []) {
            return Response::json(['items' => [], 'next_cursor' => null]);
        }
        $where[] = 'p.brand_id IN (' . Db::in($brandIds) . ')';
        $params = array_merge($params, $brandIds);
        if ($ids !== []) {
            $where[] = 'p.id IN (' . Db::in($ids) . ')';
            $params = array_merge($params, $ids);
        } else {
            $brand = $req->q('brand_id');
            if ($brand !== null && $brand !== '') {
                $where[] = 'p.brand_id = ?';
                $params[] = $brand;
            }
            $since = $req->q('updated_since');
            if ($since !== null && $since !== '') {
                $ts = Clock::parse($since);
                if ($ts === null) {
                    throw new ApiError('invalid_request', 'updated_since must be an RFC 3339 date-time.');
                }
                $where[] = 'p.content_updated_at >= ?';
                $params[] = Clock::iso($ts);
            }
        }
        $cursor = $req->q('cursor');
        if ($cursor !== null && $cursor !== '') {
            $state = $this->app->crypto->unseal('products', $cursor);
            if ($state === null || !isset($state['id'])) {
                throw new ApiError('cursor_invalid', 'This cursor is malformed.');
            }
            $where[] = 'p.id > ?';
            $params[] = (string) $state['id'];
        }
        $rows = $this->app->db->all('SELECT p.* FROM obx_products p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id LIMIT ' . ($limit + 1), $params);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $next = $more ? $this->app->crypto->seal('products', ['id' => (string) end($rows)['id']]) : null;
        return Response::json(['items' => $this->app->reader->details($rows, $v), 'next_cursor' => $next]);
    }

    public function product(Request $req): Response
    {
        $a = $this->app->auth->apiClient($req, 'catalog:read');
        $v = $this->viewer($a);
        $detail = $this->app->reader->detail((string) $req->params['id'], $v);
        if ($detail === null) {
            throw new ApiError('not_found', 'No such product.');
        }
        $etag = '"' . hash('sha256', Json::canonical(self::stripUrls($detail)) . ($v->originalAllowed ? 'o' : '')) . '"';
        $inm = $req->header('if-none-match');
        if ($inm !== null && self::etagMatches($inm, $etag)) {
            return (new Response(304))->header('ETag', $etag);
        }
        return Response::json($detail)->header('ETag', $etag)->header('Cache-Control', 'private, no-cache');
    }

    private static function stripUrls(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        unset($v['url'], $v['url_expires_at']);
        foreach ($v as $k => $x) {
            $v[$k] = self::stripUrls($x);
        }
        return $v;
    }

    public function byGtinGet(Request $req): Response
    {
        $a = $this->app->auth->apiClient($req, 'catalog:read');
        $raw = $req->q('gtin');
        if ($raw === null) {
            throw new ApiError('invalid_request', 'Pass gtin=<code>,<code>.');
        }
        $codes = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($s) => $s !== ''));
        if (count($codes) > 100) {
            throw new ApiError('limit_exceeded', 'At most 100 codes in the query string; use POST for up to 2000.');
        }
        return Response::json((new GtinLookup($this->app))->lookup($codes, $this->viewer($a)));
    }

    public function byGtinPost(Request $req): Response
    {
        $a = $this->app->auth->apiClient($req, 'catalog:read');
        $body = $req->jsonObject();
        $codes = $body['gtins'] ?? null;
        if (!is_array($codes) || !array_is_list($codes)) {
            throw ApiError::validation('/gtins', 'Send {"gtins": ["…"]}.');
        }
        if (count($codes) > 2000) {
            throw new ApiError('limit_exceeded', 'At most 2000 codes per request.');
        }
        foreach ($codes as $i => $c) {
            if (!is_string($c) || strlen($c) > 64) {
                throw ApiError::validation('/gtins/' . $i, 'Each code is a string of at most 64 characters.');
            }
        }
        return Response::json((new GtinLookup($this->app))->lookup($codes, $this->viewer($a)));
    }

    // ================================================================ media

    public function asset(Request $req): Response
    {
        $a = $this->app->auth->apiClient($req, 'media:read');
        $v = $this->viewer($a);
        $row = $this->app->reader->visibleAssetRow((string) $req->params['id'], $v);
        if ($row === null) {
            throw new ApiError('not_found', 'No such asset.');
        }
        return Response::json($this->app->reader->asset($row, $v));
    }

    public function rendition(Request $req): Response
    {
        $name = (string) $req->params['name'];
        $id = (string) $req->params['id'];
        if (!in_array($name, ['original', 'web', 'square', 'thumb'], true)) {
            throw new ApiError('not_found', 'Unknown rendition.');
        }
        $path = '/obx/v1/assets/' . rawurlencode($id) . '/renditions/' . $name;
        $log = null;
        $admin = false;
        if ($req->q('sig') !== null) {
            $actor = $this->app->reader->verifySigned($path, $req->q('exp'), $req->q('act'), $req->q('sig'));
            if ($actor === null) {
                throw new ApiError('forbidden', 'This link has expired or is not valid. Fetch the asset again for a fresh link.');
            }
            [$viewer, $log, $admin] = $this->signedViewer($actor);
            $this->app->limiter->hit('rend:' . $actor, 3000, 60);
        } else {
            $a = $this->app->auth->apiClient($req, 'media:read', false);
            $this->app->limiter->hit('rend:c:' . $a['client']['id'], 3000, 60);
            $viewer = $this->viewer($a);
            if ($name === 'original' && !in_array('media:original', $a['scopes'], true)) {
                throw new ApiError($this->app->grants->originalAllowed($a['retailer']) ? 'insufficient_scope' : 'original_not_allowed', 'Originals are not available to this client.');
            }
            $log = ['retailer' => $a['retailer'], 'actor_type' => 'api_client', 'actor_id' => $a['client']['id'], 'actor_name' => $a['client']['name']];
        }
        if ($admin) {
            $row = $this->app->db->one('SELECT * FROM obx_assets WHERE id = ?', [$id]);
            $files = $row !== null ? Json::decode((string) $row['files'], []) : [];
            if ($row === null || !isset($files[$name])) {
                throw new ApiError('not_found', 'No such file.');
            }
            $sha = (string) $files[$name]['sha256'];
            $in = Json::decode((string) $row['input'], []);
            $blob = $this->app->blobs->get($sha);
            $filename = Str::renditionFilename((string) ($in['filename'] ?? ($row['external_id'] . '.' . \Obx\Node\Images\ImageInfo::extension((string) ($blob['content_type'] ?? '')))), $name);
        } else {
            $row = $this->app->reader->visibleAssetRow($id, $viewer);
            if ($row === null) {
                throw new ApiError('not_found', 'No such asset.');
            }
            if ($name === 'original' && !$viewer->originalAllowed) {
                throw new ApiError('original_not_allowed', 'Originals are not available to you.');
            }
            $rep = Json::decode((string) $row['rep'], []);
            $sha = (string) $rep['renditions'][$name]['sha256'];
            $filename = Str::renditionFilename((string) $rep['filename'], $name);
        }
        $blob = $this->app->blobs->get($sha);
        $file = $this->app->blobs->path($sha);
        if ($blob === null || $file === null) {
            throw new ApiError('not_found', 'The file is not available.');
        }
        $res = $this->serveFile($req, $file, (string) $blob['content_type'], $sha, 'inline', $filename);
        if ($log !== null && $res->status === 200 || ($log !== null && $res->status === 206 && $res->fileOffset === 0)) {
            $this->app->stats->record('api_rendition', $log['retailer'], $log['actor_type'], $log['actor_id'], $log['actor_name'], [
                'variant' => $name, 'asset_id' => $id, 'product_id' => $row['product_id'] ?? null, 'files' => 1, 'bytes' => (int) $blob['bytes'],
            ]);
        }
        return $res;
    }

    /**
     * Resolves the actor of a signed URL, re-checking that it is still allowed.
     *
     * @return array{0: Viewer, 1: ?array<string, mixed>, 2: bool} viewer, download log info, admin mode
     */
    private function signedViewer(string $actor): array
    {
        $db = $this->app->db;
        [$kind, $id] = array_pad(explode(':', $actor, 2), 2, '');
        if ($kind === 'c') {
            $client = $db->one('SELECT * FROM obx_api_clients WHERE id = ?', [$id]);
            $retailer = $client !== null ? $db->one('SELECT * FROM obx_retailers WHERE id = ?', [$client['retailer_id']]) : null;
            if ($client === null || $retailer === null || $client['status'] !== 'active' || $retailer['status'] !== 'active') {
                throw new ApiError('access_revoked', 'Access for this client was revoked.');
            }
            $scopes = array_values(array_intersect(Json::decode((string) $client['scopes'], []), $this->app->grants->scopes($retailer)));
            $v = $this->app->grants->apiViewer($retailer, $scopes, (string) $client['id'], (string) $client['name']);
            return [$v, ['retailer' => $retailer, 'actor_type' => 'api_client', 'actor_id' => $client['id'], 'actor_name' => $client['name']], false];
        }
        if ($kind === 'v' || $kind === 'a' || $kind === 'd') {
            $user = $db->one("SELECT * FROM obx_users WHERE id = ? AND status = 'active'", [$id]);
            if ($user === null) {
                throw new ApiError('forbidden', 'This link is no longer valid.');
            }
            if ($user['role'] === 'admin') {
                return [Viewer::preview(true, $kind . ':' . $user['id']), null, $kind === 'a'];
            }
            $retailer = $db->one("SELECT * FROM obx_retailers WHERE id = ? AND status = 'active'", [$user['retailer_id']]);
            if ($retailer === null) {
                throw new ApiError('forbidden', 'This link is no longer valid.');
            }
            $v = $this->app->grants->portalViewer($retailer, (string) $user['id'], (string) $user['name']);
            $log = $kind === 'd' ? ['retailer' => $retailer, 'actor_type' => 'user', 'actor_id' => $user['id'], 'actor_name' => $user['name']] : null;
            return [$v, $log, false];
        }
        throw new ApiError('forbidden', 'This link is not valid.');
    }

    // ================================================================ exports

    public function createExport(Request $req): Response
    {
        $a = $this->app->auth->apiClient($req, 'catalog:read');
        $body = $req->jsonObject();
        $export = $this->app->exports->create($a, $body, $req->header('idempotency-key'));
        return Response::json($export, 202)->header('Location', $this->app->baseUrl() . '/obx/v1/exports/' . $export['id']);
    }

    public function getExport(Request $req): Response
    {
        $a = $this->app->auth->apiClient($req, 'catalog:read');
        return Response::json($this->app->exports->get($a, (string) $req->params['id']));
    }

    public function exportManifest(Request $req): Response
    {
        [$exportRow, $retailer] = $this->exportAccess($req, 'catalog:read');
        $path = $this->app->exports->manifestPath($exportRow);
        $res = new Response(200);
        $res->header('Content-Type', 'application/x-ndjson');
        $res->header('Cache-Control', 'private, no-store');
        $res->file = $path;
        return $res;
    }

    public function exportPart(Request $req): Response
    {
        [$exportRow, $retailer, $log] = $this->exportAccess($req, 'media:read');
        $n = (string) $req->params['number'];
        if (!ctype_digit($n)) {
            throw new ApiError('not_found', 'No such part.');
        }
        [$path, $sha] = $this->app->exports->partPath($exportRow, (int) $n);
        $res = $this->serveFile($req, $path, 'application/zip', $sha, 'attachment', 'export-' . $exportRow['id'] . '-part-' . $n . '.zip', 'private, no-store');
        if ($log !== null && ($res->status === 200 || ($res->status === 206 && $res->fileOffset === 0))) {
            $parts = Json::decode((string) $exportRow['parts'], []);
            $files = 0;
            foreach ($parts as $p) {
                if ((int) $p['number'] === (int) $n) {
                    $files = (int) $p['files'];
                }
            }
            $this->app->stats->record('api_export', $retailer, 'api_client', $log['actor_id'], $log['actor_name'], ['files' => $files, 'bytes' => (int) filesize($path)]);
        }
        return $res;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: ?array<string, mixed>} */
    private function exportAccess(Request $req, string $scope): array
    {
        $id = (string) $req->params['id'];
        $db = $this->app->db;
        if ($req->q('sig') !== null) {
            $path = $req->path;
            $actor = $this->app->reader->verifySigned($path, $req->q('exp'), $req->q('act'), $req->q('sig'));
            if ($actor === null || !str_starts_with($actor, 'c:')) {
                throw new ApiError('forbidden', 'This link has expired or is not valid.');
            }
            [$v, $log] = $this->signedViewer($actor);
            $client = $db->one('SELECT * FROM obx_api_clients WHERE id = ?', [substr($actor, 2)]);
            $a = ['client' => $client, 'retailer' => $db->one('SELECT * FROM obx_retailers WHERE id = ?', [$client['retailer_id']])];
        } else {
            $a = $this->app->auth->apiClient($req, $scope);
            $log = ['actor_id' => $a['client']['id'], 'actor_name' => $a['client']['name']];
        }
        $row = $db->one('SELECT * FROM obx_exports WHERE id = ? AND retailer_id = ?', [$id, $a['retailer']['id']]);
        if ($row === null) {
            throw new ApiError('not_found', 'No such export.');
        }
        if ($row['status'] === 'expired' || ($row['expires_ts'] !== null && (int) $row['expires_ts'] < Clock::time())) {
            throw new ApiError('export_expired', 'This export has expired. Start a new one.');
        }
        if ($row['status'] !== 'succeeded') {
            throw new ApiError('not_found', 'The export is not finished yet.');
        }
        return [$row, $a['retailer'], $log];
    }

    // ================================================================ usage

    public function usage(Request $req): Response
    {
        $a = $this->app->auth->apiClient($req, 'usage:write');
        $body = $req->jsonObject();
        $events = $body['events'] ?? null;
        if (!is_array($events) || !array_is_list($events) || $events === []) {
            throw ApiError::validation('/events', 'Send 1 to 1000 events.');
        }
        if (count($events) > 1000) {
            throw new ApiError('limit_exceeded', 'At most 1000 events per request.');
        }
        $errors = [];
        foreach ($events as $i => $e) {
            if (!is_array($e) || !is_string($e['asset_id'] ?? null) || !in_array($e['event'] ?? null, ['synced', 'published', 'downloaded', 'removed'], true)
                || !is_string($e['at'] ?? null) || Clock::parse($e['at']) === null
                || (isset($e['count']) && (!is_int($e['count']) || $e['count'] < 1))
                || (isset($e['rendition']) && !in_array($e['rendition'], ['original', 'web', 'square', 'thumb'], true))
                || (isset($e['channel']) && !in_array($e['channel'], ['webshop', 'marketplace', 'social', 'print'], true))) {
                $errors[] = ['pointer' => '/events/' . $i, 'detail' => 'Each event needs asset_id, event (synced|published|downloaded|removed) and at (RFC 3339).'];
            }
        }
        if ($errors !== []) {
            throw new ApiError('validation_failed', 'Some usage events are invalid.', array_slice($errors, 0, 50));
        }
        $v = $this->viewer($a);
        $accepted = 0;
        foreach ($events as $e) {
            $row = $this->app->reader->visibleAssetRow((string) $e['asset_id'], $v);
            if ($row === null) {
                continue;
            }
            $accepted++;
            $this->app->stats->record('usage', $a['retailer'], 'api_client', (string) $a['client']['id'], (string) $a['client']['name'], [
                'asset_id' => $row['id'], 'product_id' => $row['product_id'], 'variant' => $e['rendition'] ?? null,
                'usage_event' => $e['event'], 'files' => (int) ($e['count'] ?? 1),
            ]);
        }
        return Response::json(['accepted' => $accepted], 202);
    }

    // ================================================================ webhooks

    public function listWebhooks(Request $req): Response
    {
        $a = $this->app->auth->apiClient($req, 'catalog:read');
        return Response::json(['items' => $this->app->webhooks->list('client', (string) $a['client']['id'])]);
    }

    public function createWebhook(Request $req): Response
    {
        $a = $this->app->auth->apiClient($req, 'catalog:read');
        $body = $req->jsonObject();
        $hook = $this->app->webhooks->create('client', (string) $a['client']['id'], $body, ['changes.available', 'grant.updated', 'export.completed'], 5);
        return Response::json($hook, 201);
    }

    public function deleteWebhook(Request $req): Response
    {
        $a = $this->app->auth->apiClient($req, 'catalog:read');
        $this->app->webhooks->delete('client', (string) $a['client']['id'], (string) $req->params['id']);
        return Response::empty(204);
    }

    public function testWebhook(Request $req): Response
    {
        $a = $this->app->auth->apiClient($req, 'catalog:read');
        $d = $this->app->webhooks->test('client', (string) $a['client']['id'], (string) $req->params['id']);
        return Response::json(['delivered' => $d['success'], 'status' => $d['status_code']]);
    }
}
