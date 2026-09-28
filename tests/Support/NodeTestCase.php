<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Support;

use Obx\Node\App;
use Obx\Node\Config;
use Obx\Node\Http\Request;
use Obx\Node\Http\Response;
use Obx\Node\Support\Json;
use PHPUnit\Framework\TestCase;

/**
 * Boots a fresh node (SQLite in a temp directory) and talks to it through the full HTTP
 * pipeline (router, auth, controllers), like a browser or an API client would.
 */
abstract class NodeTestCase extends TestCase
{
    public const SETUP_CODE = 'TEST-CODE-2345';
    protected App $app;
    protected string $dir;
    /** @var array<string, array<string, string>> cookie jars per browser name */
    protected array $jars = [];
    protected string $ip = '203.0.113.10';
    /** MySQL test database name when OBX_TEST_MYSQL_DSN is set (else SQLite). */
    private ?string $mysqlDb = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/obx-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
        $this->createMysqlDb();
        $this->app = $this->makeApp();
    }

    /** Throws the node away and starts an empty one (not set up). */
    protected function resetNode(): void
    {
        if ($this->mysqlDb !== null) {
            $this->dropMysqlDb();
        }
        unset($this->app);
        $this->rmrf($this->dir);
        mkdir($this->dir, 0777, true);
        $this->jars = [];
        $this->createMysqlDb();
        $this->app = $this->makeApp();
    }

    private static function mysqlDsn(): ?string
    {
        $dsn = getenv('OBX_TEST_MYSQL_DSN');
        return $dsn !== false && $dsn !== '' ? $dsn : null;
    }

    private function mysqlPdo(): \PDO
    {
        return new \PDO((string) self::mysqlDsn(), getenv('OBX_TEST_MYSQL_USER') ?: 'root', getenv('OBX_TEST_MYSQL_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    private function dropMysqlDb(): void
    {
        $admin = $this->mysqlPdo();
        if (isset($this->app)) {
            // The node's connection may still hold locks; end it before dropping the database.
            $cid = (int) $this->app->db->value('SELECT CONNECTION_ID()');
            try {
                $admin->exec('KILL ' . $cid);
            } catch (\PDOException) {
            }
        }
        $admin->exec('DROP DATABASE IF EXISTS ' . $this->mysqlDb);
    }

    private function createMysqlDb(): void
    {
        if (self::mysqlDsn() === null) {
            return;
        }
        $this->mysqlDb = 'obx_t_' . bin2hex(random_bytes(5));
        $this->mysqlPdo()->exec('CREATE DATABASE ' . $this->mysqlDb . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    }

    /** @param array<string, mixed> $overrides */
    protected function makeApp(array $overrides = []): App
    {
        $root = dirname(__DIR__, 2);
        $config = Config::load($root, array_replace([
            'installed' => true,
            'public_url' => 'http://localhost',
            'secret_key' => 'test-secret-key-0123456789abcdef-0123456789abcdef',
            'setup_code' => self::SETUP_CODE,
            'db_dsn' => $this->mysqlDb !== null ? self::mysqlDsn() . ';dbname=' . $this->mysqlDb . ';charset=utf8mb4' : 'sqlite:' . $this->dir . '/obx.sqlite',
            'db_user' => $this->mysqlDb !== null ? (getenv('OBX_TEST_MYSQL_USER') ?: 'root') : null,
            'db_password' => $this->mysqlDb !== null ? (getenv('OBX_TEST_MYSQL_PASSWORD') ?: '') : null,
            'data_dir' => $this->dir . '/data',
            'jobs_inline' => true,
            'mail_transport' => 'memory',
            'mail_from' => 'portal@kestrel.example',
            'allow_private_urls' => true,
            'rate_limit_factor' => 100.0,
            'ui_dir' => $this->dir . '/ui',
            'locales_dir' => null,
        ], $overrides));
        return new App($config);
    }

    protected function tearDown(): void
    {
        \Obx\Node\Support\Clock::$offset = 0.0;
        if ($this->mysqlDb !== null) {
            $this->dropMysqlDb();
        }
        $this->rmrf($this->dir);
        parent::tearDown();
    }

    protected function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /** @var array<string, string> snapshot name => template directory */
    private static array $snapshots = [];

    /**
     * Restores a node state built once per test process (e.g. "set up + Kestrel imported"),
     * so slow steps (image rendering, password hashing) do not repeat for every test.
     * $build runs on a fresh node and returns extra values to keep (tokens etc.).
     *
     * @param callable(): array<string, mixed> $build
     * @return array<string, mixed> the values $build returned
     */
    protected function snapshot(string $name, callable $build): array
    {
        if ($this->mysqlDb !== null) {
            return $build();
        }
        if (!isset(self::$snapshots[$name])) {
            $values = $build();
            $template = sys_get_temp_dir() . '/obx-snap-' . $name . '-' . getmypid() . '-' . bin2hex(random_bytes(3));
            $this->copyDir($this->dir, $template);
            file_put_contents($template . '/_snapshot.json', Json::encode(['values' => $values, 'jars' => $this->jars]));
            self::$snapshots[$name] = $template;
            return $values;
        }
        $template = self::$snapshots[$name];
        unset($this->app);
        gc_collect_cycles();
        $this->rmrf($this->dir);
        $this->copyDir($template, $this->dir);
        $meta = Json::decode((string) file_get_contents($this->dir . '/_snapshot.json'));
        $this->jars = $meta['jars'];
        $this->app = $this->makeApp();
        return $meta['values'];
    }

    private function copyDir(string $from, string $to): void
    {
        @mkdir($to, 0777, true);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $target = $to . substr($f->getPathname(), strlen($from));
            if ($f->isDir()) {
                @mkdir($target, 0777, true);
            } else {
                copy($f->getPathname(), $target);
            }
        }
    }

    /** Setup done, Kestrel imported; returns ['ingest' => token]. */
    protected function kestrelNode(): array
    {
        return $this->snapshot('kestrel', function (): array {
            $this->setupNode();
            $ingest = $this->ingestToken();
            $job = $this->importKestrel($ingest);
            $this->assertSame('succeeded', $job['status'], Json::encode($job));
            $this->assertSame(0, $job['import_run']['counts']['errors'], Json::encode($job['import_run']));
            if ($this->app->db->isSqlite()) {
                $this->app->db->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            }
            return ['ingest' => $ingest];
        });
    }

    /** Setup done (empty catalog, first brand "kestrel"). */
    protected function freshNode(): array
    {
        return $this->snapshot('fresh', function (): array {
            $this->setupNode();
            if ($this->app->db->isSqlite()) {
                $this->app->db->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            }
            return [];
        });
    }

    public static function fixtures(string $path = ''): string
    {
        return dirname(__DIR__) . '/fixtures/spec' . ($path !== '' ? '/' . $path : '');
    }

    // ================================================================ HTTP

    /**
     * @param array<string, mixed> $o json, body, headers, query, post, files, browser, bearer, basic
     */
    protected function http(string $method, string $uri, array $o = []): Response
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $rawQuery = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');
        $query = Request::parseQuery($rawQuery);
        foreach ((array) ($o['query'] ?? []) as $k => $v) {
            $query[$k] = $v;
        }
        $headers = (array) ($o['headers'] ?? []);
        $body = $o['body'] ?? null;
        if (array_key_exists('json', $o)) {
            $body = Json::encode($o['json']);
            $headers['Content-Type'] ??= 'application/json';
        }
        if (isset($o['bearer'])) {
            $headers['Authorization'] = 'Bearer ' . $o['bearer'];
        }
        if (isset($o['basic'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode($o['basic'][0] . ':' . $o['basic'][1]);
        }
        $browser = $o['browser'] ?? null;
        $cookies = $browser !== null ? ($this->jars[$browser] ?? []) : [];
        if ($browser !== null && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !isset($headers['X-CSRF-Token']) && ($o['csrf'] ?? true)) {
            $headers['X-CSRF-Token'] = $this->jars[$browser . ':csrf'] ?? ($cookies['obx_csrf'] ?? '');
        }
        if ($body !== null) {
            $headers['Content-Length'] = (string) strlen((string) $body);
        }
        $req = new Request(
            method: $method,
            path: $path,
            query: $query,
            headers: $headers,
            cookies: $cookies,
            body: $body !== null ? (string) $body : '',
            post: (array) ($o['post'] ?? []),
            files: (array) ($o['files'] ?? []),
            ip: (string) ($o['ip'] ?? $this->ip),
            scheme: (string) ($o['scheme'] ?? 'http'),
            host: (string) ($o['host'] ?? 'localhost'),
            basePath: '',
            rawQuery: $rawQuery,
        );
        $res = $this->app->handle($req);
        if ($browser !== null) {
            foreach ($res->cookies as $c) {
                [$pair] = explode(';', $c, 2);
                [$name, $value] = explode('=', $pair, 2);
                $name = rawurldecode($name);
                if ($value === '' || str_contains($c, 'Max-Age=0')) {
                    unset($this->jars[$browser][$name]);
                } else {
                    $this->jars[$browser][$name] = rawurldecode($value);
                }
            }
            $json = $res->getHeader('Content-Type') !== null && str_contains((string) $res->getHeader('Content-Type'), 'json') && $res->file === null && $res->stream === null ? Json::decode($res->body) : null;
            if (is_array($json) && isset($json['csrf_token'])) {
                $this->jars[$browser . ':csrf'] = (string) $json['csrf_token'];
            }
        }
        return $res;
    }

    /** @return array<string, mixed> */
    protected function json(Response $res, int $status = 200): array
    {
        $body = $res->content();
        $this->assertSame($status, $res->status, 'Unexpected status ' . $res->status . ': ' . substr($body, 0, 2000));
        $v = Json::decode($body);
        $this->assertIsArray($v, 'Body is not JSON: ' . substr($body, 0, 500));
        return $v;
    }

    protected function assertProblem(Response $res, int $status, string $code): array
    {
        $body = $res->content();
        $this->assertSame($status, $res->status, 'Unexpected status ' . $res->status . ': ' . substr($body, 0, 1000));
        $this->assertStringContainsString('application/problem+json', (string) $res->getHeader('Content-Type'));
        $p = Json::decode($body);
        $this->assertSame($code, $p['obx_error'] ?? null, $body);
        $this->assertSame('https://openbrandexchange.com/errors/' . $code, $p['type']);
        return $p;
    }

    // ================================================================ fixtures of state

    /** Completes first-run setup as in the conformance reference path; logs in browser "admin". */
    protected function setupNode(): void
    {
        $this->http('GET', '/admin/api/session', ['browser' => 'admin']);
        $res = $this->http('POST', '/admin/api/setup', ['browser' => 'admin', 'json' => [
            'setup_code' => self::SETUP_CODE,
            'company' => ['name' => 'Kestrel Outfitters', 'first_brand_name' => 'Kestrel', 'default_language' => 'nl', 'languages' => ['nl', 'en']],
            'admin' => ['name' => 'Ada Admin', 'email' => 'admin@kestrel.example', 'password' => 'correct horse battery'],
        ]]);
        $this->json($res, 201);
    }

    /** @param list<string> $scopes @return string ingest token secret */
    protected function ingestToken(array $scopes = ['ingest:read', 'ingest:write', 'ingest:delete'], ?array $brands = null): string
    {
        $res = $this->http('POST', '/admin/api/ingest-tokens', ['browser' => 'admin', 'json' => ['name' => 'Test automation', 'scopes' => $scopes, 'brand_external_ids' => $brands]]);
        return (string) $this->json($res, 201)['secret'];
    }

    /**
     * Creates a retailer with an API client; returns [retailer id, client_id, client_secret].
     *
     * @param array<string, mixed> $retailer
     * @return array{0: string, 1: string, 2: string}
     */
    protected function retailerClient(array $retailer = ['name' => 'Shop One'], ?array $scopes = null): array
    {
        $r = $this->json($this->http('POST', '/admin/api/retailers', ['browser' => 'admin', 'json' => $retailer]), 201);
        $body = ['retailer_id' => $r['id'], 'name' => 'Webshop'];
        if ($scopes !== null) {
            $body['scopes'] = $scopes;
        }
        $c = $this->json($this->http('POST', '/admin/api/api-clients', ['browser' => 'admin', 'json' => $body]), 201);
        return [(string) $r['id'], (string) $c['client']['client_id'], (string) $c['client_secret']];
    }

    protected function accessToken(string $clientId, string $secret, ?string $scope = null): string
    {
        $o = ['basic' => [$clientId, $secret], 'body' => 'grant_type=client_credentials' . ($scope !== null ? '&scope=' . rawurlencode($scope) : ''), 'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']];
        parse_str((string) $o['body'], $post);
        $o['post'] = $post;
        return (string) $this->json($this->http('POST', '/obx/v1/oauth/token', $o))['access_token'];
    }

    /** @return array{name: string, tmp: string, size: int, type: string} */
    protected function upload(string $path, string $name, string $type = 'application/octet-stream'): array
    {
        $tmp = $this->dir . '/up-' . bin2hex(random_bytes(4));
        copy($path, $tmp);
        return ['name' => $name, 'tmp' => $tmp, 'size' => (int) filesize($tmp), 'type' => $type];
    }

    /** Builds the Kestrel package zip (products.csv + assets.csv + images/). */
    protected function kestrelZip(?string $productsCsv = null, array $extra = []): string
    {
        $zipPath = $this->dir . '/kestrel-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('products.csv', $productsCsv ?? (string) file_get_contents(self::fixtures('kestrel/products.csv')));
        $zip->addFile(self::fixtures('kestrel/assets.csv'), 'assets.csv');
        foreach (glob(self::fixtures('kestrel/images/*.png')) as $img) {
            $zip->addFile($img, 'images/' . basename($img));
        }
        foreach ($extra as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $zipPath;
    }

    /** Imports the Kestrel fixtures through the Ingest API as a package; returns the job. */
    protected function importKestrel(string $token, array $options = []): array
    {
        $res = $this->http('POST', '/obx/v1/ingest/imports', [
            'bearer' => $token,
            'files' => ['package' => [$this->upload($this->kestrelZip(), 'kestrel.zip', 'application/zip')]],
            'post' => $options !== [] ? ['options' => Json::encode($options)] : [],
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=x'],
        ]);
        $job = $this->json($res, 202);
        return $this->json($this->http('GET', '/obx/v1/ingest/jobs/' . $job['id'], ['bearer' => $token]));
    }

    /**
     * Reads /changes until the snapshot is complete; returns [entries, live cursor].
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    protected function drainSnapshot(string $token, int $limit = 500): array
    {
        $entries = [];
        $cursor = null;
        for ($i = 0; $i < 1000; $i++) {
            $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $token, 'query' => array_filter(['cursor' => $cursor, 'limit' => (string) $limit])]));
            $cursor = $page['next_cursor'];
            if ($page['phase'] === 'live') {
                // The live page may already contain entries; keep them out of the snapshot list.
                return [$entries, $cursor, $page['changes']];
            }
            foreach ($page['changes'] as $c) {
                $entries[] = $c;
            }
        }
        $this->fail('Snapshot did not finish.');
    }

    /**
     * Structural match with the spec fixture placeholders.
     *
     * @param array<string, mixed>|list<mixed>|scalar|null $expected
     */
    protected function assertMatches(mixed $expected, mixed $actual, string $path = '$', array $products = []): void
    {
        if (is_string($expected) && preg_match('/^\{\{(.+)\}\}$/', $expected, $m)) {
            $ph = $m[1];
            if (str_starts_with($ph, 'product:')) {
                $this->assertArrayHasKey(substr($ph, 8), $products, $path);
                $this->assertMatches($products[substr($ph, 8)], $actual, $path, $products);
                return;
            }
            match ($ph) {
                'id' => $this->assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9._~-]{0,127}$/', (string) $actual, $path),
                'datetime' => $this->assertNotNull(\Obx\Node\Support\Clock::parse((string) $actual), $path . ' is not a date-time'),
                'datetime|null' => $actual === null ? $this->addToAssertionCount(1) : $this->assertNotNull(\Obx\Node\Support\Clock::parse((string) $actual), $path),
                'url' => $this->assertMatchesRegularExpression('#^https?://#', (string) $actual, $path),
                'sha256' => $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $actual, $path),
                'int' => $this->assertIsInt($actual, $path),
                default => $this->fail('Unknown placeholder ' . $ph),
            };
            return;
        }
        if (is_array($expected)) {
            $this->assertIsArray($actual, $path);
            if (array_is_list($expected) && $expected !== []) {
                $this->assertCount(count($expected), $actual, $path);
                foreach ($expected as $i => $e) {
                    $this->assertMatches($e, $actual[$i], $path . '[' . $i . ']', $products);
                }
                return;
            }
            foreach ($expected as $k => $e) {
                $this->assertArrayHasKey($k, $actual, $path . '.' . $k);
                $this->assertMatches($e, $actual[$k], $path . '.' . $k, $products);
            }
            foreach (array_keys($actual) as $k) {
                if (!array_key_exists($k, $expected)) {
                    $this->assertStringStartsWith('x-', (string) $k, 'Unexpected key ' . $path . '.' . $k);
                }
            }
            return;
        }
        $this->assertSame($expected, $actual, $path);
    }
}
