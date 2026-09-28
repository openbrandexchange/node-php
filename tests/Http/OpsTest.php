<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Http;

use Obx\Node\App;
use Obx\Node\Config;
use Obx\Node\ConfigInvalid;
use Obx\Node\Db\Schema;
use Obx\Node\Db\SchemaTooNew;
use Obx\Node\Http\Request;
use Obx\Node\Ops\Backup;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Json;
use Obx\Node\Tests\Support\NodeTestCase;

/**
 * Operations: health checks, request ids, headers, metrics, audit log, GDPR exports and
 * deletion, retention, backup and restore, configuration and schema checks.
 */
final class OpsTest extends NodeTestCase
{
    private function retailerUser(string $browser, array $retailer): string
    {
        $r = $this->json($this->http('POST', '/admin/api/retailers', ['browser' => 'admin', 'json' => $retailer]), 201);
        $inv = $this->json($this->http('POST', '/admin/api/users/invites', ['browser' => 'admin', 'json' => ['email' => $browser . '@shop.example', 'name' => 'Rita ' . $browser, 'role' => 'retailer', 'retailer_id' => $r['id']]]), 201);
        $this->http('GET', '/admin/api/session', ['browser' => $browser]);
        $t = substr($inv['invite_url'], strrpos($inv['invite_url'], '/') + 1);
        $this->json($this->http('POST', '/admin/api/invites/' . $t . '/accept', ['browser' => $browser, 'json' => ['password' => 'a very good passphrase']]));
        return (string) $r['id'];
    }

    public function testHealthAndReadiness(): void
    {
        // Before setup: alive, ready (setup pending is not a failure: the wizard must be reachable).
        $h = $this->json($this->http('GET', '/healthz'));
        $this->assertSame('ok', $h['status']);
        $r = $this->json($this->http('GET', '/readyz'));
        $this->assertSame('ready', $r['status']);
        $this->assertSame(['database' => 'ok', 'data_dir' => 'ok', 'blob_store' => 'ok', 'setup' => 'pending'], $r['checks']);

        $this->freshNode();
        $this->assertSame('done', $this->json($this->http('GET', '/readyz'))['checks']['setup']);

        // A database that is behind the code (another process has not migrated yet) is not ready.
        $this->app->db->run("UPDATE obx_settings SET v = '1' WHERE k = 'schema_version'");
        $res = $this->http('GET', '/readyz');
        $body = $this->json($res, 503);
        $this->assertSame('not_ready', $body['status']);
        $this->assertStringContainsString('schema version 1', $body['checks']['database']);
    }

    public function testCorsOnlyOnTheDiscoveryDocument(): void
    {
        $this->kestrelNode();
        [, $id, $secret] = $this->retailerClient();
        $token = $this->accessToken($id, $secret);
        $disc = $this->http('GET', '/.well-known/obx');
        $this->assertSame('*', $disc->getHeader('Access-Control-Allow-Origin'));
        $pre = $this->http('OPTIONS', '/.well-known/obx');
        $this->assertSame(204, $pre->status);
        $this->assertSame('*', $pre->getHeader('Access-Control-Allow-Origin'));

        $api = $this->http('GET', '/obx/v1/products', ['bearer' => $token]);
        $this->assertSame(200, $api->status);
        $this->assertNull($api->getHeader('Access-Control-Allow-Origin'));
        $this->assertSame('0.1', $api->getHeader('OBX-Version'));
        $prefl = $this->http('OPTIONS', '/obx/v1/products');
        $this->assertNull($prefl->getHeader('Access-Control-Allow-Origin'));
        $this->assertGreaterThanOrEqual(400, $prefl->status);
    }

