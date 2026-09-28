<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Http;

use Obx\Node\Services\Webhooks;
use Obx\Node\Support\Json;
use Obx\Node\Tests\Support\NodeTestCase;

/**
 * Tests over real sockets: webhook deliveries to a receiver process, and the node itself served
 * by PHP's web server (front controller, installer, security headers).
 */
final class RealServerTest extends NodeTestCase
{
    /** @var list<resource> */
    private array $procs = [];

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) {
            proc_terminate($p);
            proc_close($p);
        }
        parent::tearDown();
    }

    /** Starts `php -S` and waits until it answers; returns the base URL. @param array<string, string> $env */
    private function serve(array $args, array $env = []): string
    {
        $port = random_int(20000, 40000);
        $cmd = array_merge([PHP_BINARY, '-S', '127.0.0.1:' . $port], $args);
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $this->dir . '/server-' . $port . '.log', 'w']], $pipes, dirname(__DIR__, 2), array_merge(getenv(), $env));
        $this->assertIsResource($p);
        $this->procs[] = $p;
        for ($i = 0; $i < 100; $i++) {
            $s = @fsockopen('127.0.0.1', $port, $e, $es, 0.2);
            if ($s !== false) {
                fclose($s);
                return 'http://127.0.0.1:' . $port;
            }
            usleep(50000);
        }
        $this->fail('php -S did not start');
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function curl(string $method, string $url, ?string $body = null, array $headers = [], ?string $cookieJar = null): array
    {
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $resHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$resHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $resHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($cookieJar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        }
        $out = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $resHeaders, 'body' => $out];
    }

    /** @return list<array<string, mixed>> */
    private function received(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        return array_map([Json::class, 'decode'], array_values(array_filter(explode("\n", (string) file_get_contents($file)))));
    }

    public function testWebhooksAreSignedAndDelivered(): void
    {
        $this->kestrelNode();
        $captured = $this->dir . '/received.ndjson';
        $receiver = $this->serve([dirname(__DIR__) . '/fixtures/receiver.php'], ['OBX_RECEIVER_FILE' => $captured]);
        [, $id, $secret] = $this->retailerClient(['name' => 'Hook Shop']);
        $token = $this->accessToken($id, $secret);
        $hook = $this->json($this->http('POST', '/obx/v1/webhooks', ['bearer' => $token, 'json' => ['url' => $receiver . '/hook', 'events' => ['changes.available', 'grant.updated']]]), 201);
        $this->assertStringStartsWith('whsec_', $hook['secret']);
        $test = $this->json($this->http('POST', '/obx/v1/webhooks/' . $hook['id'] . '/test', ['bearer' => $token]));
        $this->assertSame(['delivered' => true, 'status' => 200], $test);
        $got = $this->received($captured);
        $this->assertCount(1, $got);
        $h = $got[0]['headers'];
        $this->assertSame(Webhooks::signature($hook['secret'], $h['obx-webhook-timestamp'], $got[0]['body']), $h['obx-webhook-signature']);
        $this->assertLessThan(300, abs(time() - (int) $h['obx-webhook-timestamp']));
        $event = Json::decode($got[0]['body']);
        $this->assertSame('webhook.test', $event['type']);
        $this->assertSame($event['id'], $h['obx-webhook-id']);
        $list = $this->json($this->http('GET', '/obx/v1/webhooks', ['bearer' => $token]));
        $this->assertNull($list['items'][0]['secret']);

        // A catalog change wakes the subscriber.
        $this->app->writer->setStatus('product', 'KO-1006', false);
        $this->app->worker()->tick(10);
        $events = array_map(static fn ($r) => Json::decode($r['body'])['type'], $this->received($captured));
        $this->assertContains('changes.available', $events);

        // https only (without the development switch) and no private addresses.
        $strict = $this->makeApp(['allow_private_urls' => false]);
        $this->app = $strict;
        $this->assertProblem($this->http('POST', '/obx/v1/webhooks', ['bearer' => $token, 'json' => ['url' => 'http://example.com/hook', 'events' => ['changes.available']]]), 422, 'unsafe_url');
        $this->assertProblem($this->http('POST', '/obx/v1/webhooks', ['bearer' => $token, 'json' => ['url' => 'https://10.0.0.8/hook', 'events' => ['changes.available']]]), 422, 'unsafe_url');
    }

    public function testBrandWebhookReceivesImportCompleted(): void
    {
        $this->freshNode();
        $captured = $this->dir . '/admin-hooks.ndjson';
        $receiver = $this->serve([dirname(__DIR__) . '/fixtures/receiver.php'], ['OBX_RECEIVER_FILE' => $captured]);
        $hook = $this->json($this->http('POST', '/admin/api/webhooks', ['browser' => 'admin', 'json' => ['url' => $receiver . '/brand', 'events' => ['import_run.completed', 'access_request.created']]]), 201);
        $token = $this->ingestToken();
        $csv = $this->dir . '/p.csv';
        file_put_contents($csv, "style_number,name\nWH-1,Webhook tee\n");
        $this->json($this->http('POST', '/obx/v1/ingest/imports', ['bearer' => $token, 'files' => ['csv' => [$this->upload($csv, 'products.csv')]]]), 202);
        $this->app->worker()->tick(10);
        $types = array_map(static fn ($r) => Json::decode($r['body'])['type'], $this->received($captured));
        $this->assertContains('import_run.completed', $types);
        $deliveries = $this->json($this->http('GET', '/admin/api/webhooks/' . $hook['id'] . '/deliveries', ['browser' => 'admin']));
        $this->assertTrue($deliveries['items'][0]['success']);
    }

    public function testShippedFrontControllerInstallerAndSetup(): void
    {
        $env = [
            'OBX_CONFIG_FILE' => $this->dir . '/cfg/config.php',
            'OBX_DATA_DIR' => $this->dir . '/site-data',
            'OBX_UI_DIR' => $this->dir . '/no-ui',
        ];
        mkdir($this->dir . '/cfg');
        $root = dirname(__DIR__, 2);
        $base = $this->serve(['-d', 'enable_post_data_reading=0', '-t', $root . '/public', $root . '/public/index.php'], $env);

        // Not installed: every page shows the installer.
        $page = $this->curl('GET', $base . '/');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Install your brand portal', $page['body']);
        $this->assertStringContainsString("frame-ancestors 'none'", $page['headers']['content-security-policy']);
        $codeFile = (string) file_get_contents($this->dir . '/site-data/SETUP-CODE.txt');
        $this->assertSame(1, preg_match('/([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4})/', $codeFile, $m));
        $code = $m[1];
        $wrong = $this->curl('POST', $base . '/install', http_build_query(['setup_code' => 'AAAA-BBBB-CCCC', 'public_url' => $base, 'db' => 'sqlite']));
        $this->assertStringContainsString('setup code is not correct', $wrong['body']);
        $ok = $this->curl('POST', $base . '/install', http_build_query(['setup_code' => $code, 'public_url' => $base, 'db' => 'sqlite', 'mail_from' => '']));
        $this->assertStringContainsString('The portal is installed', $ok['body'], $ok['body']);
        $this->assertFileExists($this->dir . '/cfg/config.php');
        $this->assertStringContainsString($code, $ok['body']);

        // Installed: the API answers; setup is still required.
        $disc = $this->curl('GET', $base . '/.well-known/obx');
        $this->assertSame(503, $disc['status'], $disc['body']);
        $this->assertSame('setup_required', Json::decode($disc['body'])['obx_error']);
        $again = $this->curl('GET', $base . '/install');
        $this->assertStringContainsString('Already installed', $again['body']);

        $jar = $this->dir . '/cookies.txt';
        $session = Json::decode($this->curl('GET', $base . '/admin/api/session', null, [], $jar)['body']);
        $setup = $this->curl('POST', $base . '/admin/api/setup', Json::encode([
            'setup_code' => $code,
            'company' => ['name' => 'Kestrel Outfitters', 'default_language' => 'en'],
            'admin' => ['name' => 'Ada', 'email' => 'ada@kestrel.example', 'password' => 'correct horse battery'],
        ]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $session['csrf_token']], $jar);
        $this->assertSame(201, $setup['status'], $setup['body']);
        $disc = $this->curl('GET', $base . '/.well-known/obx');
        $this->assertSame(200, $disc['status']);
        $this->assertSame('*', $disc['headers']['access-control-allow-origin']);
        $this->assertSame($base . '/obx/v1', Json::decode($disc['body'])['api_base']);
        $this->assertFileDoesNotExist($this->dir . '/site-data/SETUP-CODE.txt');

        // SPA fallback with security headers (placeholder page when the UI is not vendored).
        $spa = $this->curl('GET', $base . '/login', null, ['Accept' => 'text/html']);
        $this->assertSame(200, $spa['status']);
        $this->assertSame('DENY', $spa['headers']['x-frame-options']);
        $this->assertStringContainsString("frame-ancestors 'none'", $spa['headers']['content-security-policy']);
        $this->assertStringContainsString('<base href="/" />', $spa['body']);
        $api404 = $this->curl('GET', $base . '/admin/api/nope');
        $this->assertSame(404, $api404['status']);
        $this->assertStringContainsString('problem+json', $api404['headers']['content-type']);
        // Repeated "images" fields in one multipart request all arrive (own multipart parser).
        $csrf = Json::decode($this->curl('GET', $base . '/admin/api/session', null, [], $jar)['body'])['csrf_token'];
        $tok = Json::decode($this->curl('POST', $base . '/admin/api/ingest-tokens', Json::encode(['name' => 'CLI', 'scopes' => ['ingest:read', 'ingest:write']]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf], $jar)['body'])['secret'];
        $fields = ['csv' => new \CURLFile(self::fixtures('kestrel/products.csv'), 'text/csv', 'products.csv')];
        $ch = curl_init($base . '/obx/v1/ingest/imports');
        $boundary = 'obxtest' . bin2hex(random_bytes(4));
        $body = '';
        foreach (['KO-1001_NVY_1_front.png', 'KO-1001_NVY_2_back.png', 'KO-1002_BLK_1_front.png'] as $img) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"images\"; filename=\"$img\"\r\nContent-Type: image/png\r\n\r\n" . file_get_contents(self::fixtures('kestrel/images/' . $img)) . "\r\n";
        }
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"csv\"; filename=\"products.csv\"\r\nContent-Type: text/csv\r\n\r\n" . file_get_contents(self::fixtures('kestrel/products.csv')) . "\r\n";
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"options\"\r\n\r\n{\"mode\":\"upsert\"}\r\n--$boundary--\r\n";
        unset($fields, $ch);
        $imp = $this->curl('POST', $base . '/obx/v1/ingest/imports', $body, ['Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'multipart/form-data; boundary=' . $boundary]);
        $this->assertSame(202, $imp['status'], $imp['body']);
        $files = array_column(Json::decode($imp['body'])['import_run']['files'], 'name');
        $this->assertSame(['products.csv', 'KO-1001_NVY_1_front.png', 'KO-1001_NVY_2_back.png', 'KO-1002_BLK_1_front.png'], $files);

        // Bearer tokens reach PHP.
        $unauth = $this->curl('GET', $base . '/obx/v1/changes', null, ['Authorization' => 'Bearer nope']);
        $this->assertSame('invalid_token', Json::decode($unauth['body'])['obx_error']);
    }
}
