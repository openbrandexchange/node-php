<?php

declare(strict_types=1);

namespace Obx\Node\Controllers;

use Obx\Node\Catalog\Validator;
use Obx\Node\Config;
use Obx\Node\Http\ApiError;
use Obx\Node\Http\Request;
use Obx\Node\Http\Response;
use Obx\Node\Images\ImageInfo;
use Obx\Node\Import\Csv;
use Obx\Node\Security\Crypto;
use Obx\Node\Services\Retailers;
use Obx\Node\Services\Settings;
use Obx\Node\Services\Stats;
use Obx\Node\Services\Users;
use Obx\Node\Services\Webhooks;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Ids;
use Obx\Node\Support\Json;
use Obx\Node\Support\Lang;

/**
 * Brand admin: settings, imports, retailers, access requests, accounts, keys, automation, stats.
 */
final class AdminController extends Controller
{
    /** @return array{session: array<string, mixed>, user: array<string, mixed>, retailer: ?array<string, mixed>} */
    private function admin(Request $req): array
    {
        $s = $this->app->auth->user($req, 'admin');
        // Hosts without cron: do a little background work after admin requests.
        $this->app->defer(fn () => $this->app->worker()->tick(20));
        return $s;
    }

    private function retailerService(): Retailers
    {
        return new Retailers($this->app);
    }

    // ================================================================ settings

    /** @return array<string, mixed> */
    public function settingsData(): array
    {
        $s = $this->app->settings->all();
        $base = $this->app->baseUrl();
        return [
            'company' => $s['company'],
            'branding' => [
                'display_name' => $this->app->settings->displayName(),
                'logo_url' => (new DiscoveryController($this->app))->logoUrl(),
                'accent_color' => $s['branding']['accent_color'],
                'welcome_text' => $s['branding']['welcome_text'],
                'portal_word' => $s['branding']['portal_word'] ?? null,
            ],
            'portal' => [
                'access_requests_enabled' => (bool) $s['portal']['access_requests_enabled'],
                'allow_original_download' => (bool) $s['portal']['allow_original_download'],
                'allow_article_data_export' => (bool) $s['portal']['allow_article_data_export'],
                'retailer_api_self_service' => (bool) $s['portal']['retailer_api_self_service'],
                'languages' => $this->app->settings->languages(),
                'default_language' => $this->app->settings->defaultLanguage(),
            ],
            'defaults' => [
                'rights' => $this->app->settings->defaultRights(),
                'ingest_status' => $this->app->settings->defaultStatus(),
            ],
            'node' => [
                'public_url' => $this->app->publicUrl(),
                'discovery_url' => $base . '/.well-known/obx',
                'software' => Config::SOFTWARE,
                'version' => Config::VERSION,
                'email_configured' => $this->app->mailer->configured(),
                'renditions_server_generated' => $this->app->renditions->serverGenerated(),
                'max_upload_bytes' => max(26214400, (int) $this->app->config->get('max_upload_bytes')),
                'custom_domain_help' => "1. In your domain's DNS, point the domain (A record or CNAME) to your web host.\n"
                    . "2. In your hosting control panel, add the domain as an alias or add-on domain that serves this portal's `public` folder, and switch on HTTPS (Let's Encrypt).\n"
                    . "3. Set `public_url` in `config/config.php` (or `OBX_PUBLIC_URL`) to the new address so e-mails link to it.",
                'cron' => [
                    'url' => $base . '/cron?key=' . $this->app->crypto->derive('cron', 32),
                    'command' => 'php ' . $this->app->config->root() . '/bin/obx cron',
                    'last_run_at' => isset($this->app->settings->state()['cron_ts']) ? Clock::iso((float) $this->app->settings->state()['cron_ts']) : null,
                ],
            ],
        ];
    }

    public function settings(Request $req): Response
    {
        $this->admin($req);
        return Response::json($this->settingsData());
    }