    public function testRequestIdsAndSecurityHeaders(): void
    {
        $this->freshNode();
        $given = $this->http('GET', '/.well-known/obx', ['headers' => ['X-Request-Id' => 'lb-trace-12345678']]);
        $this->assertSame('lb-trace-12345678', $given->getHeader('X-Request-Id'));
        $bad = $this->http('GET', '/.well-known/obx', ['headers' => ['X-Request-Id' => "evil\nheader"]]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{24}$/', (string) $bad->getHeader('X-Request-Id'));
        $other = $this->http('GET', '/.well-known/obx');
        $this->assertNotSame($bad->getHeader('X-Request-Id'), $other->getHeader('X-Request-Id'));

        foreach (['/.well-known/obx', '/admin/api/session', '/obx/v1/products'] as $path) {
            $res = $this->http('GET', $path);
            $this->assertSame('nosniff', $res->getHeader('X-Content-Type-Options'), $path);
            $this->assertSame('DENY', $res->getHeader('X-Frame-Options'), $path);
            $this->assertStringContainsString("frame-ancestors 'none'", (string) $res->getHeader('Content-Security-Policy'), $path);
            $this->assertSame('strict-origin-when-cross-origin', $res->getHeader('Referrer-Policy'), $path);
            $this->assertNull($res->getHeader('Strict-Transport-Security'), 'no HSTS over plain http');
        }
        $ui = $this->http('GET', '/login', ['headers' => ['Accept' => 'text/html']]);
        $this->assertStringContainsString("script-src 'self'", (string) $ui->getHeader('Content-Security-Policy'));
        $tls = $this->http('GET', '/.well-known/obx', ['scheme' => 'https']);
        $this->assertSame('max-age=63072000', $tls->getHeader('Strict-Transport-Security'));
    }

    public function testMetricsNeedTheirToken(): void
    {
        $this->kestrelNode();
        $this->assertProblem($this->http('GET', '/metrics'), 404, 'not_found');
        $this->app = $this->makeApp(['metrics_token' => 'metrics-token-0123456789']);
        $this->assertProblem($this->http('GET', '/metrics'), 401, 'unauthorized');
        $this->assertProblem($this->http('GET', '/metrics', ['bearer' => 'wrong-token-0123456789']), 401, 'unauthorized');
        $res = $this->http('GET', '/metrics', ['bearer' => 'metrics-token-0123456789']);
        $this->assertSame(200, $res->status);
        $this->assertStringStartsWith('text/plain; version=0.0.4', (string) $res->getHeader('Content-Type'));
        $text = $res->content();
        $visible = (int) $this->app->db->value('SELECT COUNT(*) FROM obx_products WHERE visible = 1');
        $this->assertStringContainsString('obx_catalog_objects{type="product",visible="true"} ' . $visible, $text);
        $this->assertStringContainsString('obx_build_info{software="obx-node-php"', $text);
        $this->assertMatchesRegularExpression('/^obx_feed_last_seq [1-9]\d*$/m', $text);
    }

    public function testAuditLogRecordsAdminActionsWithoutSecrets(): void
    {
        $this->freshNode();
        $this->app->db->run('DELETE FROM obx_audit_log');
        // A failed login, a successful one, a settings change, a key created, a retailer deleted.
        $this->http('GET', '/admin/api/session', ['browser' => 'other']);
        $this->http('POST', '/admin/api/session/login', ['browser' => 'other', 'json' => ['email' => 'admin@kestrel.example', 'password' => 'wrong password here']]);
        $this->json($this->http('PATCH', '/admin/api/settings', ['browser' => 'admin', 'json' => ['company' => ['website' => 'https://kestrel.example']]]));
        $secret = $this->ingestToken();
        $r = $this->json($this->http('POST', '/admin/api/retailers', ['browser' => 'admin', 'json' => ['name' => 'Gone Shop']]), 201);
        $this->assertSame(204, $this->http('DELETE', '/admin/api/retailers/' . $r['id'], ['browser' => 'admin'])->status);
        // Reads and portal browsing are not actions.
        $this->http('GET', '/admin/api/retailers', ['browser' => 'admin']);

        $log = $this->json($this->http('GET', '/admin/api/audit-log', ['browser' => 'admin']));
        $actions = array_column($log['items'], 'action');
        $this->assertSame([
            'DELETE /admin/api/retailers/{id}',
            'POST /admin/api/retailers',
            'POST /admin/api/ingest-tokens',
            'PATCH /admin/api/settings',
            'POST /admin/api/session/login',
        ], $actions);
        $failed = $log['items'][4];
        $this->assertSame(401, $failed['status']);
        $this->assertSame('anonymous', $failed['actor']['type']);
        $this->assertSame('admin@kestrel.example', $failed['actor']['name']);
        $this->assertSame('invalid_credentials', $failed['detail']['error']);
        $this->assertSame($this->ip, $failed['ip']);
        $settings = $log['items'][3];
        $this->assertSame('admin', $settings['actor']['type']);
        $this->assertSame(['company'], $settings['detail']['fields']);
        $this->assertSame(['type' => 'retailers', 'id' => $r['id']], $log['items'][0]['target']);
        $raw = Json::encode($this->app->db->all('SELECT * FROM obx_audit_log'));
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertStringNotContainsString('wrong password', $raw);
        $this->assertStringNotContainsString('kestrel.example"', str_replace('admin@kestrel.example', '', $raw), 'values are never logged');

        // Paging and the admin-only rule.
        $page = $this->json($this->http('GET', '/admin/api/audit-log', ['browser' => 'admin', 'query' => ['limit' => '2']]));
        $this->assertCount(2, $page['items']);
        $next = $this->json($this->http('GET', '/admin/api/audit-log', ['browser' => 'admin', 'query' => ['limit' => '2', 'cursor' => $page['next_cursor']]]));
        $this->assertSame('POST /admin/api/ingest-tokens', $next['items'][0]['action']);
        $this->retailerUser('shop', ['name' => 'Curious Shop']);
        $this->assertProblem($this->http('GET', '/admin/api/audit-log', ['browser' => 'shop']), 403, 'forbidden');
    }

    public function testGdprExportAndDeletion(): void
    {
        $this->kestrelNode();
        $rid = $this->retailerUser('buyer', ['name' => 'Privacy Shop', 'reference' => 'C-77']);
        $asset = (string) $this->app->db->value("SELECT id FROM obx_assets WHERE external_id = 'KO-1001_NVY_1'");
        $this->assertSame(200, $this->http('GET', '/admin/api/portal/assets/' . $asset . '/download', ['browser' => 'buyer', 'query' => ['variant' => 'web']])->status);
        $this->json($this->http('POST', '/admin/api/api-clients', ['browser' => 'admin', 'json' => ['retailer_id' => $rid, 'name' => 'Shop sync']]), 201);

        $res = $this->http('GET', '/admin/api/retailers/' . $rid . '/export', ['browser' => 'admin']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('attachment; filename="retailer-', (string) $res->getHeader('Content-Disposition'));
        $export = Json::decode($res->content());
        $this->assertSame('Privacy Shop', $export['retailer']['name']);
        $this->assertSame('C-77', $export['retailer']['reference']);
        $this->assertSame('buyer@shop.example', $export['accounts'][0]['email']);
        $this->assertSame('Shop sync', $export['api_clients'][0]['name']);
        $this->assertArrayNotHasKey('secret_hash', $export['api_clients'][0]);
        $this->assertStringNotContainsString('password_hash', $res->content());
        $this->assertSame('image', $export['downloads'][0]['kind']);
        $this->assertSame('Kestrel Outfitters', $export['controller']['name']);

        // The retailer's own copy of its account data.
        $mine = Json::decode($this->http('GET', '/admin/api/me/export', ['browser' => 'buyer'])->content());
        $this->assertSame('buyer@shop.example', $mine['account']['email']);
        $this->assertCount(1, $mine['downloads']);
        $this->assertProblem($this->http('GET', '/admin/api/retailers/' . $rid . '/export', ['browser' => 'buyer']), 403, 'forbidden');

        // Deleting the retailer removes the personal data everywhere; statistics keep the company.
        $uid = (string) $this->app->db->value("SELECT id FROM obx_users WHERE email = 'buyer@shop.example'");
        $this->app->db->insert('obx_access_requests', ['id' => 'ar_x', 'token_hash' => 'h', 'status' => 'approved', 'input' => Json::encode(['contact' => ['email' => 'buyer@shop.example']]), 'retailer_id' => $rid, 'created_at' => Clock::iso(), 'claimed' => 1, 'portal_invited' => 0]);
        $this->assertSame(204, $this->http('DELETE', '/admin/api/retailers/' . $rid, ['browser' => 'admin'])->status);
        $all = Json::encode([
            $this->app->db->all('SELECT * FROM obx_users'),
            $this->app->db->all('SELECT * FROM obx_download_events'),
            $this->app->db->all('SELECT * FROM obx_access_requests'),
            $this->app->db->all('SELECT actor_name, ip, actor_id FROM obx_audit_log WHERE actor_id = ?', [$uid]),
        ]);
        $this->assertStringNotContainsString('buyer@shop.example', $all);
        $this->assertSame('Privacy Shop', $this->app->db->value('SELECT retailer_name FROM obx_download_events LIMIT 1'));
        $this->assertNull($this->app->db->value('SELECT ip FROM obx_audit_log WHERE actor_id = ? AND ip IS NOT NULL', [$uid]));
    }

    public function testRetentionSettingsAndSweep(): void
    {
        $this->freshNode();
        $s = $this->json($this->http('GET', '/admin/api/settings', ['browser' => 'admin']));
        $this->assertSame(['download_stats_days' => 730, 'audit_log_days' => 365, 'ip_days' => 30, 'access_requests_days' => 365], $s['retention']);
        $this->assertProblem($this->http('PATCH', '/admin/api/settings', ['browser' => 'admin', 'json' => ['retention' => ['ip_days' => 90]]]), 422, 'validation_failed');
        $this->assertProblem($this->http('PATCH', '/admin/api/settings', ['browser' => 'admin', 'json' => ['retention' => ['forever' => 1]]]), 422, 'validation_failed');
        $s = $this->json($this->http('PATCH', '/admin/api/settings', ['browser' => 'admin', 'json' => ['retention' => ['download_stats_days' => 30, 'ip_days' => 7]]]));
        $this->assertSame(30, $s['retention']['download_stats_days']);
        $this->assertSame(365, $s['retention']['audit_log_days']);

        $now = Clock::time();
        $event = static fn (string $id, int $ts) => ['id' => $id, 'at' => Clock::iso((float) $ts), 'at_ts' => $ts, 'kind' => 'image', 'actor_type' => 'user', 'files' => 1];
        $this->app->db->insert('obx_download_events', $event('ev_old', $now - 31 * 86400));
        $this->app->db->insert('obx_download_events', $event('ev_new', $now - 29 * 86400));
        $this->app->audit->record('admin', 'u1', 'a@b.example', 'TEST old', null, null, 200, [], '198.51.100.7');
        $this->app->db->run("UPDATE obx_audit_log SET at_ts = ? WHERE action = 'TEST old'", [$now - 8 * 86400]);
        $this->app->audit->record('admin', 'u1', 'a@b.example', 'TEST new', null, null, 200, [], '198.51.100.8');
        $this->app->db->insert('obx_access_requests', ['id' => 'ar_old', 'token_hash' => 'h1', 'status' => 'rejected', 'input' => '{}', 'created_at' => Clock::iso((float) ($now - 400 * 86400)), 'decided_at' => Clock::iso((float) ($now - 400 * 86400)), 'claimed' => 0, 'portal_invited' => 0]);
        $this->app->db->insert('obx_access_requests', ['id' => 'ar_open', 'token_hash' => 'h2', 'status' => 'pending', 'input' => '{}', 'created_at' => Clock::iso((float) ($now - 400 * 86400)), 'claimed' => 0, 'portal_invited' => 0]);

        $done = $this->app->retention->sweep();
        $this->assertSame(1, $done['download_events']);
        $this->assertSame(['ev_new'], $this->app->db->column('SELECT id FROM obx_download_events'));
        $this->assertNull($this->app->db->value("SELECT ip FROM obx_audit_log WHERE action = 'TEST old'"));
        $this->assertSame('198.51.100.8', $this->app->db->value("SELECT ip FROM obx_audit_log WHERE action = 'TEST new'"));
        $this->assertSame(['ar_open'], $this->app->db->column('SELECT id FROM obx_access_requests'));
    }

    public function testBackupAndRestoreGiveTheSameNode(): void
    {
        ['ingest' => $ingest] = $this->kestrelNode();
        [, $id, $secret] = $this->retailerClient(['name' => 'Backup Shop']);
        $token = $this->accessToken($id, $secret);
        [$before] = $this->drainSnapshot($token);
        $asset = $this->json($this->http('GET', '/obx/v1/assets/' . (string) $this->app->db->value("SELECT id FROM obx_assets WHERE external_id = 'KO-1001_NVY_1'"), ['bearer' => $token]));

        $file = $this->dir . '/backup.zip';
        $m = (new Backup($this->app))->create($file);
        $this->assertSame(Schema::latestVersion(), $m['schema_version']);
        $this->assertGreaterThan(0, $m['files']);
        $this->assertSame([], $m['files_missing']);

        // A new, empty node elsewhere (other data folder, other database, no secret key set).
        $other = $this->dir . '/restored';
        mkdir($other);
        $config = Config::load(dirname(__DIR__, 2), [
            'installed' => true, 'public_url' => 'http://localhost', 'secret_key' => null, 'setup_code' => null,
            'db_dsn' => 'sqlite:' . $other . '/obx.sqlite', 'data_dir' => $other . '/data', 'jobs_inline' => true,
            'mail_transport' => 'memory', 'mail_from' => 'portal@kestrel.example', 'ui_dir' => $other . '/ui', 'rate_limit_factor' => 100.0,
        ]);
        $restored = Backup::restore($config, $file);
        $this->assertSame($m['files'], $restored['files_restored']);
        $this->expectRestoreRefusedOnSecondRun($config, $file);

        $original = $this->app;
        $this->app = new App($config);
        // The same key: the API client, its token and the ingest token still work.
        $token2 = $this->accessToken($id, $secret);
        [$after] = $this->drainSnapshot($token2);
        $this->assertSame(Json::encode($before), Json::encode($after));
        $again = $this->json($this->http('GET', '/obx/v1/assets/' . $asset['id'], ['bearer' => $token2]));
        $web = $this->http('GET', (string) parse_url($again['renditions']['web']['url'], PHP_URL_PATH) . '?' . parse_url($again['renditions']['web']['url'], PHP_URL_QUERY));
        $this->assertSame(200, $web->status);
        $this->assertSame($asset['renditions']['web']['sha256'], hash('sha256', $web->content()));
        $this->assertSame(200, $this->http('GET', '/obx/v1/ingest', ['bearer' => $ingest])->status);
        $this->http('GET', '/admin/api/session', ['browser' => 'x']);
        $login = $this->http('POST', '/admin/api/session/login', ['browser' => 'x', 'json' => ['email' => 'admin@kestrel.example', 'password' => 'correct horse battery']]);
        $this->assertSame(200, $login->status, $login->content());
        $this->app = $original;
    }

    private function expectRestoreRefusedOnSecondRun(Config $config, string $file): void
    {
        try {
            Backup::restore($config, $file);
            $this->fail('A second restore into a portal must be refused without --force.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already holds a portal', $e->getMessage());
        }
    }

    public function testConfigurationProblemsAreNamed(): void
    {
        $config = Config::load(dirname(__DIR__, 2), [
            'installed' => true, 'public_url' => 'brands.example.com', 'secret_key' => 'short', 'db_dsn' => 'postgres:x',
            'blob_store' => 's3', 'mail_transport' => 'smtp', 'smtp_host' => null, 'renditions' => 'sometimes',
            'max_upload_bytes' => 10, 'rate_limit_factor' => 0, 'trusted_proxies' => '10.0.0.0/8, nonsense',
        ]);
        $problems = $config->validate();
        $text = implode("\n", $problems);
        foreach (['OBX_PUBLIC_URL', 'OBX_SECRET_KEY', 'OBX_DB_DSN', 'OBX_S3_BUCKET', 'OBX_S3_ACCESS_KEY_ID', 'OBX_SMTP_HOST', 'OBX_RENDITIONS', 'OBX_MAX_UPLOAD_BYTES', 'OBX_RATE_LIMIT_FACTOR', '"nonsense"'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $this->expectException(ConfigInvalid::class);
        new App($config);
    }

    public function testDatabaseFromANewerReleaseIsRefused(): void
    {
        $this->freshNode();
        $this->app->db->run("UPDATE obx_settings SET v = ? WHERE k = 'schema_version'", [(string) (Schema::latestVersion() + 1)]);
        $this->expectException(SchemaTooNew::class);
        $this->expectExceptionMessage('newer release');
        Schema::migrate($this->app->db);
    }

    public function testMigrationFromTheFirstSchemaKeepsData(): void
    {
        $this->freshNode();
        // Simulate a node installed with schema version 1.
        $this->app->db->pdo->exec('DROP TABLE obx_audit_log');
        $this->app->db->run("UPDATE obx_settings SET v = '1' WHERE k = 'schema_version'");
        $app = $this->makeApp();
        $this->assertSame(Schema::latestVersion(), Schema::currentVersionOf($app->db));
        $this->assertSame('Kestrel Outfitters', $app->settings->all()['company']['name']);
        $this->assertSame(0, (int) $app->db->value('SELECT COUNT(*) FROM obx_audit_log'));
    }

    public function testClientAddressBehindTrustedProxies(): void
    {
        $this->assertSame('203.0.113.9', Request::clientAddress('10.1.2.3', '203.0.113.9', 'private'));
        $this->assertSame('203.0.113.9', Request::clientAddress('10.1.2.3', '198.51.100.1, 203.0.113.9, 10.0.0.5', '10.0.0.0/8'));
        $this->assertSame('10.1.2.3', Request::clientAddress('10.1.2.3', '203.0.113.9', null), 'no proxies trusted: the peer');
        $this->assertSame('198.51.100.20', Request::clientAddress('198.51.100.20', '203.0.113.9', '10.0.0.0/8'), 'an untrusted peer cannot claim another address');
        $this->assertSame('2001:db8::1', Request::clientAddress('::1', '2001:db8::1', '::1'));
        $this->assertSame('10.1.2.3', Request::clientAddress('10.1.2.3', 'garbage', '*'));
    }

    public function testInterruptedJobIsPickedUpAgainAndSucceeds(): void
    {
        $this->freshNode();
        $ingest = $this->ingestToken();
        $this->app = $this->makeApp(['jobs_inline' => false]);
        $res = $this->http('POST', '/obx/v1/ingest/imports', [
            'bearer' => $ingest,
            'files' => ['package' => [$this->upload($this->kestrelZip(), 'kestrel.zip', 'application/zip')]],
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=x'],
        ]);
        $job = $this->json($res, 202);
        // The worker that claimed it died (deploy, OOM): running, lease in the past.
        $this->app->db->update('obx_jobs', ['status' => 'running', 'attempts' => 1, 'lease_ts' => Clock::time() - 1], ['id' => $job['id']]);
        $done = $this->app->worker()->tick(30);
        $this->assertSame(1, $done['jobs']);
        $row = $this->app->db->one('SELECT status, attempts FROM obx_jobs WHERE id = ?', [$job['id']]);
        $this->assertSame('succeeded', $row['status']);
        $this->assertSame(2, (int) $row['attempts']);
        $this->assertSame(8, (int) $this->app->db->value('SELECT COUNT(*) FROM obx_products'));
    }
}
