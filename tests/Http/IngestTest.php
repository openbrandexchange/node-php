<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Http;

use Obx\Node\Support\Json;
use Obx\Node\Tests\Support\NodeTestCase;

/**
 * The write side (conformance profile "ingest"): replay of the spec's ingest script, batches,
 * files, validation and scopes.
 */
final class IngestTest extends NodeTestCase
{
    private string $ingest;
    private string $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshNode();
        $this->ingest = $this->ingestToken();
        [, $id, $secret] = $this->retailerClient();
        $this->client = $this->accessToken($id, $secret);
    }

    /** @return list<array<string, mixed>> */
    private function script(string $suffix = ''): array
    {
        $ops = [];
        foreach (file(self::fixtures('ingest/script.ndjson'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $op = Json::decode($line);
            if ($suffix !== '') {
                $op['external_id'] .= $suffix;
                foreach (['brand_external_id', 'product_external_id'] as $k) {
                    if (isset($op['data'][$k])) {
                        $op['data'][$k] .= $suffix;
                    }
                }
            }
            $ops[] = $op;
        }
        return $ops;
    }

    private function uploadFixtureBlobs(): void
    {
        foreach (glob(self::fixtures('ingest/files/*.png')) as $f) {
            $sha = hash_file('sha256', $f);
            $res = $this->http('PUT', '/obx/v1/ingest/blobs/' . $sha, ['bearer' => $this->ingest, 'body' => file_get_contents($f), 'headers' => ['Content-Type' => 'image/png']]);
            $this->assertContains($res->status, [200, 201], $res->content());
        }
    }

    /** @param array<string, mixed> $op */
    private function send(array $op): array
    {
        $path = '/obx/v1/ingest/' . $op['kind'] . 's/' . rawurlencode($op['external_id']);
        $res = match ($op['op']) {
            'upsert' => $this->http('PUT', $path, ['bearer' => $this->ingest, 'json' => $op['data']]),
            'patch' => $this->http('PATCH', $path, ['bearer' => $this->ingest, 'json' => $op['data'], 'headers' => ['Content-Type' => 'application/merge-patch+json']]),
            'publish' => $this->http('PATCH', $path, ['bearer' => $this->ingest, 'json' => ['status' => 'published'], 'headers' => ['Content-Type' => 'application/merge-patch+json']]),
            'unpublish' => $this->http('PATCH', $path, ['bearer' => $this->ingest, 'json' => ['status' => 'unpublished'], 'headers' => ['Content-Type' => 'application/merge-patch+json']]),
            'delete' => $this->http('DELETE', $path, ['bearer' => $this->ingest]),
            default => throw new \UnexpectedValueException('Unknown operation ' . $op['op']),
        };
        $body = Json::decode($res->content());
        if ($res->status >= 400) {
            return ['result' => 'error', 'obx_error' => $body['obx_error'] ?? null, 'status' => $res->status];
        }
        return $body;
    }

    /** @return list<array<string, mixed>> */
    private function liveEntries(string &$cursor): array
    {
        $out = [];
        do {
            $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $this->client, 'query' => ['cursor' => $cursor, 'limit' => '500']]));
            $this->assertSame('live', $page['phase']);
            $cursor = $page['next_cursor'];
            foreach ($page['changes'] as $c) {
                $out[] = $c;
            }
        } while ($page['has_more']);
        return $out;
    }

    public function testReplayProducesExpectedResultsAndChanges(): void
    {
        [, $cursor] = $this->drainSnapshot($this->client);
        $this->uploadFixtureBlobs();
        $expectedResults = Json::decode((string) file_get_contents(self::fixtures('ingest/expected-results.json')));
        $expectedChanges = Json::decode((string) file_get_contents(self::fixtures('ingest/expected-changes.json')))['changes'];
        foreach ($this->script() as $i => $op) {
            $r = $this->send($op);
            $exp = $expectedResults[$i];
            $this->assertSame($exp['result'], $r['result'], 'op ' . $i . ': ' . Json::encode($r));
            if (isset($exp['obx_error'])) {
                $this->assertSame($exp['obx_error'], $r['obx_error'], 'op ' . $i);
            }
            $got = array_map(static fn ($c) => [$c['type'], $c['op'], $c['external_id']], $this->liveEntries($cursor));
            $want = array_values(array_map(static fn ($c) => [$c['type'], $c['op'], $c['external_id']], array_filter($expectedChanges, static fn ($c) => $c['after_op'] === $i)));
            $this->assertSame($want, $got, 'changes after op ' . $i);
        }
    }

    public function testReplayAsJsonBatchAndNdjsonBatchGivesTheSameResults(): void
    {
        $this->uploadFixtureBlobs();
        $expectedResults = Json::decode((string) file_get_contents(self::fixtures('ingest/expected-results.json')));
        $expectedChanges = Json::decode((string) file_get_contents(self::fixtures('ingest/expected-changes.json')))['changes'];
        foreach (['-b' => 'json', '-c' => 'ndjson'] as $suffix => $format) {
            [, $cursor] = $this->drainSnapshot($this->client);
            $ops = $this->script($suffix);
            $body = $format === 'json' ? Json::encode($ops) : implode("\n", array_map([Json::class, 'encode'], $ops)) . "\n";
            $res = $this->json($this->http('POST', '/obx/v1/ingest/batch', ['bearer' => $this->ingest, 'body' => $body, 'headers' => ['Content-Type' => $format === 'json' ? 'application/json' : 'application/x-ndjson']]));
            foreach ($expectedResults as $i => $exp) {
                $this->assertSame($exp['result'], $res['results'][$i]['result'], $suffix . ' op ' . $i . ': ' . Json::encode($res['results'][$i]));
                $this->assertSame($i, $res['results'][$i]['index']);
                if (isset($exp['obx_error'])) {
                    $this->assertSame($exp['obx_error'], $res['results'][$i]['error']['obx_error']);
                }
            }
            $got = array_map(static fn ($c) => [$c['type'], $c['op'], $c['external_id']], $this->liveEntries($cursor));
            // Read after the whole batch: upsert entries of objects that are no longer visible are
            // omitted (docs/protocol.md §6.2), delete entries stay.
            $want = [];
            foreach ($expectedChanges as $c) {
                $ext = $c['external_id'] . $suffix;
                if ($c['op'] === 'upsert') {
                    $table = ['brand' => 'obx_brands', 'product' => 'obx_products', 'variant' => 'obx_variants', 'asset' => 'obx_assets'][$c['type']];
                    if ((int) $this->app->db->value('SELECT visible FROM ' . $table . ' WHERE external_id = ?', [$ext]) !== 1) {
                        continue;
                    }
                }
                $want[] = [$c['type'], $c['op'], $ext];
            }
            $this->assertSame($want, $got, 'changes for ' . $suffix);
        }
    }

    public function testIdempotentRepeatsAndIdempotencyKey(): void
    {
        $body = ['style_number' => 'KO-7000', 'name' => ['en' => 'Idempotent tee']];
        $this->assertSame('created', $this->json($this->http('PUT', '/obx/v1/ingest/products/KO-7000', ['bearer' => $this->ingest, 'json' => $body]), 201)['result']);
        [, $cursor] = $this->drainSnapshot($this->client);
        $again = $this->json($this->http('PUT', '/obx/v1/ingest/products/KO-7000', ['bearer' => $this->ingest, 'json' => $body]));
        $this->assertSame('unchanged', $again['result']);
        $this->assertSame([], $this->liveEntries($cursor));
        $ops = Json::encode([['op' => 'upsert', 'kind' => 'product', 'external_id' => 'KO-7001', 'data' => ['style_number' => 'KO-7001', 'name' => ['en' => 'x']]]]);
        $one = $this->http('POST', '/obx/v1/ingest/batch', ['bearer' => $this->ingest, 'body' => $ops, 'headers' => ['Content-Type' => 'application/json', 'Prefer' => 'respond-async', 'Idempotency-Key' => 'k1']]);
        $two = $this->http('POST', '/obx/v1/ingest/batch', ['bearer' => $this->ingest, 'body' => $ops, 'headers' => ['Content-Type' => 'application/json', 'Prefer' => 'respond-async', 'Idempotency-Key' => 'k1']]);
        $this->assertSame($this->json($one, 202)['id'], $this->json($two, 202)['id']);
        $job = $this->json($this->http('GET', '/obx/v1/ingest/jobs/' . $this->json($one, 202)['id'], ['bearer' => $this->ingest]));
        $this->assertSame('succeeded', $job['status']);
        $this->assertSame(1, $job['summary']['created']);
        $results = $this->json($this->http('GET', '/obx/v1/ingest/jobs/' . $job['id'] . '/results', ['bearer' => $this->ingest]));
        $this->assertSame('created', $results['items'][0]['result']);
    }

    public function testBlobsDedupeChecksumAndMissingBlob(): void
    {
        $file = self::fixtures('ingest/files/KL-9001_GRN_1.png');
        $sha = hash_file('sha256', $file);
        $this->assertSame(404, $this->http('HEAD', '/obx/v1/ingest/blobs/' . $sha, ['bearer' => $this->ingest])->status);
        $put = $this->json($this->http('PUT', '/obx/v1/ingest/blobs/' . $sha, ['bearer' => $this->ingest, 'body' => file_get_contents($file)]), 201);
        $this->assertTrue($put['created']);
        $this->assertSame(1200, $put['width']);
        $this->assertSame(200, $this->http('HEAD', '/obx/v1/ingest/blobs/' . $sha, ['bearer' => $this->ingest])->status);
        $this->assertFalse($this->json($this->http('PUT', '/obx/v1/ingest/blobs/' . $sha, ['bearer' => $this->ingest, 'body' => file_get_contents($file)]))['created']);
        $this->assertProblem($this->http('PUT', '/obx/v1/ingest/blobs/' . str_repeat('a', 64), ['bearer' => $this->ingest, 'body' => file_get_contents($file)]), 422, 'checksum_mismatch');
        $this->assertProblem($this->http('PUT', '/obx/v1/ingest/blobs/' . hash('sha256', 'hello'), ['bearer' => $this->ingest, 'body' => 'hello']), 415, 'unsupported_media_type');
        $this->http('PUT', '/obx/v1/ingest/products/P1', ['bearer' => $this->ingest, 'json' => ['style_number' => 'P1', 'name' => ['en' => 'P']]]);
        $this->assertProblem($this->http('PUT', '/obx/v1/ingest/assets/P1_X_1', ['bearer' => $this->ingest, 'json' => [
            'product_external_id' => 'P1', 'color_code' => null, 'role' => 'packshot', 'position' => 1, 'files' => ['original' => ['sha256' => str_repeat('b', 64)]],
        ]]), 422, 'blob_missing');
        // Per-file upload with a wrong declared hash.
        $this->json($this->http('PUT', '/obx/v1/ingest/assets/P1_X_1', ['bearer' => $this->ingest, 'json' => ['product_external_id' => 'P1', 'role' => 'packshot', 'position' => 1]]), 201);
        $this->assertProblem($this->http('PUT', '/obx/v1/ingest/assets/P1_X_1/files/original', ['bearer' => $this->ingest, 'body' => file_get_contents($file), 'headers' => ['Content-Type' => 'image/png', 'OBX-Content-SHA256' => str_repeat('c', 64)]]), 422, 'checksum_mismatch');
        $ok = $this->json($this->http('PUT', '/obx/v1/ingest/assets/P1_X_1/files/original', ['bearer' => $this->ingest, 'body' => file_get_contents($file), 'headers' => ['Content-Type' => 'image/png', 'OBX-Content-SHA256' => $sha]]));
        $this->assertSame('updated', $ok['result']);
        $this->assertSame('visible', $ok['visibility']);
        $record = $this->json($this->http('GET', '/obx/v1/ingest/assets/P1_X_1', ['bearer' => $this->ingest]));
        $this->assertSame([], $record['missing_files']);
        $this->assertSame($sha, $record['data']['files']['original']['sha256']);
        // data is the write model with defaults applied: effective rights and provenance.
        $this->assertSame('retailer-use', $record['data']['rights']['license']);
        $this->assertSame(['webshop', 'marketplace', 'social'], $record['data']['rights']['channels']);
        $this->assertSame(['ai_generated' => false, 'ai_edited' => false, 'source' => 'photo', 'c2pa' => 'none', 'c2pa_manifest_url' => null, 'generator' => null], $record['data']['provenance']);
        $product = $this->json($this->http('GET', '/obx/v1/ingest/products/P1', ['bearer' => $this->ingest]));
        $this->assertArrayNotHasKey('missing_files', $product);
        // Writing back what GET returned (defaults spelled out) changes nothing.
        $back = $this->json($this->http('PUT', '/obx/v1/ingest/assets/P1_X_1', ['bearer' => $this->ingest, 'json' => $record['data']]));
        $this->assertSame('unchanged', $back['result']);
        // Non-default provenance survives; its implied source is shown.
        $ai = $record['data'];
        $ai['provenance'] = ['ai_generated' => true, 'generator' => 'fictional-model 1'];
        $this->assertSame('updated', $this->json($this->http('PUT', '/obx/v1/ingest/assets/P1_X_1', ['bearer' => $this->ingest, 'json' => $ai]))['result']);
        $prov = $this->json($this->http('GET', '/obx/v1/ingest/assets/P1_X_1', ['bearer' => $this->ingest]))['data']['provenance'];
        $this->assertTrue($prov['ai_generated']);
        $this->assertSame('generated', $prov['source']);
        $this->assertSame('fictional-model 1', $prov['generator']);
    }

    public function testResumableMultipartUpload(): void
    {
        // A 12 MiB valid PNG: tiny image plus a large ancillary chunk.
        $png = (string) file_get_contents(self::fixtures('ingest/files/KL-9001_GRN_2.png'));
        $pad = str_repeat("\0", 12 * 1024 * 1024 - strlen($png) - 12);
        $chunk = pack('N', strlen($pad)) . 'zzZz' . $pad;
        $big = substr($png, 0, 33) . $chunk . pack('N', crc32('zzZz' . $pad)) . substr($png, 33);
        $big = substr($big, 0, 12 * 1024 * 1024) === $big ? $big : $big;
        $sha = hash('sha256', $big);
        $up = $this->json($this->http('POST', '/obx/v1/ingest/uploads', ['bearer' => $this->ingest, 'json' => ['sha256' => $sha, 'bytes' => strlen($big), 'content_type' => 'image/png']]), 201);
        $part = $up['part_bytes'];
        $this->assertSame((int) ceil(strlen($big) / $part), $up['parts_total']);
        $this->http('PUT', '/obx/v1/ingest/uploads/' . $up['id'] . '/parts/1', ['bearer' => $this->ingest, 'body' => substr($big, 0, $part)]);
        $this->assertProblem($this->http('POST', '/obx/v1/ingest/uploads/' . $up['id'] . '/complete', ['bearer' => $this->ingest]), 409, 'upload_incomplete');
        $status = $this->json($this->http('GET', '/obx/v1/ingest/uploads/' . $up['id'], ['bearer' => $this->ingest]));
        $this->assertSame([1], $status['received_parts']);
        for ($n = 2; $n <= $up['parts_total']; $n++) {
            $this->json($this->http('PUT', '/obx/v1/ingest/uploads/' . $up['id'] . '/parts/' . $n, ['bearer' => $this->ingest, 'body' => substr($big, ($n - 1) * $part, $part)]));
        }
        $blob = $this->json($this->http('POST', '/obx/v1/ingest/uploads/' . $up['id'] . '/complete', ['bearer' => $this->ingest]));
        $this->assertSame($sha, $blob['sha256']);
        $this->assertSame(strlen($big), $blob['bytes']);
        $this->assertSame(200, $this->http('HEAD', '/obx/v1/ingest/blobs/' . $sha, ['bearer' => $this->ingest])->status);
    }

    public function testValidationCodes(): void
    {
        $this->http('PUT', '/obx/v1/ingest/products/V1', ['bearer' => $this->ingest, 'json' => ['style_number' => 'V1', 'name' => ['en' => 'V']]]);
        $this->assertProblem($this->http('PUT', '/obx/v1/ingest/variants/x', ['bearer' => $this->ingest, 'json' => ['product_external_id' => 'V1', 'gtin' => '2000100101019', 'color' => ['code' => 'A']]]), 422, 'invalid_gtin');
        $this->json($this->http('PUT', '/obx/v1/ingest/variants/2000100101018', ['bearer' => $this->ingest, 'json' => ['product_external_id' => 'V1', 'gtin' => '2000100101018', 'color' => ['code' => 'A']]]), 201);
        $this->assertProblem($this->http('PUT', '/obx/v1/ingest/variants/other', ['bearer' => $this->ingest, 'json' => ['product_external_id' => 'V1', 'gtin' => '2000100101018', 'color' => ['code' => 'A']]]), 409, 'gtin_conflict');
        $this->assertProblem($this->http('PUT', '/obx/v1/ingest/variants/y', ['bearer' => $this->ingest, 'json' => ['product_external_id' => 'NOPE', 'color' => ['code' => 'A']]]), 422, 'unknown_parent');
        $this->json($this->http('PUT', '/obx/v1/ingest/assets/V1_A_1', ['bearer' => $this->ingest, 'json' => ['product_external_id' => 'V1', 'color_code' => 'A', 'role' => 'packshot', 'position' => 1]]), 201);
        $this->assertProblem($this->http('PUT', '/obx/v1/ingest/assets/V1_A_1b', ['bearer' => $this->ingest, 'json' => ['product_external_id' => 'V1', 'color_code' => 'A', 'role' => 'packshot', 'position' => 1]]), 409, 'position_conflict');
        $p = $this->assertProblem($this->http('PUT', '/obx/v1/ingest/products/V2', ['bearer' => $this->ingest, 'json' => ['name' => 'not localised']]), 422, 'validation_failed');
        $this->assertNotEmpty($p['errors']);
        $this->assertProblem($this->http('PUT', '/obx/v1/ingest/products/V3', ['bearer' => $this->ingest, 'json' => ['style_number' => 'V3', 'name' => ['en' => 'x'], 'brand_external_id' => 'nope']]), 422, 'unknown_parent');
        // Unknown fields are ignored with a warning.
        $r = $this->json($this->http('PUT', '/obx/v1/ingest/products/V4', ['bearer' => $this->ingest, 'json' => ['style_number' => 'V4', 'name' => ['en' => 'x'], 'colour' => 'red']]), 201);
        $this->assertNotEmpty($r['warnings']);
        // Asset reorder.
        $this->json($this->http('PUT', '/obx/v1/ingest/assets/V1_A_2', ['bearer' => $this->ingest, 'json' => ['product_external_id' => 'V1', 'color_code' => 'A', 'role' => 'packshot', 'position' => 2]]), 201);
        $order = $this->json($this->http('PUT', '/obx/v1/ingest/products/V1/asset-order', ['bearer' => $this->ingest, 'json' => ['color_code' => 'A', 'asset_external_ids' => ['V1_A_2', 'V1_A_1']]]));
        $this->assertSame(['updated', 'updated'], array_column($order, 'result'));
        $this->assertProblem($this->http('PUT', '/obx/v1/ingest/products/V1/asset-order', ['bearer' => $this->ingest, 'json' => ['color_code' => 'A', 'asset_external_ids' => ['V1_A_2']]]), 422, 'validation_failed');
        // Deleting a brand with products.
        $this->assertProblem($this->http('DELETE', '/obx/v1/ingest/brands/kestrel', ['bearer' => $this->ingest]), 409, 'conflict');
        // If-Match.
        $this->assertProblem($this->http('PUT', '/obx/v1/ingest/products/V4', ['bearer' => $this->ingest, 'json' => ['style_number' => 'V4', 'name' => ['en' => 'y']], 'headers' => ['If-Match' => '"nope"']]), 412, 'precondition_failed');
    }

    public function testScopesAndBrandRestriction(): void
    {
        $read = $this->ingestToken(['ingest:read']);
        $this->assertProblem($this->http('PUT', '/obx/v1/ingest/products/S1', ['bearer' => $read, 'json' => ['style_number' => 'S1', 'name' => ['en' => 'x']]]), 403, 'insufficient_scope');
        $info = $this->json($this->http('GET', '/obx/v1/ingest', ['bearer' => $read]));
        $this->assertGreaterThanOrEqual(26214400, $info['limits']['max_upload_bytes']);
        $this->assertGreaterThanOrEqual(1000, $info['limits']['max_batch_operations']);
        $this->assertTrue($info['renditions']['server_generated']);
        $this->json($this->http('PUT', '/obx/v1/ingest/brands/other', ['bearer' => $this->ingest, 'json' => ['name' => 'Other']]), 201);
        $restricted = $this->ingestToken(['ingest:read', 'ingest:write'], ['kestrel']);
        $this->json($this->http('PUT', '/obx/v1/ingest/products/R1', ['bearer' => $restricted, 'json' => ['brand_external_id' => 'kestrel', 'style_number' => 'R1', 'name' => ['en' => 'x']]]), 201);
        $this->assertProblem($this->http('PUT', '/obx/v1/ingest/products/R2', ['bearer' => $restricted, 'json' => ['brand_external_id' => 'other', 'style_number' => 'R2', 'name' => ['en' => 'x']]]), 403, 'forbidden');
        $this->assertProblem($this->http('DELETE', '/obx/v1/ingest/products/R1', ['bearer' => $restricted]), 403, 'insufficient_scope');
        // Revoked token.
        $tokens = $this->json($this->http('GET', '/admin/api/ingest-tokens', ['browser' => 'admin']))['items'];
        $id = null;
        foreach ($tokens as $t) {
            if ($t['brand_external_ids'] === ['kestrel']) {
                $id = $t['id'];
            }
        }
        $this->assertSame(204, $this->http('DELETE', '/admin/api/ingest-tokens/' . $id, ['browser' => 'admin'])->status);
        $this->assertProblem($this->http('GET', '/obx/v1/ingest', ['bearer' => $restricted]), 401, 'access_revoked');
    }
}
