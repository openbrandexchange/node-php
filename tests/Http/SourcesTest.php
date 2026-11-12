<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Http;

use Obx\Node\Storage\S3Client;
use Obx\Node\Support\Json;
use Obx\Node\Tests\Support\NodeTestCase;

/**
 * Scheduled pull imports ("automatic upload") from a web address, an S3 bucket and an SFTP
 * folder. The web address test always runs (a local server); S3 and SFTP run when
 * scripts/test-sources.sh provides the servers (OBX_TEST_S3_ENDPOINT, OBX_TEST_SFTP_HOST).
 */
final class SourcesTest extends NodeTestCase
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

    private function serveDir(string $dir): string
    {
        $port = random_int(40001, 50000);
        $p = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $dir], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->procs[] = $p;
        for ($i = 0; $i < 100; $i++) {
            $s = @fsockopen('127.0.0.1', $port, $e, $es, 0.2);
            if ($s !== false) {
                fclose($s);
                return 'http://127.0.0.1:' . $port;
            }
            usleep(50000);
        }
        $this->fail('server did not start');
    }

    /** @return array<string, mixed> the finished run */
    private function runSource(string $sourceId, array $body = []): array
    {
        $run = $this->json($this->http('POST', '/admin/api/import-sources/' . $sourceId . '/run', ['browser' => 'admin', 'json' => $body]), 202);
        return $this->json($this->http('GET', '/admin/api/imports/' . $run['id'], ['browser' => 'admin']));
    }

    private function expectKestrel(array $run): void
    {
        $this->assertSame('succeeded', $run['status'], Json::encode($run));
        $this->assertSame(0, $run['counts']['errors'], Json::encode($run['errors_preview']));
        $this->assertSame(35, (int) $this->app->db->value('SELECT COUNT(*) FROM obx_assets WHERE visible = 1'));
    }

    public function testWebAddressImportRunsAndSkipsUnchangedFiles(): void
    {
        $this->freshNode();
        $site = $this->dir . '/site';
        mkdir($site);
        copy($this->kestrelZip(), $site . '/export.zip');
        $base = $this->serveDir($site);
        $src = $this->json($this->http('POST', '/admin/api/import-sources', ['browser' => 'admin', 'json' => [
            'name' => 'Nightly export', 'kind' => 'https_url', 'config' => ['url' => $base . '/export.zip'],
            'schedule' => ['frequency' => 'hourly'], 'credentials' => ['headers' => ['Authorization' => 'Bearer abc']],
        ]]), 201);
        $this->assertSame(['Authorization'], $src['credentials_set']['headers']);
        $test = $this->json($this->http('POST', '/admin/api/import-sources/' . $src['id'] . '/test', ['browser' => 'admin']));
        $this->assertTrue($test['ok'], Json::encode($test));
        $this->assertNull($test['message'], 'no English-only text next to the UI\'s own "Connected" message');
        $this->assertSame('export.zip', $test['files'][0]['name']);
        $this->expectKestrel($this->runSource($src['id']));
        $again = $this->runSource($src['id']);
        $this->assertSame('succeeded', $again['status']);
        $this->assertSame(0, $again['counts']['created'] + $again['counts']['updated'] + $again['counts']['unchanged'], 'unchanged file is skipped');
        $forced = $this->runSource($src['id'], ['force' => true]);
        $this->assertSame(11 + 46 + 35, $forced['counts']['unchanged']);
        $list = $this->json($this->http('GET', '/admin/api/import-sources', ['browser' => 'admin']));
        $this->assertSame($forced['id'], $list['items'][0]['last_run']['id']);
        $this->assertSame('manual_run', $list['items'][0]['last_run']['trigger']);
        // Schedules: due sources run from the worker.
        \Obx\Node\Support\Clock::$offset = 3700;
        file_put_contents($site . '/export.zip', (string) file_get_contents($this->kestrelZip(str_replace('Merino Crew Sweater', 'Merino Crew Jumper', (string) file_get_contents(self::fixtures('kestrel/products.csv'))))));
        $this->app->worker()->tick(30);
        $runs = $this->json($this->http('GET', '/admin/api/imports', ['browser' => 'admin', 'query' => ['source_id' => $src['id']]]));
        $this->assertSame('schedule', $runs['items'][0]['trigger']);
        $this->assertSame(1, $runs['items'][0]['counts']['updated'], Json::encode($runs['items'][0]));
    }

    public function testS3BucketImport(): void
    {
        $endpoint = getenv('OBX_TEST_S3_ENDPOINT');
        if ($endpoint === false || $endpoint === '') {
            $this->markTestSkipped('Run scripts/test-sources.sh for the S3 test.');
        }
        $this->freshNode();
        $bucket = 'obx' . bin2hex(random_bytes(4));
        $s3 = new S3Client($bucket, 'us-east-1', 'obxtest', 'obxtestsecret', $endpoint, true);
        $this->assertSame(200, $s3->request('PUT', '')['status']);
        $s3->put('export/products.csv', self::fixtures('kestrel/products.csv'), 'text/csv');
        $s3->put('export/assets.csv', self::fixtures('kestrel/assets.csv'), 'text/csv');
        foreach (glob(self::fixtures('kestrel/images/*.png')) as $img) {
            $s3->put('export/images/' . basename($img), $img, 'image/png');
        }
        $src = $this->json($this->http('POST', '/admin/api/import-sources', ['browser' => 'admin', 'json' => [
            'name' => 'Bucket', 'kind' => 's3', 'schedule' => ['frequency' => 'manual'],
            'config' => ['endpoint' => $endpoint, 'region' => 'us-east-1', 'bucket' => $bucket, 'prefix' => 'export/', 'path_style' => true],
            'credentials' => ['access_key_id' => 'obxtest', 'secret_access_key' => 'obxtestsecret'],
        ]]), 201);
        $test = $this->json($this->http('POST', '/admin/api/import-sources/' . $src['id'] . '/test', ['browser' => 'admin']));
        $this->assertTrue($test['ok'], Json::encode($test));
        $this->assertCount(29, $test['files']);
        $this->expectKestrel($this->runSource($src['id']));
        $this->http('PATCH', '/admin/api/import-sources/' . $src['id'], ['browser' => 'admin', 'json' => ['credentials' => ['secret_access_key' => 'wrong']]]);
        $bad = $this->json($this->http('POST', '/admin/api/import-sources/' . $src['id'] . '/test', ['browser' => 'admin']));
        $this->assertFalse($bad['ok']);
    }

    public function testSftpFolderImportPinsTheHostKey(): void
    {
        $host = getenv('OBX_TEST_SFTP_HOST');
        if ($host === false || $host === '') {
            $this->markTestSkipped('Run scripts/test-sources.sh for the SFTP test.');
        }
        $this->freshNode();
        $src = $this->json($this->http('POST', '/admin/api/import-sources', ['browser' => 'admin', 'json' => [
            'name' => 'SFTP', 'kind' => 'sftp', 'schedule' => ['frequency' => 'daily', 'time' => '02:30'],
            'config' => ['host' => $host, 'port' => 22, 'username' => 'brand', 'path' => '/upload/export'],
            'credentials' => ['password' => 'brandpass'],
        ]]), 201);
        $test = $this->json($this->http('POST', '/admin/api/import-sources/' . $src['id'] . '/test', ['browser' => 'admin']));
        $this->assertTrue($test['ok'], Json::encode($test));
        $this->assertStringStartsWith('SHA256:', (string) $test['host_key_fingerprint']);
        $this->expectKestrel($this->runSource($src['id']));
        $pinned = $this->json($this->http('GET', '/admin/api/import-sources/' . $src['id'], ['browser' => 'admin']));
        $this->assertSame($test['host_key_fingerprint'], $pinned['config']['host_key_fingerprint']);
        $this->http('PATCH', '/admin/api/import-sources/' . $src['id'], ['browser' => 'admin', 'json' => ['config' => array_replace($pinned['config'], ['host_key_fingerprint' => 'SHA256:somethingelse'])]]);
        $bad = $this->json($this->http('POST', '/admin/api/import-sources/' . $src['id'] . '/test', ['browser' => 'admin']));
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('key changed', (string) $bad['message']);
    }

    public function testImagesCanLiveInAnS3Bucket(): void
    {
        $endpoint = getenv('OBX_TEST_S3_ENDPOINT');
        if ($endpoint === false || $endpoint === '') {
            $this->markTestSkipped('Run scripts/test-sources.sh for the S3 test.');
        }
        $bucket = 'blobs' . bin2hex(random_bytes(4));
        $s3 = new S3Client($bucket, 'us-east-1', 'obxtest', 'obxtestsecret', $endpoint, true);
        $this->assertSame(200, $s3->request('PUT', '')['status']);
        $this->app = $this->makeApp([
            'blob_store' => 's3', 's3_endpoint' => $endpoint, 's3_bucket' => $bucket, 's3_region' => 'us-east-1',
            's3_access_key_id' => 'obxtest', 's3_secret_access_key' => 'obxtestsecret', 's3_path_style' => true, 's3_prefix' => 'b/',
        ]);
        $this->setupNode();
        $token = $this->ingestToken();
        $job = $this->importKestrel($token);
        $this->assertSame('succeeded', $job['status']);
        $keys = array_column($s3->list('b/'), 'key');
        $this->assertCount(35 * 4, $keys, 'originals and renditions are in the bucket');
        // Serving works from a cold local cache.
        $this->rmrf($this->dir . '/data/cache');
        [, $id, $secret] = $this->retailerClient();
        $client = $this->accessToken($id, $secret);
        $asset = (string) $this->app->db->value("SELECT id FROM obx_assets WHERE external_id = 'KO-1001_NVY_1'");
        $res = $this->http('GET', '/obx/v1/assets/' . $asset . '/renditions/original', ['bearer' => $client]);
        $this->assertSame(file_get_contents(self::fixtures('kestrel/images/KO-1001_NVY_1_front.png')), $res->content());
    }
}
