<?php

declare(strict_types=1);

/*
 * Seeded load test (scripts/load-test.sh runs it).
 *
 *   php load.php seed <root> <out.json> [products]   inside the node: set up the node, create a
 *        retailer API key, then write <products> products (default 10 000) with 6 variants
 *        each (2 colours × 3 sizes, valid EANs) and 4 images each (2 per colour, metadata
 *        plus one shared JPEG) through the node's own write path (validation, visibility,
 *        change feed), in transactions of 50 products.
 *   php load.php bench <base-url> <in.json>           from outside: measures the read paths.
 */

use Obx\Node\App;
use Obx\Node\Config;
use Obx\Node\Http\Request;
use Obx\Node\Support\Json;

$cmd = $argv[1] ?? '';
if ($cmd === 'seed') {
    seed((string) $argv[2], (string) $argv[3], (int) ($argv[4] ?? 10000));
} elseif ($cmd === 'bench') {
    bench(rtrim((string) $argv[2], '/'), (array) json_decode((string) file_get_contents((string) $argv[3]), true));
} else {
    fwrite(STDERR, "usage: php load.php seed <root> <out.json> [products] | bench <base-url> <in.json>\n");
    exit(1);
}

function ean(int $n): string
{
    $body = '20' . str_pad((string) $n, 10, '0', STR_PAD_LEFT);
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int) $body[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    return $body . ((10 - $sum % 10) % 10);
}

