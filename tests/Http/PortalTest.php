<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Http;

use Obx\Node\Support\Json;
use Obx\Node\Tests\Support\NodeTestCase;

/**
 * The retailer portal (conformance profile "portal"): browse, filter, EAN paste, selection,
 * downloads, CSV, background zips, isolation between retailers and statistics.
 */
final class PortalTest extends NodeTestCase
{
    private string $kestrelId;
    private string $ingest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingest = (string) $this->kestrelNode()["ingest"];
        $this->kestrelId = (string) $this->app->db->value("SELECT id FROM obx_brands WHERE external_id = 'kestrel'");
    }

    /** Invites and logs in a retailer user in browser $browser. */
    private function retailerUser(string $browser, array $retailer): string
    {
        $r = $this->json($this->http('POST', '/admin/api/retailers', ['browser' => 'admin', 'json' => $retailer]), 201);
        $inv = $this->json($this->http('POST', '/admin/api/users/invites', ['browser' => 'admin', 'json' => ['email' => $browser . '@shop.example', 'role' => 'retailer', 'retailer_id' => $r['id']]]), 201);
        $this->http('GET', '/admin/api/session', ['browser' => $browser]);
        $t = substr($inv['invite_url'], strrpos($inv['invite_url'], '/') + 1);
        $this->json($this->http('POST', '/admin/api/invites/' . $t . '/accept', ['browser' => $browser, 'json' => ['password' => 'a very good passphrase']]));
        return (string) $r['id'];
    }

    private function zipEntries(string $bytes): array
    {
        $f = $this->dir . '/z-' . bin2hex(random_bytes(3)) . '.zip';
        file_put_contents($f, $bytes);
        $z = new \ZipArchive();
        $this->assertTrue($z->open($f) === true, 'not a zip');
        $names = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $names[] = $z->getNameIndex($i);
        }
        $z->close();
        return $names;
    }

    public function testBrowseFilterDetailAndFacets(): void
    {
        $this->retailerUser('r1', ['name' => 'Shop One']);
        $viewer = $this->json($this->http('GET', '/admin/api/portal/viewer', ['browser' => 'r1']));
        $this->assertFalse($viewer['preview']);
        $this->assertTrue($viewer['permissions']['original_download']);
        $this->assertSame('Kestrel Outfitters', $viewer['branding']['name']);
        $list = $this->json($this->http('GET', '/admin/api/portal/products', ['browser' => 'r1']));
        $this->assertSame(8, $list['total']);
        $first = $list['items'][0];
        $this->assertSame('KO-1001', $first['style_number']);
        $this->assertSame(['NVY', 'OAT'], array_column($first['colors'], 'code'));
        $this->assertSame(4, $first['image_count']);
        $this->assertNotNull($first['thumb_url']);
        $thumb = $this->http('GET', substr($first['thumb_url'], strlen('http://localhost')));
        $this->assertSame(200, $thumb->status);
        $filtered = $this->json($this->http('GET', '/admin/api/portal/products', ['browser' => 'r1', 'query' => ['item_type' => ['jacket'], 'q' => 'shell']]));
        $this->assertSame(['KT-2002'], array_column($filtered['items'], 'style_number'));
        $byColor = $this->json($this->http('GET', '/admin/api/portal/products', ['browser' => 'r1', 'query' => ['color' => ['RST']]]));
        $this->assertSame(['KO-1003', 'KO-1006'], array_column($byColor['items'], 'style_number'));
        $byEan = $this->json($this->http('GET', '/admin/api/portal/products', ['browser' => 'r1', 'query' => ['q' => '2000100501016']]));
        $this->assertSame(['KO-1005'], array_column($byEan['items'], 'style_number'));
        $facets = $this->json($this->http('GET', '/admin/api/portal/facets', ['browser' => 'r1', 'query' => ['item_type' => ['jacket']]]));
        $this->assertSame(['Kestrel', 'Kestrel Trail'], array_column($facets['brands'], 'label'));
        $types = array_column($facets['item_types'], 'count', 'value');
        $this->assertSame(1, $types['sweater'], 'own facet ignores its own filter');
        $this->assertSame(2, $types['jacket']);
        $detail = $this->json($this->http('GET', '/admin/api/portal/products/' . $first['id'], ['browser' => 'r1']));
        $this->assertCount(8, $detail['variants']);
        $this->assertArrayHasKey('original', $detail['assets'][0]['renditions']);
        $page2 = $this->json($this->http('GET', '/admin/api/portal/products', ['browser' => 'r1', 'query' => ['limit' => '5']]));
        $this->assertNotNull($page2['next_cursor']);
        $rest = $this->json($this->http('GET', '/admin/api/portal/products', ['browser' => 'r1', 'query' => ['limit' => '5', 'cursor' => $page2['next_cursor']]]));
        $this->assertCount(3, $rest['items']);
        $this->assertNull($rest['next_cursor']);
    }

    public function testEanPasteSelectionZipsAndCsv(): void
    {
        $this->retailerUser('r1', ['name' => 'Shop One']);
        $lookup = $this->json($this->http('POST', '/admin/api/portal/gtin-lookup', ['browser' => 'r1', 'json' => ['text' => "2000100101025\n2000100301012; 9999999999999,abc 2000200201014"]]));
        $this->assertSame(['matched', 'matched', 'unmatched', 'matched'], array_column($lookup['results'], 'status'));
        $ids = array_column($lookup['products'], 'id');
        $this->assertCount(3, $ids);
        $sel = $this->json($this->http('PUT', '/admin/api/portal/selection', ['browser' => 'r1', 'json' => ['product_ids' => array_merge($ids, ['prd_unknown'])]]));
        $this->assertSame($ids, $sel['product_ids']);
        $this->assertSame(4 + 7 + 2, $sel['image_count']);
        $zip = $this->http('GET', '/admin/api/portal/selection/download.zip', ['browser' => 'r1', 'query' => ['variant' => 'web']]);
        $this->assertSame(200, $zip->status);
        $entries = $this->zipEntries($zip->content());
        sort($entries);
        $this->assertContains('KO-1001/KO-1001_NVY_1_front_web.jpg', $entries);
        $this->assertContains('KT-2002/KT-2002_YLW_1_front_web.jpg', $entries);
        $this->assertCount(13, $entries);
        $one = $this->http('GET', '/admin/api/portal/products/' . $ids[0] . '/download.zip', ['browser' => 'r1']);
        $origEntries = $this->zipEntries($one->content());
        $this->assertContains('KO-1001_NVY_1_front.png', $origEntries);
        $csv = $this->http('GET', '/admin/api/portal/selection/data.csv', ['browser' => 'r1', 'query' => ['delimiter' => 'semicolon']]);
        $body = $csv->content();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $lines = explode("\r\n", trim(substr($body, 3)));
        $header = str_getcsv($lines[0], ';', '"', '');
        $this->assertSame(['brand', 'product_id', 'style_number', 'name:nl', 'name:en'], array_slice($header, 0, 5));
        $this->assertSame('image_files', end($header));
        $this->assertCount(1 + 8 + 12 + 3, $lines);
        // Formula injection is neutralised, GTINs stay numeric.
        $this->http('PUT', '/obx/v1/ingest/products/KO-1001', ['bearer' => $this->ingest, 'json' => [
            'brand_external_id' => 'kestrel', 'style_number' => 'KO-1001', 'name' => ['en' => '=HYPERLINK("x")', 'nl' => 'Trui'],
        ]]);
        $csv2 = $this->http('GET', '/admin/api/portal/selection/data.csv', ['browser' => 'r1'])->content();
        $this->assertStringContainsString("'=HYPERLINK", $csv2);
        $this->assertStringNotContainsString("'2000100101025", $csv2);
        // An exported CSV can be imported again: same columns.
        $file = $this->dir . '/export.csv';
        file_put_contents($file, $csv2);
        $job = $this->json($this->http('POST', '/obx/v1/ingest/imports', ['bearer' => $this->ingest, 'files' => ['csv' => [$this->upload($file, 'products.csv')]], 'post' => ['options' => '{"dry_run":true}']]), 202);
        $job = $this->json($this->http('GET', '/obx/v1/ingest/jobs/' . $job['id'], ['bearer' => $this->ingest]));
        $this->assertSame(0, $job['import_run']['counts']['errors'], Json::encode($job['import_run']['errors_preview']));
    }

    public function testBackgroundDownloadJob(): void
    {
        $this->retailerUser('r1', ['name' => 'Shop One']);
        $job = $this->json($this->http('POST', '/admin/api/portal/download-jobs', ['browser' => 'r1', 'json' => ['source' => 'filter', 'filter' => ['brand_id' => [$this->kestrelId]], 'variant' => 'square']]), 202);
        $got = $this->json($this->http('GET', '/admin/api/portal/download-jobs/' . $job['id'], ['browser' => 'r1']));
        $this->assertSame('succeeded', $got['status']);
        $this->assertSame(23, $got['files']);
        $file = $this->http('GET', '/admin/api/portal/download-jobs/' . $job['id'] . '/file', ['browser' => 'r1']);
        $entries = $this->zipEntries($file->content());
        $this->assertContains('data.csv', $entries);
        $this->assertContains('KO-1002/KO-1002_OLV_1_front_square.jpg', $entries);
        $mail = end($this->app->mailer->sent);
        $this->assertSame('r1@shop.example', $mail['to']);
        $this->assertStringContainsString('23', $mail['text']);
        // Other users cannot read the job.
        $this->retailerUser('r2', ['name' => 'Shop Two']);
        $this->assertProblem($this->http('GET', '/admin/api/portal/download-jobs/' . $job['id'], ['browser' => 'r2']), 404, 'not_found');
        $list = $this->json($this->http('GET', '/admin/api/portal/download-jobs', ['browser' => 'r1']));
        $this->assertCount(1, $list['items']);
    }

    public function testFacetCountsEqualTheListWithThatValueChosen(): void
    {
        $this->retailerUser('f1', ['name' => 'Facet Shop']);
        $keys = ['brands' => 'brand_id', 'item_types' => 'item_type', 'colors' => 'color', 'seasons' => 'season', 'collections' => 'collection', 'target_groups' => 'target_group'];
        $combos = [[], ['item_type' => ['jacket']], ['color' => ['BLK'], 'season' => ['AW26']], ['brand_id' => [$this->kestrelId], 'q' => 'o'], ['color' => ['NVY', 'RST'], 'target_group' => ['men']]];
        foreach ($combos as $filters) {
            $facets = $this->json($this->http('GET', '/admin/api/portal/facets', ['browser' => 'f1', 'query' => $filters]));
            if ($filters === []) {
                $this->assertCount(2, $facets['brands']);
                $this->assertNotSame([], $facets['colors']);
            }
            foreach ($keys as $facet => $key) {
                foreach ($facets[$facet] as $item) {
                    // Disjunctive: the count is the number of articles the list shows when this
                    // facet is set to just this value and every other filter stays.
                    $q = array_replace($filters, [$key => [$item['value']]]);
                    $list = $this->json($this->http('GET', '/admin/api/portal/products', ['browser' => 'f1', 'query' => $q + ['limit' => '1']]));
                    $this->assertSame($list['total'], $item['count'], $facet . '=' . $item['value'] . ' with ' . Json::encode($filters));
                }
            }
        }
    }

    public function testRestrictedRetailerNeverSeesTheOtherBrand(): void
    {
        $this->retailerUser('rr', ['name' => 'Kestrel only', 'brand_ids' => [$this->kestrelId], 'allow_original' => false]);
        $list = $this->json($this->http('GET', '/admin/api/portal/products', ['browser' => 'rr']));
        $this->assertSame(6, $list['total']);
        $trail = (string) $this->app->db->value("SELECT id FROM obx_products WHERE external_id = 'KT-2001'");
        $this->assertProblem($this->http('GET', '/admin/api/portal/products/' . $trail, ['browser' => 'rr']), 404, 'not_found');
        $lookup = $this->json($this->http('POST', '/admin/api/portal/gtin-lookup', ['browser' => 'rr', 'json' => ['gtins' => ['2000200101017']]]));
        $this->assertSame('unmatched', $lookup['results'][0]['status']);
        $facets = $this->json($this->http('GET', '/admin/api/portal/facets', ['browser' => 'rr']));
        $this->assertSame(['Kestrel'], array_column($facets['brands'], 'label'));
        $asset = (string) $this->app->db->value("SELECT id FROM obx_assets WHERE external_id = 'KO-1001_NVY_1'");
        $this->assertProblem($this->http('GET', '/admin/api/portal/assets/' . $asset . '/download', ['browser' => 'rr', 'query' => ['variant' => 'original']]), 403, 'original_not_allowed');
        $this->assertSame(200, $this->http('GET', '/admin/api/portal/assets/' . $asset . '/download', ['browser' => 'rr'])->status);
        $detail = $this->json($this->http('GET', '/admin/api/portal/products/' . (string) $this->app->db->value("SELECT id FROM obx_products WHERE external_id = 'KO-1001'"), ['browser' => 'rr']));
        $this->assertArrayNotHasKey('original', $detail['assets'][0]['renditions']);

        // The same restriction on the API: snapshot, lookups.
        [, $id, $secret] = $this->retailerClient(['name' => 'Kestrel only API', 'brand_ids' => [$this->kestrelId]]);
        $token = $this->accessToken($id, $secret);
        [$entries] = $this->drainSnapshot($token);
        foreach ($entries as $e) {
            $this->assertSame($this->kestrelId, $e['brand_id']);
        }
        $this->assertSame(1, count(array_filter($entries, static fn ($e) => $e['type'] === 'brand')));
        $this->assertProblem($this->http('GET', '/obx/v1/products/' . $trail, ['bearer' => $token]), 404, 'not_found');
    }

    public function testGrantChangeWritesResyncEntry(): void
    {
        [$rid, $id, $secret] = $this->retailerClient(['name' => 'Grant Shop']);
        $token = $this->accessToken($id, $secret);
        [, $cursor] = $this->drainSnapshot($token);
        $this->json($this->http('PATCH', '/admin/api/retailers/' . $rid, ['browser' => 'admin', 'json' => ['brand_ids' => [$this->kestrelId]]]));
        $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $token, 'query' => ['cursor' => $cursor]]));
        $this->assertCount(1, $page['changes']);
        $this->assertSame('grant', $page['changes'][0]['type']);
        $this->assertTrue($page['changes'][0]['resync']);
        $this->assertSame([$this->kestrelId], $page['changes'][0]['data']['brand_ids']);
    }

    public function testDownloadsAreCountedForRetailersNotForAdminPreview(): void
    {
        $this->retailerUser('r1', ['name' => 'Counted Shop']);
        $asset = (string) $this->app->db->value("SELECT id FROM obx_assets WHERE external_id = 'KO-1001_NVY_1'");
        $this->assertSame(200, $this->http('GET', '/admin/api/portal/assets/' . $asset . '/download', ['browser' => 'r1', 'query' => ['variant' => 'web']])->status);
        $this->assertSame(200, $this->http('GET', '/admin/api/portal/assets/' . $asset . '/download', ['browser' => 'admin', 'query' => ['variant' => 'web']])->status);
        [, $id, $secret] = $this->retailerClient(['name' => 'API Shop']);
        $token = $this->accessToken($id, $secret);
        $this->assertSame(200, $this->http('GET', '/obx/v1/assets/' . $asset . '/renditions/thumb', ['bearer' => $token])->status);
        $usage = $this->json($this->http('POST', '/obx/v1/usage', ['bearer' => $token, 'json' => ['events' => [
            ['asset_id' => $asset, 'event' => 'published', 'channel' => 'webshop', 'at' => '2026-09-28T10:00:00Z'],
            ['asset_id' => 'ast_unknown', 'event' => 'synced', 'at' => '2026-09-28T10:00:00Z'],
        ]]]), 202);
        $this->assertSame(1, $usage['accepted']);
        $stats = $this->json($this->http('GET', '/admin/api/stats', ['browser' => 'admin', 'query' => ['days' => '7']]));
        $this->assertSame(2, $stats['totals']['downloads']);
        $this->assertSame(1, $stats['totals']['api_downloads']);
        $this->assertSame(1, $stats['totals']['usage_events']);
        $this->assertSame(2, $stats['totals']['active_retailers']);
        $names = array_column($stats['top_retailers'], 'name');
        sort($names);
        $this->assertSame(['API Shop', 'Counted Shop'], $names);
        $events = $this->json($this->http('GET', '/admin/api/stats/events', ['browser' => 'admin']));
        $this->assertCount(3, $events['items']);
    }

    public function testSelfServiceApiKeysNeedTheBrandSwitch(): void
    {
        $this->retailerUser('r1', ['name' => 'Self Service']);
        $this->assertProblem($this->http('POST', '/admin/api/portal/api-clients', ['browser' => 'r1', 'json' => ['name' => 'My shop']]), 403, 'forbidden');
        $this->json($this->http('PATCH', '/admin/api/settings', ['browser' => 'admin', 'json' => ['portal' => ['retailer_api_self_service' => true]]]));
        $c = $this->json($this->http('POST', '/admin/api/portal/api-clients', ['browser' => 'r1', 'json' => ['name' => 'My shop']]), 201);
        $this->assertStringStartsWith('obxs_', $c['client_secret']);
        $this->assertSame(200, $this->http('POST', '/obx/v1/oauth/token', ['basic' => [$c['client']['client_id'], $c['client_secret']], 'post' => ['grant_type' => 'client_credentials']])->status);
        $this->assertCount(1, $this->json($this->http('GET', '/admin/api/portal/api-clients', ['browser' => 'r1']))['items']);
        $this->assertSame(204, $this->http('DELETE', '/admin/api/portal/api-clients/' . $c['client']['id'], ['browser' => 'r1'])->status);
    }

    public function testExportManifestAndParts(): void
    {
        [, $id, $secret] = $this->retailerClient(['name' => 'Export Shop']);
        $token = $this->accessToken($id, $secret);
        $export = $this->json($this->http('POST', '/obx/v1/exports', ['bearer' => $token, 'json' => ['filter' => ['brand_ids' => [$this->kestrelId]], 'renditions' => ['web']]]), 202);
        $done = $this->json($this->http('GET', '/obx/v1/exports/' . $export['id'], ['bearer' => $token]));
        $this->assertSame('succeeded', $done['status'], Json::encode($done));
        $manifest = $this->http('GET', substr($done['manifest_url'], strlen('http://localhost')))->content();
        $lines = array_map([Json::class, 'decode'], array_filter(explode("\n", $manifest)));
        $this->assertSame('header', $lines[0]['type']);
        $this->assertSame(6, $lines[0]['counts']['products']);
        $this->assertSame(23, $lines[0]['counts']['files']);
        $part = $this->http('GET', substr($done['parts'][0]['url'], strlen('http://localhost')));
        $zipBytes = $part->content();
        $this->assertSame($done['parts'][0]['sha256'], hash('sha256', $zipBytes));
        $f = $this->dir . '/part.zip';
        file_put_contents($f, $zipBytes);
        $z = new \ZipArchive();
        $z->open($f);
        foreach ($lines as $l) {
            if ($l['type'] === 'file') {
                $this->assertSame($l['sha256'], hash('sha256', (string) $z->getFromName($l['path'])), $l['path']);
                $this->assertMatchesRegularExpression('#^KO-\d+/web/.+_web\.jpg$#', $l['path']);
            }
        }
        $z->close();
        // The header cursor continues the live feed.
        $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $token, 'query' => ['cursor' => $lines[0]['changes_cursor']]]));
        $this->assertSame('live', $page['phase']);
        // Other retailers cannot read it.
        [, $id2, $secret2] = $this->retailerClient(['name' => 'Other Shop']);
        $this->assertProblem($this->http('GET', '/obx/v1/exports/' . $export['id'], ['bearer' => $this->accessToken($id2, $secret2)]), 404, 'not_found');
    }

    public function testRightsExpiryHidesTheAssetAndWritesATombstone(): void
    {
        [, $id, $secret] = $this->retailerClient(['name' => 'Expiry Shop']);
        $token = $this->accessToken($id, $secret);
        $ingest = $this->ingest;
        $rec = $this->json($this->http('GET', '/obx/v1/ingest/assets/KO-1001_NVY_2', ['bearer' => $ingest]));
        $data = $rec['data'];
        unset($data['status']);
        $data['rights'] = ['expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 60)];
        $this->json($this->http('PUT', '/obx/v1/ingest/assets/KO-1001_NVY_2', ['bearer' => $ingest, 'json' => $data]));
        [, $cursor] = $this->drainSnapshot($token);
        \Obx\Node\Support\Clock::$offset = 120;
        $this->app->worker()->tick(5);
        $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $token, 'query' => ['cursor' => $cursor]]));
        $this->assertSame([['asset', 'delete', 'KO-1001_NVY_2']], array_map(static fn ($c) => [$c['type'], $c['op'], $c['external_id']], $page['changes']));
        $this->assertProblem($this->http('GET', '/obx/v1/assets/' . $rec['id'], ['bearer' => $token]), 404, 'not_found');
    }

    public function testLargeExportsAreSplitIntoIndependentParts(): void
    {
        $this->app = $this->makeApp(['export_part_bytes' => 1]);
        [, $id, $secret] = $this->retailerClient(['name' => 'Parts Shop']);
        $token = $this->accessToken($id, $secret);
        $export = $this->json($this->http('POST', '/obx/v1/exports', ['bearer' => $token, 'json' => ['renditions' => ['original', 'web', 'square'], 'include_data' => false]]), 202);
        $done = $this->json($this->http('GET', '/obx/v1/exports/' . $export['id'], ['bearer' => $token]));
        $this->assertGreaterThan(1, count($done['parts']));
        $manifest = array_map([Json::class, 'decode'], array_filter(explode("\n", $this->http('GET', substr($done['manifest_url'], strlen('http://localhost')))->content())));
        $this->assertSame(81, $manifest[0]['counts']['files']);
        $this->assertSame(['header', 'file'], array_values(array_unique(array_column($manifest, 'type'))), 'include_data=false leaves out data lines');
        $zips = [];
        foreach ($done['parts'] as $p) {
            $f = $this->dir . '/part' . $p['number'] . '.zip';
            file_put_contents($f, $this->http('GET', '/obx/v1/exports/' . $export['id'] . '/parts/' . $p['number'], ['bearer' => $token])->content());
            $this->assertSame($p['sha256'], hash_file('sha256', $f));
            $zips[$p['number']] = new \ZipArchive();
            $zips[$p['number']]->open($f);
        }
        foreach ($manifest as $l) {
            if ($l['type'] === 'file') {
                $this->assertSame($l['sha256'], hash('sha256', (string) $zips[$l['part']]->getFromName($l['path'])));
            }
        }
        // Another concurrent export is refused while one runs; an expired one answers 410.
        $this->app->db->run("UPDATE obx_exports SET expires_ts = 1 WHERE id = ?", [$export['id']]);
        $this->assertProblem($this->http('GET', '/obx/v1/exports/' . $export['id'] . '/parts/1', ['bearer' => $token]), 410, 'export_expired');
    }

    public function testOldCursorsExpireAfterRetention(): void
    {
        [, $id, $secret] = $this->retailerClient(['name' => 'Cursor Shop']);
        $token = $this->accessToken($id, $secret);
        [, $cursor] = $this->drainSnapshot($token);
        $this->app->writer->setStatus('product', 'KO-1006', false);
        $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $token, 'query' => ['cursor' => $cursor]]));
        $this->assertNotEmpty($page['changes']);
        $this->app->db->run('UPDATE obx_changes SET created_ts = 1');
        $this->app->feed->prune();
        $this->assertProblem($this->http('GET', '/obx/v1/changes', ['bearer' => $token, 'query' => ['cursor' => $cursor]]), 410, 'cursor_expired');
        // A new snapshot still works after pruning, and so does the newest cursor.
        [$entries, $fresh] = $this->drainSnapshot($token);
        $this->assertNotEmpty($entries);
        $this->assertSame(200, $this->http('GET', '/obx/v1/changes', ['bearer' => $token, 'query' => ['cursor' => $page['next_cursor']]])->status);
    }

    public function testAccessRequestsCanBeSwitchedOffAndAreRateLimited(): void
    {
        $body = ['retailer' => ['name' => 'Flood BV'], 'contact' => ['name' => 'F', 'email' => 'f@flood.example']];
        $strict = $this->makeApp(['rate_limit_factor' => 1.0]);
        $this->app = $strict;
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(202, $this->http('POST', '/obx/v1/access-requests', ['json' => $body, 'ip' => '198.51.100.7'])->status);
        }
        $res = $this->http('POST', '/obx/v1/access-requests', ['json' => $body, 'ip' => '198.51.100.7']);
        $this->assertProblem($res, 429, 'rate_limited');
        $this->assertNotNull($res->getHeader('Retry-After'));
        $this->assertSame('0', $res->getHeader('RateLimit-Remaining'));
        $this->json($this->http('PATCH', '/admin/api/settings', ['browser' => 'admin', 'json' => ['portal' => ['access_requests_enabled' => false]]]));
        $this->assertProblem($this->http('POST', '/obx/v1/access-requests', ['json' => $body, 'ip' => '198.51.100.8']), 403, 'forbidden');
        $doc = $this->json($this->http('GET', '/.well-known/obx'));
        $this->assertNull($doc['access_requests_url']);
        $this->assertNotContains('access_requests', $doc['capabilities']);
    }
}