    public function patchSettings(Request $req): Response
    {
        $this->admin($req);
        $patch = $req->jsonObject();
        $s = $this->app->settings->all();
        $before = $s;
        $v = new Validator();
        if (isset($patch['company']) && is_array($patch['company'])) {
            $c = $patch['company'];
            if (array_key_exists('name', $c)) {
                if (!is_string($c['name']) || trim($c['name']) === '' || mb_strlen($c['name'], 'UTF-8') > 200) {
                    throw ApiError::validation('/company/name', 'Enter the company name.');
                }
                $s['company']['name'] = trim($c['name']);
            }
            if (array_key_exists('website', $c)) {
                if ($c['website'] !== null && (!is_string($c['website']) || filter_var($c['website'], FILTER_VALIDATE_URL) === false)) {
                    throw ApiError::validation('/company/website', 'A web address starting with https://.');
                }
                $s['company']['website'] = $c['website'];
            }
            if (array_key_exists('contact_email', $c)) {
                $s['company']['contact_email'] = $c['contact_email'] === null ? null : Users::normalizeEmail($c['contact_email'], '/company/contact_email');
            }
            if (array_key_exists('country', $c)) {
                if ($c['country'] !== null && (!is_string($c['country']) || !preg_match('/^[A-Z]{2}$/', $c['country']))) {
                    throw ApiError::validation('/company/country', 'Two capital letters, e.g. NL.');
                }
                $s['company']['country'] = $c['country'];
            }
        }
        if (isset($patch['branding']) && is_array($patch['branding'])) {
            $b = $patch['branding'];
            if (array_key_exists('display_name', $b)) {
                if ($b['display_name'] !== null && (!is_string($b['display_name']) || trim($b['display_name']) === '' || mb_strlen($b['display_name'], 'UTF-8') > 200)) {
                    throw ApiError::validation('/branding/display_name', 'Enter the name retailers see.');
                }
                $s['branding']['display_name'] = $b['display_name'] !== null ? trim($b['display_name']) : null;
            }
            if (array_key_exists('accent_color', $b)) {
                if ($b['accent_color'] !== null && (!is_string($b['accent_color']) || !preg_match('/^#[0-9a-fA-F]{6}$/', $b['accent_color']))) {
                    throw ApiError::validation('/branding/accent_color', 'A colour like #2f5d62.');
                }
                $s['branding']['accent_color'] = $b['accent_color'];
            }
            foreach (['welcome_text', 'portal_word'] as $k) {
                if (array_key_exists($k, $b)) {
                    $s['branding'][$k] = $b[$k] === null ? null : $v->localized(Validator::mergePatch($s['branding'][$k] ?? [], $b[$k]), '/branding/' . $k);
                }
            }
        }
        if (isset($patch['portal']) && is_array($patch['portal'])) {
            $p = $patch['portal'];
            foreach (['access_requests_enabled', 'allow_original_download', 'allow_article_data_export', 'retailer_api_self_service'] as $k) {
                if (array_key_exists($k, $p)) {
                    if (!is_bool($p[$k])) {
                        throw ApiError::validation('/portal/' . $k, 'true or false.');
                    }
                    $s['portal'][$k] = $p[$k];
                }
            }
            if (array_key_exists('languages', $p)) {
                if (!is_array($p['languages']) || $p['languages'] === []) {
                    throw ApiError::validation('/portal/languages', 'At least one language.');
                }
                foreach ($p['languages'] as $l) {
                    if (!is_string($l) || !preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $l)) {
                        throw ApiError::validation('/portal/languages', 'Language codes such as nl or en.');
                    }
                }
                $s['portal']['languages'] = array_values(array_unique($p['languages']));
            }
            if (array_key_exists('default_language', $p)) {
                if (!is_string($p['default_language']) || !preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $p['default_language'])) {
                    throw ApiError::validation('/portal/default_language', 'A language code such as nl.');
                }
                $s['portal']['default_language'] = $p['default_language'];
            }
        }
        if (isset($patch['defaults']) && is_array($patch['defaults'])) {
            $d = $patch['defaults'];
            if (array_key_exists('rights', $d)) {
                $partial = $v->rights(is_array($d['rights']) ? $d['rights'] : null, '/defaults/rights');
                $s['defaults']['rights'] = array_replace(Settings::DEFAULT_RIGHTS, $this->app->settings->defaultRights(), (array) $partial);
                if (($s['defaults']['rights']['license'] ?? '') === 'custom' && empty($s['defaults']['rights']['license_text'])) {
                    throw ApiError::validation('/defaults/rights/license_text', 'Required for a custom licence.');
                }
            }
            if (array_key_exists('ingest_status', $d)) {
                if (!in_array($d['ingest_status'], ['published', 'unpublished'], true)) {
                    throw ApiError::validation('/defaults/ingest_status', 'published or unpublished.');
                }
                $s['defaults']['ingest_status'] = $d['ingest_status'];
            }
        }
        $this->app->db->tx(function () use ($s, $before) {
            $this->app->settings->save($s);
            if ($before['portal']['allow_original_download'] !== $s['portal']['allow_original_download']) {
                $this->app->grants->touchAll(true);
                foreach ($this->app->db->column("SELECT id FROM obx_retailers WHERE status = 'active'") as $rid) {
                    $this->app->webhooks->grantUpdated((string) $rid);
                }
            }
            if (Json::canonical($before['defaults']['rights'] ?? []) !== Json::canonical($s['defaults']['rights'] ?? [])) {
                $this->app->writer->resyncAllAssets();
            }
        });
        $this->app->defer(fn () => $this->app->webhooks->notifyChanges());
        return Response::json($this->settingsData());
    }

    public function putLogo(Request $req): Response
    {
        $this->admin($req);
        $tmp = $req->bodyToFile($this->app->blobs->tmpDir(), 5 * 1024 * 1024);
        $type = ImageInfo::sniffFile($tmp['path']);
        if (!in_array($type, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            @unlink($tmp['path']);
            throw new ApiError('unsupported_media_type', 'Upload a PNG, JPEG or WebP logo (no SVG).');
        }
        $blob = $this->app->blobs->store($tmp['path'], $tmp['sha256'], $tmp['bytes']);
        $s = $this->app->settings->all();
        $s['branding']['logo_sha'] = $blob['sha256'];
        $s['branding']['logo_type'] = $blob['content_type'];
        $this->app->settings->save($s);
        return Response::json($this->settingsData());
    }

    public function deleteLogo(Request $req): Response
    {
        $this->admin($req);
        $s = $this->app->settings->all();
        $s['branding']['logo_sha'] = null;
        $s['branding']['logo_type'] = null;
        $this->app->settings->save($s);
        return Response::json($this->settingsData());
    }

    // ================================================================ imports

    public function imports(Request $req): Response
    {
        $this->admin($req);
        $limit = $this->limit($req, 50, 200);
        $offset = $this->offset($req, 'imports');
        $source = $req->q('source_id');
        $sql = 'SELECT * FROM obx_import_runs' . ($source !== null ? ' WHERE source_id = ?' : '') . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $rows = $this->app->db->all($sql, $source !== null ? [$source] : []);
        return Response::json([
            'items' => array_map(fn ($r) => $this->app->importer->runJson($r), $rows),
            'next_cursor' => $this->nextCursor('imports', $offset, count($rows), $limit),
        ]);
    }

    public function createImport(Request $req): Response
    {
        $s = $this->admin($req);
        $options = $this->app->importer->parseOptions($req->post['options'] ?? (isset($req->files['options'][0]) ? (string) file_get_contents($req->files['options'][0]['tmp']) : null));
        $job = $this->app->importer->queueFromRequest($req, $options, null, 'upload', 'user', (string) $s['user']['id'], null);
        $run = $this->app->db->one('SELECT * FROM obx_import_runs WHERE job_id = ?', [$job['id']]);
        return Response::json($this->app->importer->runJson($run), 202);
    }

    public function importRun(Request $req): Response
    {
        $this->admin($req);
        $run = $this->app->db->one('SELECT * FROM obx_import_runs WHERE id = ?', [$req->params['id']]);
        if ($run === null) {
            throw new ApiError('not_found', 'No such import.');
        }
        return Response::json($this->app->importer->runJson($run));
    }

    public function importErrors(Request $req): Response
    {
        $this->admin($req);
        $run = $this->app->db->one('SELECT id FROM obx_import_runs WHERE id = ?', [$req->params['id']]);
        if ($run === null) {
            throw new ApiError('not_found', 'No such import.');
        }
        $limit = $this->limit($req, 50, 200);
        $offset = $this->offset($req, 'import-errors');
        $page = $this->app->importer->errors((string) $run['id'], $offset, $limit);
        return Response::json(['items' => $page['items'], 'next_cursor' => $page['next'] !== null ? $this->app->crypto->seal('list:import-errors', ['o' => $page['next']]) : null]);
    }

    public function importTemplate(Request $req): Response
    {
        $this->admin($req);
        $langs = $this->app->settings->languages();
        $header = ['brand', 'style_number'];
        foreach ($langs as $l) {
            $header[] = 'name:' . $l;
        }
        foreach ($langs as $l) {
            $header[] = 'description:' . $l;
        }
        array_push($header, 'item_type', 'target_group', 'season', 'collection', 'materials', 'care', 'country_of_origin', 'gtin', 'sku', 'color_code');
        foreach ($langs as $l) {
            $header[] = 'color_name:' . $l;
        }
        array_push($header, 'size', 'size_system');
        $brand = (string) ($this->app->db->value('SELECT name FROM obx_brands ORDER BY created_at LIMIT 1') ?? 'My brand');
        $sample = static function (string $size, string $gtin) use ($brand, $langs): array {
            $row = [$brand, 'ST-1001'];
            foreach ($langs as $_) {
                $row[] = 'Crew neck sweater';
            }
            foreach ($langs as $_) {
                $row[] = 'Fine-knit sweater in soft merino wool.';
            }
            array_push($row, 'sweater', 'unisex', 'AW26', 'Core', 'main: 100% merino_wool', 'wash_30_very_mild|do_not_tumble_dry|dry_flat', 'PT', $gtin, 'ST-1001-NVY-' . $size, 'NVY');
            foreach ($langs as $_) {
                $row[] = 'Navy';
            }
            array_push($row, $size, 'INT');
            return $row;
        };
        $csv = "\xEF\xBB\xBF" . Csv::line($header, ',') . Csv::line($sample('S', '2000000000015'), ',') . Csv::line($sample('M', '2000000000022'), ',');
        $res = Response::text($csv, 'text/csv; charset=utf-8');
        $res->header('Content-Disposition', 'attachment; filename="products-template.csv"');
        return $res;
    }

    // ---------------------------------------------------------------- import sources

    public function sources(Request $req): Response
    {
        $this->admin($req);
        $rows = $this->app->db->all('SELECT * FROM obx_import_sources ORDER BY created_at');
        return Response::json(['items' => array_map(fn ($r) => $this->app->sources->json($r), $rows)]);
    }

    public function createSource(Request $req): Response
    {
        $this->admin($req);
        return Response::json($this->app->sources->create($req->jsonObject()), 201);
    }

    public function source(Request $req): Response
    {
        $this->admin($req);
        return Response::json($this->app->sources->json($this->app->sources->get((string) $req->params['id'])));
    }

    public function patchSource(Request $req): Response
    {
        $this->admin($req);
        return Response::json($this->app->sources->update((string) $req->params['id'], $req->jsonObject()));
    }

    public function deleteSource(Request $req): Response
    {
        $this->admin($req);
        $this->app->sources->delete((string) $req->params['id']);
        return Response::empty(204);
    }

    public function runSource(Request $req): Response
    {
        $this->admin($req);
        $body = $req->jsonObject(true);
        $run = $this->app->sources->run((string) $req->params['id'], (bool) ($body['dry_run'] ?? false), (bool) ($body['force'] ?? false), 'manual_run');
        return Response::json($run, 202);
    }

    public function testSource(Request $req): Response
    {
        $this->admin($req);
        return Response::json($this->app->sources->test((string) $req->params['id']));
    }

    // ================================================================ retailers

    public function retailers(Request $req): Response
    {
        $this->admin($req);
        $limit = $this->limit($req, 50, 200);
        $offset = $this->offset($req, 'retailers');
        $where = [];
        $params = [];
        if (($q = $req->q('q')) !== null && trim($q) !== '') {
            $where[] = '(LOWER(name) LIKE ? OR LOWER(COALESCE(reference, \'\')) LIKE ?)';
            $like = '%' . mb_strtolower(trim($q), 'UTF-8') . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if (($st = $req->q('status')) !== null && in_array($st, ['active', 'blocked'], true)) {
            $where[] = 'status = ?';
            $params[] = $st;
        }
        $rows = $this->app->db->all('SELECT * FROM obx_retailers' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY name, id LIMIT ' . $limit . ' OFFSET ' . $offset, $params);
        return Response::json([
            'items' => array_map(fn ($r) => $this->retailerService()->json($r), $rows),
            'next_cursor' => $this->nextCursor('retailers', $offset, count($rows), $limit),
        ]);
    }

    public function createRetailer(Request $req): Response
    {
        $this->admin($req);
        $r = $this->retailerService()->create($req->jsonObject());
        return Response::json($this->retailerService()->json($r), 201);
    }

    public function retailer(Request $req): Response
    {
        $this->admin($req);
        return Response::json($this->retailerService()->json($this->retailerService()->get((string) $req->params['id'])));
    }

    public function patchRetailer(Request $req): Response
    {
        $this->admin($req);
        $r = $this->retailerService()->update((string) $req->params['id'], $req->jsonObject());
        $this->app->defer(fn () => $this->app->webhooks->notifyChanges());
        return Response::json($this->retailerService()->json($r));
    }

    public function deleteRetailer(Request $req): Response
    {
        $this->admin($req);
        $this->retailerService()->delete((string) $req->params['id']);
        return Response::empty(204);
    }

    public function accessRequests(Request $req): Response
    {
        $this->admin($req);
        $limit = $this->limit($req, 50, 200);
        $offset = $this->offset($req, 'access');
        $st = $req->q('status');
        $rows = $st !== null && in_array($st, ['pending', 'approved', 'rejected'], true)
            ? $this->app->db->all('SELECT * FROM obx_access_requests WHERE status = ? ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, [$st])
            : $this->app->db->all('SELECT * FROM obx_access_requests ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
        return Response::json([
            'items' => array_map(fn ($r) => $this->app->accessRequests->adminJson($r), $rows),
            'next_cursor' => $this->nextCursor('access', $offset, count($rows), $limit),
        ]);
    }

    public function accessRequest(Request $req): Response
    {
        $this->admin($req);
        return Response::json($this->app->accessRequests->adminJson($this->app->accessRequests->get((string) $req->params['id'])));
    }

    public function approve(Request $req): Response
    {
        $this->admin($req);
        return Response::json($this->app->accessRequests->approve((string) $req->params['id'], $req->jsonObject(true)));
    }

    public function reject(Request $req): Response
    {
        $this->admin($req);
        $body = $req->jsonObject(true);
        $reason = $body['reason'] ?? null;
        if ($reason !== null && (!is_string($reason) || mb_strlen($reason, 'UTF-8') > 2000)) {
            throw ApiError::validation('/reason', 'At most 2000 characters.');
        }
        return Response::json($this->app->accessRequests->reject((string) $req->params['id'], $reason !== null ? trim($reason) : null));
    }

    // ================================================================ users

    public function usersList(Request $req): Response
    {
        $this->admin($req);
        $limit = $this->limit($req, 50, 200);
        $offset = $this->offset($req, 'users');
        $where = [];
        $params = [];
        if (($role = $req->q('role')) !== null && in_array($role, ['admin', 'retailer'], true)) {
            $where[] = 'role = ?';
            $params[] = $role;
        }
        if (($rid = $req->q('retailer_id')) !== null && $rid !== '') {
            $where[] = 'retailer_id = ?';
            $params[] = $rid;
        }
        $rows = $this->app->db->all('SELECT * FROM obx_users' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY name, id LIMIT ' . $limit . ' OFFSET ' . $offset, $params);
        return Response::json([
            'items' => array_map(fn ($u) => $this->app->users->json($u), $rows),
            'next_cursor' => $this->nextCursor('users', $offset, count($rows), $limit),
        ]);
    }

    public function inviteUser(Request $req): Response
    {
        $this->admin($req);
        $in = $req->jsonObject();
        $email = Users::normalizeEmail($in['email'] ?? null);
        $role = $in['role'] ?? null;
        if (!in_array($role, ['admin', 'retailer'], true)) {
            throw ApiError::validation('/role', 'admin or retailer.');
        }
        $retailerId = null;
        if ($role === 'retailer') {
            $retailerId = is_string($in['retailer_id'] ?? null) ? $in['retailer_id'] : null;
            if ($retailerId === null) {
                throw ApiError::validation('/retailer_id', 'Choose the retailer this person works for.');
            }
            $this->retailerService()->get($retailerId);
        }
        $lang = isset($in['language']) && is_string($in['language']) ? $in['language'] : null;
        return Response::json($this->app->users->invite($email, is_string($in['name'] ?? null) ? $in['name'] : null, $role, $retailerId, $lang), 201);
    }

    /** @return array<string, mixed> */
    private function user(string $id): array
    {
        $u = $this->app->db->one('SELECT * FROM obx_users WHERE id = ?', [$id]);
        if ($u === null) {
            throw new ApiError('not_found', 'No such account.');
        }
        return $u;
    }

    private function guardLastAdmin(array $me, array $target): void
    {
        if ($me['id'] === $target['id']) {
            throw new ApiError('conflict', 'You cannot block or delete your own account.');
        }
        if ($target['role'] === 'admin' && $target['status'] === 'active') {
            $others = (int) $this->app->db->value("SELECT COUNT(*) FROM obx_users WHERE role = 'admin' AND status = 'active' AND id <> ?", [$target['id']]);
            if ($others === 0) {
                throw new ApiError('conflict', 'This is the last active administrator.');
            }
        }
    }

    public function patchUser(Request $req): Response
    {
        $s = $this->admin($req);
        $u = $this->user((string) $req->params['id']);
        $in = $req->jsonObject();
        $set = [];
        if (array_key_exists('name', $in)) {
            if (!is_string($in['name']) || trim($in['name']) === '') {
                throw ApiError::validation('/name', 'Enter a name.');
            }
            $set['name'] = trim($in['name']);
        }
        if (array_key_exists('status', $in)) {
            if (!in_array($in['status'], ['active', 'blocked'], true)) {
                throw ApiError::validation('/status', 'active or blocked.');
            }
            if ($in['status'] === 'blocked') {
                $this->guardLastAdmin($s['user'], $u);
            }
            if ($in['status'] === 'active' && $u['status'] === 'invited') {
                throw new ApiError('conflict', 'This person has not accepted the invitation yet.');
            }
            $set['status'] = $in['status'];
        }
        if ($set !== []) {
            $this->app->db->update('obx_users', $set, ['id' => $u['id']]);
            if (($set['status'] ?? null) === 'blocked') {
                $this->app->auth->endSessions((string) $u['id']);
            }
        }
        return Response::json($this->app->users->json($this->user((string) $u['id'])));
    }

    public function deleteUser(Request $req): Response
    {
        $s = $this->admin($req);
        $u = $this->user((string) $req->params['id']);
        $this->guardLastAdmin($s['user'], $u);
        $this->app->auth->endSessions((string) $u['id']);
        $this->app->db->delete('obx_selections', ['user_id' => $u['id']]);
        $this->app->db->run('UPDATE obx_download_events SET actor_name = NULL, actor_id = NULL WHERE actor_id = ?', [$u['id']]);
        $this->app->db->delete('obx_users', ['id' => $u['id']]);
        return Response::empty(204);
    }

    public function resendInvite(Request $req): Response
    {
        $this->admin($req);
        $u = $this->user((string) $req->params['id']);
        if ($u['status'] !== 'invited') {
            throw new ApiError('conflict', 'This account is already active.');
        }
        return Response::json($this->app->users->sendInvite((string) $u['id']));
    }

    // ================================================================ API clients

    public function apiClients(Request $req): Response
    {
        $this->admin($req);
        $rid = $req->q('retailer_id');
        $rows = $rid !== null && $rid !== ''
            ? $this->app->db->all('SELECT * FROM obx_api_clients WHERE retailer_id = ? ORDER BY created_at', [$rid])
            : $this->app->db->all('SELECT * FROM obx_api_clients ORDER BY created_at');
        return Response::json(['items' => array_map([Retailers::class, 'clientJson'], $rows)]);
    }

    public function createApiClient(Request $req): Response
    {
        $this->admin($req);
        $in = $req->jsonObject();
        if (!is_string($in['retailer_id'] ?? null)) {
            throw ApiError::validation('/retailer_id', 'Choose the retailer.');
        }
        $scopes = isset($in['scopes']) && is_array($in['scopes']) ? array_values(array_map('strval', $in['scopes'])) : null;
        $c = $this->retailerService()->createClient($in['retailer_id'], (string) ($in['name'] ?? ''), $scopes);
        return Response::json($this->retailerService()->withSecret($c), 201);
    }

    public function rotateApiClient(Request $req): Response
    {
        $this->admin($req);
        return Response::json($this->retailerService()->withSecret($this->retailerService()->rotateClient((string) $req->params['id'])));
    }

    public function revokeApiClient(Request $req): Response
    {
        $this->admin($req);
        $this->retailerService()->revokeClient((string) $req->params['id']);
        return Response::empty(204);
    }

    // ================================================================ ingest tokens

    /** @param array<string, mixed> $t @return array<string, mixed> */
    private function tokenJson(array $t): array
    {
        $status = $t['status'];
        if ($status === 'active' && $t['expires_ts'] !== null && (int) $t['expires_ts'] <= Clock::time()) {
            $status = 'expired';
        }
        return [
            'id' => $t['id'],
            'name' => $t['name'],
            'prefix' => $t['prefix'],
            'scopes' => Json::decode((string) $t['scopes'], []),
            'brand_external_ids' => Json::decode((string) $t['brand_external_ids'], null),
            'status' => $status,
            'expires_at' => $t['expires_at'],
            'created_at' => $t['created_at'],
            'last_used_at' => $t['last_used_at'],
        ];
    }

    public function ingestTokens(Request $req): Response
    {
        $this->admin($req);
        $rows = $this->app->db->all('SELECT * FROM obx_ingest_tokens ORDER BY created_at');
        return Response::json(['items' => array_map(fn ($t) => $this->tokenJson($t), $rows)]);
    }

    public function createIngestToken(Request $req): Response
    {
        $this->admin($req);
        $in = $req->jsonObject();
        $name = $in['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen($name, 'UTF-8') > 200) {
            throw ApiError::validation('/name', 'Name the token after the system that uses it.');
        }
        $scopes = $in['scopes'] ?? null;
        if (!is_array($scopes) || $scopes === [] || array_diff($scopes, ['ingest:read', 'ingest:write', 'ingest:delete']) !== []) {
            throw ApiError::validation('/scopes', 'Choose from ingest:read, ingest:write, ingest:delete.');
        }
        $brands = $in['brand_external_ids'] ?? null;
        if ($brands !== null && (!is_array($brands) || !array_is_list($brands))) {
            throw ApiError::validation('/brand_external_ids', 'A list of brand external ids, or null for all brands.');
        }
        $expires = $in['expires_at'] ?? null;
        $expiresTs = null;
        if ($expires !== null) {
            $ts = is_string($expires) ? Clock::parse($expires) : null;
            if ($ts === null || $ts <= Clock::now()) {
                throw ApiError::validation('/expires_at', 'A date-time in the future, or null.');
            }
            $expires = Clock::normalize($expires);
            $expiresTs = (int) $ts;
        }
        $secret = Crypto::token('obxi_', 32);
        $row = [
            'id' => Ids::new('ink'),
            'name' => trim($name),
            'prefix' => substr($secret, 0, 12),
            'secret_hash' => Crypto::hashToken($secret),
            'scopes' => Json::encode(array_values(array_intersect(['ingest:read', 'ingest:write', 'ingest:delete'], $scopes))),
            'brand_external_ids' => $brands === null ? null : Json::encode(array_values(array_unique(array_map('strval', $brands)))),
            'status' => 'active',
            'expires_at' => $expires,
            'expires_ts' => $expiresTs,
            'created_at' => Clock::iso(),
            'last_used_at' => null,
        ];
        $this->app->db->insert('obx_ingest_tokens', $row);
        return Response::json(['token' => $this->tokenJson($row), 'secret' => $secret, 'ingest_url' => $this->app->baseUrl() . '/obx/v1/ingest'], 201);
    }

    public function rotateIngestToken(Request $req): Response
    {
        $this->admin($req);
        $t = $this->app->db->one('SELECT * FROM obx_ingest_tokens WHERE id = ?', [$req->params['id']]);
        if ($t === null) {
            throw new ApiError('not_found', 'No such token.');
        }
        $secret = Crypto::token('obxi_', 32);
        $this->app->db->update('obx_ingest_tokens', ['secret_hash' => Crypto::hashToken($secret), 'prefix' => substr($secret, 0, 12), 'status' => 'active'], ['id' => $t['id']]);
        $t = $this->app->db->one('SELECT * FROM obx_ingest_tokens WHERE id = ?', [$t['id']]);
        return Response::json(['token' => $this->tokenJson($t), 'secret' => $secret, 'ingest_url' => $this->app->baseUrl() . '/obx/v1/ingest']);
    }

    public function revokeIngestToken(Request $req): Response
    {
        $this->admin($req);
        if ($this->app->db->update('obx_ingest_tokens', ['status' => 'revoked'], ['id' => $req->params['id']]) === 0) {
            throw new ApiError('not_found', 'No such token.');
        }
        return Response::empty(204);
    }

    // ================================================================ webhooks (brand)

    private const ADMIN_EVENTS = ['access_request.created', 'import_run.completed', 'ingest_job.completed'];

    public function webhooksList(Request $req): Response
    {
        $this->admin($req);
        return Response::json(['items' => $this->app->webhooks->list('admin', 'brand')]);
    }

    public function createWebhook(Request $req): Response
    {
        $this->admin($req);
        return Response::json($this->app->webhooks->create('admin', 'brand', $req->jsonObject(), self::ADMIN_EVENTS, 20), 201);
    }

    public function patchWebhook(Request $req): Response
    {
        $this->admin($req);
        return Response::json($this->app->webhooks->patch('admin', 'brand', (string) $req->params['id'], $req->jsonObject(), self::ADMIN_EVENTS));
    }

    public function deleteWebhook(Request $req): Response
    {
        $this->admin($req);
        $this->app->webhooks->delete('admin', 'brand', (string) $req->params['id']);
        return Response::empty(204);
    }

    public function testWebhook(Request $req): Response
    {
        $this->admin($req);
        return Response::json($this->app->webhooks->test('admin', 'brand', (string) $req->params['id']));
    }

    public function webhookDeliveries(Request $req): Response
    {
        $this->admin($req);
        $this->app->webhooks->get('admin', 'brand', (string) $req->params['id']);
        $limit = $this->limit($req, 50, 200);
        $offset = $this->offset($req, 'deliveries');
        $rows = $this->app->db->all('SELECT * FROM obx_webhook_deliveries WHERE webhook_id = ? ORDER BY at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, [$req->params['id']]);
        return Response::json([
            'items' => array_map([Webhooks::class, 'deliveryJson'], $rows),
            'next_cursor' => $this->nextCursor('deliveries', $offset, count($rows), $limit),
        ]);
    }

    // ================================================================ stats

    public function statsSummary(Request $req): Response
    {
        $this->admin($req);
        $days = (int) ($req->q('days') ?? 30);
        if (!in_array($days, [7, 30, 90, 365], true)) {
            throw new ApiError('invalid_request', 'days is 7, 30, 90 or 365.');
        }
        return Response::json($this->app->stats->summary($days));
    }

    public function statEvents(Request $req): Response
    {
        $this->admin($req);
        $limit = $this->limit($req, 50, 200);
        $offset = $this->offset($req, 'events');
        $where = [];
        $params = [];
        if (($rid = $req->q('retailer_id')) !== null && $rid !== '') {
            $where[] = 'retailer_id = ?';
            $params[] = $rid;
        }
        if (($kind = $req->q('kind')) !== null && $kind !== '') {
            $where[] = 'kind = ?';
            $params[] = $kind;
        }
        $rows = $this->app->db->all('SELECT * FROM obx_download_events' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $params);
        return Response::json([
            'items' => array_map([Stats::class, 'event'], $rows),
            'next_cursor' => $this->nextCursor('events', $offset, count($rows), $limit),
        ]);
    }
}