function seed(string $root, string $out, int $products): void
{
    require $root . '/vendor/autoload.php';
    $app = new App(Config::load($root));
    $jar = [];
    $call = function (string $method, string $path, ?array $json = null) use ($app, &$jar): array {
        $body = $json !== null ? Json::encode($json) : '';
        $headers = ['Content-Type' => 'application/json', 'Content-Length' => (string) strlen($body)];
        if (isset($jar['obx_csrf'])) {
            $headers['X-CSRF-Token'] = $jar['obx_csrf'];
        }
        $req = new Request(method: $method, path: $path, headers: $headers, cookies: $jar, body: $body, ip: '198.51.100.1', host: 'localhost');
        $res = $app->handle($req);
        foreach ($res->cookies as $c) {
            [$pair] = explode(';', $c, 2);
            [$k, $v] = explode('=', $pair, 2);
            $jar[rawurldecode($k)] = rawurldecode($v);
        }
        if ($res->status >= 400) {
            throw new RuntimeException($method . ' ' . $path . ': ' . $res->content());
        }
        return (array) Json::decode($res->content(), []);
    };
    $call('GET', '/admin/api/session');
    $code = $app->ensureSetupCode();
    $call('POST', '/admin/api/setup', [
        'setup_code' => $code,
        'company' => ['name' => 'Load Test Brands', 'first_brand_name' => 'Loadbrand', 'default_language' => 'en', 'languages' => ['en', 'nl']],
        'admin' => ['name' => 'Load Admin', 'email' => 'admin@load.example', 'password' => 'load test password 1'],
    ]);
    $call('PATCH', '/admin/api/settings', ['portal' => ['retailer_api_self_service' => true]]);
    $retailer = $call('POST', '/admin/api/retailers', ['name' => 'Load Shop']);
    $client = $call('POST', '/admin/api/api-clients', ['retailer_id' => $retailer['id'], 'name' => 'Load bench']);

    // One small JPEG shared by every image (renditions are made once per original).
    $img = imagecreatetruecolor(1200, 1600);
    imagefilledrectangle($img, 0, 0, 1199, 1599, (int) imagecolorallocate($img, 200, 190, 170));
    $jpg = sys_get_temp_dir() . '/load-original.jpg';
    imagejpeg($img, $jpg, 85);
    $blob = $app->blobs->storeCopy($jpg);
    $sha = $blob['sha256'];

    $w = $app->writer;
    $ctx = ['actor' => 'load-test'];
    $w->upsert('brand', 'loadbrand', ['name' => 'Loadbrand'], $ctx);
    $w->upsert('brand', 'loadsport', ['name' => 'Loadsport'], $ctx);
    $types = ['sweater', 'trousers', 'jacket', 'shirt', 'dress', 'shoes', 'bag', 'scarf'];
    $colors = [['BLK', 'Black'], ['NVY', 'Navy'], ['OAT', 'Oatmeal'], ['OLV', 'Olive'], ['RST', 'Rust'], ['WHT', 'White']];
    $started = microtime(true);
    $variant = 0;
    for ($p = 1; $p <= $products; $p++) {
        $app->db->tx(function () use ($w, $ctx, $p, $types, $colors, $sha, &$variant): void {
            $style = sprintf('LT-%05d', $p);
            $w->upsert('product', $style, [
                'brand_external_id' => $p % 5 === 0 ? 'loadsport' : 'loadbrand',
                'style_number' => $style,
                'name' => ['en' => 'Load test ' . $types[$p % 8] . ' ' . $p, 'nl' => 'Laadtest ' . $types[$p % 8] . ' ' . $p],
                'item_type' => $types[$p % 8],
                'target_group' => ['women', 'men', 'unisex', 'kids'][$p % 4],
                'season' => ['SS27', 'AW27', 'SS28'][$p % 3],
                'materials' => [['part' => 'main', 'fibers' => [['fiber' => 'cotton', 'percentage' => 100]]]],
                'care' => [['code' => 'wash_30']],
            ], $ctx);
            $pair = [$colors[$p % 6], $colors[($p + 1) % 6]];
            foreach ($pair as $ci => [$code, $name]) {
                foreach (['S', 'M', 'L'] as $size) {
                    $variant++;
                    $gtin = ean($variant);
                    $w->upsert('variant', $gtin, ['product_external_id' => $style, 'gtin' => $gtin, 'color' => ['code' => $code, 'name' => ['en' => $name]], 'size' => $size], $ctx);
                }
                foreach ([1, 2] as $pos) {
                    $w->upsert('asset', $style . '_' . $code . '_' . $pos, [
                        'product_external_id' => $style, 'color_code' => $code, 'role' => 'packshot',
                        'view' => $pos === 1 ? 'front' : 'back', 'position' => $pos,
                        'files' => ['original' => ['sha256' => $sha]],
                    ], $ctx);
                }
            }
        });
        if ($p % 1000 === 0) {
            fwrite(STDERR, sprintf("  %d products (%.0f s)\n", $p, microtime(true) - $started));
        }
    }
    $seconds = microtime(true) - $started;
    $counts = [];
    foreach (['obx_products', 'obx_variants', 'obx_assets', 'obx_changes'] as $t) {
        $counts[$t] = (int) $app->db->value('SELECT COUNT(*) FROM ' . $t);
    }
    file_put_contents($out, Json::encode([
        'client_id' => $client['client']['client_id'],
        'client_secret' => $client['client_secret'],
        'admin' => ['email' => 'admin@load.example', 'password' => 'load test password 1'],
        'seed_seconds' => round($seconds, 1),
        'counts' => $counts,
        'last_gtin' => ean($variant),
        'live_cursor_from_start' => $app->feed->liveCursor(0),
        'driver' => $app->db->isSqlite() ? 'sqlite' : 'mysql',
    ], true));
    fwrite(STDERR, sprintf("Seeded %d products, %d variants, %d assets, %d change entries in %.0f s\n", $counts['obx_products'], $counts['obx_variants'], $counts['obx_assets'], $counts['obx_changes'], $seconds));
}

/** @return array{status: int, body: string, ms: float, headers: array<string, string>} */
function http(string $method, string $url, array $headers = [], ?string $body = null, ?string &$cookieJar = null): array
{
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) {
        $h[] = $k . ': ' . $v;
    }
    $resp = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$resp): int {
            $p = strpos($line, ':');
            if ($p !== false) {
                $resp[strtolower(trim(substr($line, 0, $p)))][] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        },
    ]);
    if ($cookieJar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $t = microtime(true);
    $out = (string) curl_exec($ch);
    $ms = (microtime(true) - $t) * 1000;
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $out, 'ms' => $ms, 'headers' => array_map(static fn ($v) => (string) end($v), $resp)];
}

