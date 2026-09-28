<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Http;

use Obx\Node\Support\Json;
use Obx\Node\Tests\Support\NodeTestCase;

/**
 * The read side against the Kestrel fixtures (conformance profile "protocol").
 */
final class ProtocolTest extends NodeTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kestrelNode();
        [, $id, $secret] = $this->retailerClient();
        $this->token = $this->accessToken($id, $secret);
    }

    public function testDiscoveryDocument(): void
    {
        $res = $this->http('GET', '/.well-known/obx');
        $doc = $this->json($res);
        $this->assertSame('*', $res->getHeader('Access-Control-Allow-Origin'));
        $this->assertSame('http://localhost/obx/v1', $doc['api_base']);
        $this->assertSame('0.1', $doc['obx_version']);
        $this->assertSame(['nl', 'en'], $doc['languages']);
        $this->assertSame('Kestrel Outfitters', $doc['publisher']['name']);
        foreach (['changes', 'products', 'by_gtin', 'renditions', 'exports', 'webhooks', 'usage', 'ingest', 'portal'] as $cap) {
            $this->assertContains($cap, $doc['capabilities']);
        }
        $this->assertCount(2, $doc['brands']);
    }

    public function testSnapshotHasExactlyTheVisibleFixtureObjectsInTypeOrder(): void
    {
        $counts = Json::decode((string) file_get_contents(self::fixtures('expected/counts.json')));
        [$entries] = $this->drainSnapshot($this->token);
        $byType = ['brand' => 0, 'product' => 0, 'variant' => 0, 'asset' => 0];
        $order = [];
        foreach ($entries as $e) {
            $byType[$e['type']]++;
            $this->assertSame('upsert', $e['op']);
            $this->assertArrayHasKey('data', $e);
            if ($order === [] || end($order) !== $e['type']) {
                $order[] = $e['type'];
            }
        }
        $this->assertSame(['brand', 'product', 'variant', 'asset'], $order);
        $this->assertSame($counts['brands'], $byType['brand']);
        $this->assertSame($counts['products'], $byType['product']);
        $this->assertSame($counts['variants'], $byType['variant']);
        $this->assertSame($counts['assets'], $byType['asset']);
    }

    public function testPagingWithLimitOneGivesTheSameEntries(): void
    {
        [$big] = $this->drainSnapshot($this->token, 500);
        [$small] = $this->drainSnapshot($this->token, 1);
        $ids = static fn (array $l) => array_map(static fn ($e) => $e['type'] . ':' . $e['id'], $l);
        $this->assertSame($ids($big), $ids($small));
    }

    public function testGarbageCursorIsRejected(): void
    {
        $this->assertProblem($this->http('GET', '/obx/v1/changes', ['bearer' => $this->token, 'query' => ['cursor' => 'garbage']]), 400, 'cursor_invalid');
    }

    public function testProductsMatchExpectedFixtures(): void
    {
        foreach (['KO-1001', 'KO-1005'] as $style) {
            $expected = Json::decode((string) file_get_contents(self::fixtures('expected/product-' . $style . '.json')));
            $id = $this->productId($style);
            $res = $this->http('GET', '/obx/v1/products/' . $id, ['bearer' => $this->token]);
            $actual = $this->json($res);
            $this->assertMatches($expected, $actual);
            $etag = (string) $res->getHeader('ETag');
            $this->assertSame(304, $this->http('GET', '/obx/v1/products/' . $id, ['bearer' => $this->token, 'headers' => ['If-None-Match' => $etag]])->status);
        }
    }

    public function testAssetsMatchExpectedFixtures(): void
    {
        foreach (['asset-ai-generated.json', 'asset-embargoed.json', 'asset-large-original.json'] as $f) {
            $expected = Json::decode((string) file_get_contents(self::fixtures('expected/' . $f)));
            $id = $this->assetId($expected['external_id']);
            $this->assertMatches($expected, $this->json($this->http('GET', '/obx/v1/assets/' . $id, ['bearer' => $this->token])));
        }
    }

    public function testAssetWithoutOriginalScope(): void
    {
        [, $id, $secret] = $this->retailerClient(['name' => 'No originals shop', 'allow_original' => false]);
        $token = $this->accessToken($id, $secret);
        $expected = Json::decode((string) file_get_contents(self::fixtures('expected/asset-without-original-scope.json')));
        $assetId = $this->assetId($expected['external_id']);
        $this->assertMatches($expected, $this->json($this->http('GET', '/obx/v1/assets/' . $assetId, ['bearer' => $token])));
        $this->assertProblem($this->http('GET', '/obx/v1/assets/' . $assetId . '/renditions/original', ['bearer' => $token]), 403, 'original_not_allowed');
    }

    public function testGtinLookupMatchesFixture(): void
    {
        $request = Json::decode((string) file_get_contents(self::fixtures('expected/gtin-lookup.request.json')));
        $expected = Json::decode((string) file_get_contents(self::fixtures('expected/gtin-lookup.json')));
        $actual = $this->json($this->http('POST', '/obx/v1/products:by-gtin', ['bearer' => $this->token, 'json' => $request]));
        $products = [];
        foreach (['KO-1001', 'KT-2001'] as $style) {
            $products[$style] = $this->json($this->http('GET', '/obx/v1/products/' . $this->productId($style), ['bearer' => $this->token]));
        }
        $this->assertMatches($expected, $actual, '$', $products);
        $this->assertProblem($this->http('POST', '/obx/v1/products:by-gtin', ['bearer' => $this->token, 'json' => ['gtins' => array_fill(0, 2001, '2000100101025')]]), 400, 'limit_exceeded');
        $get = $this->json($this->http('GET', '/obx/v1/products:by-gtin', ['bearer' => $this->token, 'query' => ['gtin' => '2000100101025,12345']]));
        $this->assertSame(['matched', 'invalid'], array_column($get['results'], 'status'));
    }

    public function testRenditionsServeExactBytesRangesAndSignedUrls(): void
    {
        $images = Json::decode((string) file_get_contents(self::fixtures('kestrel/images.json')));
        $assetId = $this->assetId('KO-1002_OLV_1');
        $asset = $this->json($this->http('GET', '/obx/v1/assets/' . $assetId, ['bearer' => $this->token]));
        $orig = $this->http('GET', '/obx/v1/assets/' . $assetId . '/renditions/original', ['bearer' => $this->token]);
        $this->assertSame(200, $orig->status);
        $this->assertSame(file_get_contents(self::fixtures('kestrel/images/KO-1002_OLV_1_front.png')), $orig->content());
        $this->assertSame('"' . $images['KO-1002_OLV_1_front.png']['sha256'] . '"', $orig->getHeader('ETag'));
        $this->assertSame('bytes', $orig->getHeader('Accept-Ranges'));
        foreach (['web' => 2000, 'square' => 2000, 'thumb' => 600] as $name => $max) {
            $res = $this->http('GET', '/obx/v1/assets/' . $assetId . '/renditions/' . $name, ['bearer' => $this->token]);
            $bytes = $res->content();
            $this->assertSame($asset['renditions'][$name]['sha256'], hash('sha256', $bytes));
            $this->assertSame($asset['renditions'][$name]['sha256'], $res->getHeader('OBX-Content-SHA256'));
            $info = getimagesizefromstring($bytes);
            $this->assertSame('image/jpeg', $info['mime']);
            $this->assertLessThanOrEqual($max, max($info[0], $info[1]));
            if ($name === 'square') {
                $this->assertSame($info[0], $info[1]);
            }
            $this->assertStringContainsString('_' . $name . '.jpg', (string) $res->getHeader('Content-Disposition'));
        }
        $range = $this->http('GET', '/obx/v1/assets/' . $assetId . '/renditions/web', ['bearer' => $this->token, 'headers' => ['Range' => 'bytes=0-9']]);
        $this->assertSame(206, $range->status);
        $this->assertSame(10, strlen($range->content()));
        $etag = (string) $range->getHeader('ETag');
        $this->assertSame(304, $this->http('GET', '/obx/v1/assets/' . $assetId . '/renditions/web', ['bearer' => $this->token, 'headers' => ['If-None-Match' => $etag]])->status);
        // Signed URL works without Authorization.
        $url = (string) $asset['renditions']['thumb']['url'];
        $signed = $this->http('GET', substr($url, strlen('http://localhost')));
        $this->assertSame(200, $signed->status);
        $this->assertSame($asset['renditions']['thumb']['sha256'], hash('sha256', $signed->content()));
        $tampered = $this->http('GET', str_replace('/thumb?', '/original?', substr($url, strlen('http://localhost'))));
        $this->assertProblem($tampered, 403, 'forbidden');
    }

    public function testErrorsAreProblemJson(): void
    {
        $this->assertProblem($this->http('GET', '/obx/v1/changes'), 401, 'unauthorized');
        $this->assertProblem($this->http('GET', '/obx/v1/changes', ['bearer' => 'nope']), 401, 'invalid_token');
        $this->assertProblem($this->http('GET', '/obx/v1/products/prd_doesnotexist', ['bearer' => $this->token]), 404, 'not_found');
    }

    public function testTokenEndpointErrorsUseOauthJson(): void
    {
        [, $id] = $this->retailerClient(['name' => 'Shop Two']);
        $res = $this->http('POST', '/obx/v1/oauth/token', ['basic' => [$id, 'wrong'], 'post' => ['grant_type' => 'client_credentials']]);
        $this->assertSame(401, $res->status);
        $this->assertSame('invalid_client', $this->json($res, 401)['error']);
        [, $id2, $secret2] = $this->retailerClient(['name' => 'Shop Three', 'allow_original' => false]);
        $res = $this->http('POST', '/obx/v1/oauth/token', ['basic' => [$id2, $secret2], 'post' => ['grant_type' => 'client_credentials', 'scope' => 'catalog:read media:original']]);
        $this->assertSame('invalid_scope', $this->json($res, 400)['error']);
    }

    public function testProductsListKeysetPaginationAndIdsFilter(): void
    {
        $seen = [];
        $cursor = null;
        do {
            $page = $this->json($this->http('GET', '/obx/v1/products', ['bearer' => $this->token, 'query' => array_filter(['limit' => '3', 'cursor' => $cursor])]));
            foreach ($page['items'] as $p) {
                $seen[] = $p['id'];
            }
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        $this->assertCount(8, $seen);
        $this->assertSame($seen, array_values(array_unique($seen)));
        $some = $this->json($this->http('GET', '/obx/v1/products', ['bearer' => $this->token, 'query' => ['ids' => $seen[0] . ',prd_unknown']]));
        $this->assertCount(1, $some['items']);
    }

    private function productId(string $style): string
    {
        return (string) $this->app->db->value('SELECT id FROM obx_products WHERE external_id = ?', [$style]);
    }

    private function assetId(string $ext): string
    {
        return (string) $this->app->db->value('SELECT id FROM obx_assets WHERE external_id = ?', [$ext]);
    }
}