/** @param list<float> $ms */
function stats(array $ms): array
{
    sort($ms);
    $n = count($ms);
    $q = static fn (float $f) => $ms[(int) min($n - 1, floor($f * $n))];
    return ['n' => $n, 'p50' => round($q(0.5), 1), 'p95' => round($q(0.95), 1), 'max' => round(end($ms), 1)];
}

function bench(string $base, array $in): void
{
    $rows = [];
    $check = static function (array $r, string $what): array {
        if ($r['status'] !== 200) {
            fwrite(STDERR, $what . ' answered ' . $r['status'] . ': ' . substr($r['body'], 0, 300) . "\n");
            exit(1);
        }
        return (array) json_decode($r['body'], true);
    };
    $tok = $check(http('POST', $base . '/obx/v1/oauth/token', ['Authorization' => 'Basic ' . base64_encode($in['client_id'] . ':' . $in['client_secret']), 'Content-Type' => 'application/x-www-form-urlencoded'], 'grant_type=client_credentials'), 'token');
    $auth = ['Authorization' => 'Bearer ' . $tok['access_token']];

    // Full /changes snapshot, 500 per page.
    $ms = [];
    $entries = 0;
    $cursor = null;
    $t0 = microtime(true);
    for ($i = 0; $i < 10000; $i++) {
        $r = http('GET', $base . '/obx/v1/changes?limit=500' . ($cursor !== null ? '&cursor=' . rawurlencode($cursor) : ''), $auth);
        $page = $check($r, 'changes');
        $ms[] = $r['ms'];
        $entries += count($page['changes']);
        $cursor = $page['next_cursor'];
        if ($page['phase'] === 'live') {
            break;
        }
    }
    $rows['GET /obx/v1/changes?limit=500 (full snapshot)'] = stats($ms) + ['note' => sprintf('%d entries in %d pages, %.1f s total', $entries, count($ms), microtime(true) - $t0)];

    // The same history as live pages (a consumer catching up on changes), 500 per page.
    $ms = [];
    $entries = 0;
    $cursor = $in['live_cursor_from_start'];
    $t0 = microtime(true);
    for ($i = 0; $i < 10000; $i++) {
        $r = http('GET', $base . '/obx/v1/changes?limit=500&cursor=' . rawurlencode((string) $cursor), $auth);
        $page = $check($r, 'changes (live)');
        $ms[] = $r['ms'];
        $entries += count($page['changes']);
        $cursor = $page['next_cursor'];
        if (empty($page['has_more'])) {
            break;
        }
    }
    $rows['GET /obx/v1/changes?limit=500 (live, from the start)'] = stats($ms) + ['note' => sprintf('%d entries in %d pages, %.1f s total', $entries, count($ms), microtime(true) - $t0)];

    // Product list pages, first and deep (keyset cursor).
    $ms = [];
    $cursor = null;
    $pages = 0;
    for ($i = 0; $i < 40; $i++) {
        $r = http('GET', $base . '/obx/v1/products?limit=100' . ($cursor !== null ? '&cursor=' . rawurlencode($cursor) : ''), $auth);
        $page = $check($r, 'products');
        $ms[] = $r['ms'];
        $pages++;
        $cursor = $page['next_cursor'] ?? null;
        if ($cursor === null) {
            break;
        }
    }
    $rows['GET /obx/v1/products?limit=100 (40 pages)'] = stats($ms);

    // EAN lookups: single GET and POST with 100 codes.
    $ms = [];
    for ($i = 1; $i <= 50; $i++) {
        $r = http('GET', $base . '/obx/v1/products:by-gtin?gtin=' . ean($i * 1000), $auth);
        $check($r, 'by-gtin');
        $ms[] = $r['ms'];
    }
    $rows['GET /obx/v1/products:by-gtin (1 EAN)'] = stats($ms);
    $ms = [];
    for ($i = 0; $i < 20; $i++) {
        $codes = [];
        for ($j = 0; $j < 100; $j++) {
            $codes[] = ean(1 + (($i * 100 + $j) * 577) % 59999);
        }
        $r = http('POST', $base . '/obx/v1/products:by-gtin', $auth + ['Content-Type' => 'application/json'], json_encode(['gtins' => $codes]));
        $res = $check($r, 'by-gtin batch');
        $ms[] = $r['ms'];
    }
    $rows['POST /obx/v1/products:by-gtin (100 EANs)'] = stats($ms);
    $ms = [];
    for ($i = 1; $i <= 30; $i++) {
        $r = http('GET', $base . '/obx/v1/products?limit=25', $auth);
        $list = $check($r, 'products');
        $id = $list['items'][$i % count($list['items'])]['id'];
        $r = http('GET', $base . '/obx/v1/products/' . $id, $auth);
        $check($r, 'product');
        $ms[] = $r['ms'];
    }
    $rows['GET /obx/v1/products/{id}'] = stats($ms);

    // The retailer portal as the admin sees it (same queries as a retailer).
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    http('GET', $base . '/admin/api/session', [], null, $jar);
    $check(http('POST', $base . '/admin/api/session/login', ['Content-Type' => 'application/json', 'X-CSRF-Token' => csrfFrom($jar)], json_encode($in['admin']), $jar), 'login');
    $portal = [
        'GET /admin/api/portal/products (first page)' => '/admin/api/portal/products?limit=48',
        'GET /admin/api/portal/products (page 100)' => 'PAGE100',
        'GET /admin/api/portal/products?q=sweater' => '/admin/api/portal/products?limit=48&q=sweater',
        'GET /admin/api/portal/products (type + season filter)' => '/admin/api/portal/products?limit=48&item_type=sweater&season=AW27',
        'GET /admin/api/portal/facets' => '/admin/api/portal/facets',
        'GET /admin/api/catalog/products (admin list)' => '/admin/api/catalog/products?limit=50',
    ];
    foreach ($portal as $label => $path) {
        $ms = [];
        for ($i = 0; $i < 15; $i++) {
            if ($path === 'PAGE100') {
                static $deep = null;
                if ($deep === null) {
                    $body = $check(http('GET', $base . '/admin/api/portal/products?limit=48', [], null, $jar), $label);
                    for ($k = 1; $k < 99; $k++) {
                        $body = $check(http('GET', $base . '/admin/api/portal/products?limit=48&cursor=' . rawurlencode((string) $body['next_cursor']), [], null, $jar), $label);
                    }
                    $deep = '/admin/api/portal/products?limit=48&cursor=' . rawurlencode((string) $body['next_cursor']);
                }
                $r = http('GET', $base . $deep, [], null, $jar);
                $check($r, $label);
                $ms[] = $r['ms'];
                continue;
            }
            $r = http('GET', $base . $path, [], null, $jar);
            $check($r, $label);
            $ms[] = $r['ms'];
        }
        $rows[$label] = stats($ms);
    }
    $ms = [];
    for ($i = 0; $i < 10; $i++) {
        $text = '';
        for ($j = 0; $j < 50; $j++) {
            $text .= ean(1 + ($i * 50 + $j) * 97) . "\n";
        }
        $r = http('POST', $base . '/admin/api/portal/gtin-lookup', ['Content-Type' => 'application/json', 'X-CSRF-Token' => csrfFrom($jar)], json_encode(['text' => $text]), $jar);
        $check($r, 'portal gtin lookup');
        $ms[] = $r['ms'];
    }
    $rows['POST /admin/api/portal/gtin-lookup (50 EANs)'] = stats($ms);

    echo "| Request | n | p50 ms | p95 ms | max ms | note |\n|---|---:|---:|---:|---:|---|\n";
    foreach ($rows as $label => $s) {
        printf("| %s | %d | %s | %s | %s | %s |\n", $label, $s['n'], $s['p50'], $s['p95'], $s['max'], $s['note'] ?? '');
    }
}

function csrfFrom(string $jar): string
{
    foreach (file($jar) ?: [] as $line) {
        $f = explode("\t", trim($line));
        if (count($f) >= 7 && $f[5] === 'obx_csrf') {
            return $f[6];
        }
    }
    return '';
}
