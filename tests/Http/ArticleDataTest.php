<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Http;

use Obx\Node\Db\Schema;
use Obx\Node\Support\Json;
use Obx\Node\Tests\Support\NodeTestCase;

/**
 * Article data of spec 0.2 (docs/protocol.md §5.9): the import columns and rule 9 per key
 * (fixtures/imports, conformance I-IMP-7), label warnings (rule 10, I-IMP-8), the Ingest API
 * write rules (ingest.md §3.4), the shape of every protocol object (P-CAT-6), the portal CSV
 * and the upgrade of a 0.1 database.
 */
final class ArticleDataTest extends NodeTestCase
{
    /** @return array<string, mixed> the finished job */
    private function importCsv(string $token, string $csv, array $options = ['mode' => 'upsert']): array
    {
        $path = $this->dir . '/p-' . bin2hex(random_bytes(3)) . '.csv';
        file_put_contents($path, $csv);
        $job = $this->json($this->http('POST', '/obx/v1/ingest/imports', [
            'bearer' => $token,
            'files' => ['csv' => [$this->upload($path, 'products.csv', 'text/csv')]],
            'post' => ['options' => Json::encode($options)],
        ]), 202);
        $done = $this->json($this->http('GET', '/obx/v1/ingest/jobs/' . $job['id'], ['bearer' => $token]));
        $this->assertSame('succeeded', $done['status'], Json::encode($done));
        return $done;
    }

    /** @return list<array<string, mixed>> */
    private function rowErrors(array $job): array
    {
        $run = $job['import_run']['id'];
        $items = [];
        $cursor = null;
        do {
            $page = $this->json($this->http('GET', '/admin/api/imports/' . $run . '/errors', ['browser' => 'admin', 'query' => array_filter(['limit' => '200', 'cursor' => $cursor])]));
            array_push($items, ...$page['items']);
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        return $items;
    }

    /** @return array<string, array<string, mixed>> protocol objects by "product:ext" / "variant:ext" */
    private function protocolObjects(string $client): array
    {
        $out = [];
        $cursor = null;
        do {
            $page = $this->json($this->http('GET', '/obx/v1/products', ['bearer' => $client, 'query' => array_filter(['limit' => '100', 'cursor' => $cursor])]));
            foreach ($page['items'] as $p) {
                $out['product:' . $p['external_id']] = $p;
                foreach ($p['variants'] as $v) {
                    $out['variant:' . $v['external_id']] = $v;
                }
            }
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        return $out;
    }

    public function testImportCasesOfTheSpecFixtures(): void
    {
        ['ingest' => $token] = $this->kestrelNode();
        [, $id, $secret] = $this->retailerClient();
        $client = $this->accessToken($id, $secret);
        [, $cursor] = $this->drainSnapshot($client);
        $cases = Json::decode((string) file_get_contents(self::fixtures('imports/expected.json')))['cases'];
        foreach ($cases as $c) {
            $job = $this->importCsv($token, (string) file_get_contents(self::fixtures('imports/' . $c['file'])));
            $counts = $job['import_run']['counts'];
            foreach ($c['counts'] as $k => $want) {
                $this->assertSame($want, $counts[$k], $c['file'] . ' counts.' . $k . ' ' . Json::encode($counts));
            }
            $errors = array_values(array_filter($this->rowErrors($job), static fn ($e) => $e['severity'] === 'error'));
            $this->assertCount(count($c['errors']), $errors, $c['file'] . ': ' . Json::encode($errors));
            foreach ($c['errors'] as $i => $want) {
                foreach ($want as $k => $v) {
                    $this->assertSame($v, $errors[$i][$k], $c['file'] . ' error ' . $i . ' ' . $k);
                }
            }
            $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $client, 'query' => ['cursor' => $cursor, 'limit' => '500']]));
            $cursor = $page['next_cursor'];
            $this->assertSame(
                array_map(static fn ($w) => $w['type'] . ' ' . $w['op'] . ' ' . $w['external_id'], $c['changes']),
                array_map(static fn ($e) => $e['type'] . ' ' . $e['op'] . ' ' . $e['external_id'], $page['changes']),
                $c['file'] . ' change entries',
            );
            $objects = $this->protocolObjects($client);
            foreach (['product' => $c['products'], 'variant' => $c['variants']] as $kind => $list) {
                foreach ($list as $ext => $fields) {
                    foreach ($fields as $field => $want) {
                        $this->assertMatches($want, $objects[$kind . ':' . $ext][$field], $c['file'] . ' ' . $kind . ' ' . $ext . '.' . $field);
                    }
                }
            }
        }
    }

    public function testLabelWarningsOfADryRun(): void
    {
        ['ingest' => $token] = $this->kestrelNode();
        $job = $this->importCsv($token, (string) file_get_contents(self::fixtures('imports/label-warnings.csv')), ['mode' => 'upsert', 'dry_run' => true]);
        $all = $this->rowErrors($job);
        $this->assertSame([], array_values(array_filter($all, static fn ($e) => $e['severity'] === 'error')));
        $codes = ['fibre_composition_missing', 'animal_origin_unknown', 'footwear_materials_missing', 'fibre_percentages_not_100'];
        $got = array_map(
            static fn ($e) => ['file' => $e['file'], 'row' => $e['row'], 'column' => $e['column'], 'severity' => $e['severity'], 'obx_error' => $e['obx_error']],
            array_values(array_filter($all, static fn ($e) => in_array($e['obx_error'], $codes, true))),
        );
        $this->assertSame(Json::decode((string) file_get_contents(self::fixtures('imports/label-warnings.expected.json')))['warnings'], $got);
        $this->assertSame(0, (int) $this->app->db->value("SELECT COUNT(*) FROM obx_products WHERE external_id LIKE 'KL-%'"));

        // The fixture catalog itself is complete.
        $again = $this->importKestrel($token);
        $this->assertSame([], array_values(array_filter($this->rowErrors($again), static fn ($e) => in_array($e['obx_error'], $codes, true))));
    }

    public function testEveryProtocolObjectHasTheArticleDataShape(): void
    {
        $this->kestrelNode();
        [, $id, $secret] = $this->retailerClient();
        [$entries] = $this->drainSnapshot($this->accessToken($id, $secret));
        foreach ($entries as $e) {
            $d = $e['data'];
            if ($e['type'] === 'product') {
                foreach (['item_subtype', 'contains_animal_origin', 'footwear_materials', 'certifications', 'features'] as $k) {
                    $this->assertArrayHasKey($k, $d, $d['external_id']);
                }
                $this->assertNotSame([], $d['features']);
                foreach ((array) $d['materials'] as $part) {
                    foreach ($part['fibers'] as $f) {
                        $this->assertDoesNotMatchRegularExpression('/^(recycled|organic)_/', $f['fiber']);
                        $this->assertNotContains(false, [$f['recycled'] ?? true, $f['organic'] ?? true]);
                    }
                }
                if ($d['footwear_materials'] !== null) {
                    $this->assertSame(['upper', 'lining_and_sock', 'outer_sole'], array_keys($d['footwear_materials']));
                }
            } elseif ($e['type'] === 'variant') {
                foreach (['size_length', 'measurements'] as $k) {
                    $this->assertArrayHasKey($k, $d);
                }
                $this->assertArrayHasKey('family', $d['color']);
                $this->assertNotSame([], $d['measurements']);
            }
        }
    }

    public function testIngestWritesNormaliseValidateAndWarn(): void
    {
        $this->kestrelNode();
        $token = $this->ingestToken();
        $put = fn (string $kind, string $ext, array $body) => $this->http('PUT', '/obx/v1/ingest/' . $kind . 's/' . rawurlencode($ext), ['bearer' => $token, 'json' => $body]);
        $body = [
            'brand_external_id' => 'kestrel', 'style_number' => 'KX-1', 'name' => ['en' => 'Test scarf'], 'item_type' => 'scarf',
            'materials' => [['part' => 'main', 'fibers' => [['fiber' => 'organic_cotton', 'percentage' => 60], ['fiber' => 'wool', 'percentage' => 30, 'recycled' => false]]]],
            'features' => ['fit' => 'regular', 'fringe_cm' => 5], 'certifications' => [],
        ];
        $res = $this->json($put('product', 'KX-1', $body), 201);
        $this->assertSame([
            ['code' => 'animal_origin_unknown', 'pointer' => '/contains_animal_origin'],
            ['code' => 'fibre_percentages_not_100', 'pointer' => '/materials/0'],
        ], array_map(static fn ($w) => ['code' => $w['code'], 'pointer' => $w['pointer']], $res['warning_details']));
        $this->assertCount(2, array_filter($res['warnings'], static fn ($w) => str_starts_with($w, 'animal_origin_unknown') || str_starts_with($w, 'fibre_percentages_not_100')));
        $rec = $this->json($this->http('GET', '/obx/v1/ingest/products/KX-1', ['bearer' => $token]));
        $this->assertSame([['part' => 'main', 'fibers' => [['fiber' => 'cotton', 'percentage' => 60, 'organic' => true], ['fiber' => 'wool', 'percentage' => 30]]]], $rec['data']['materials']);
        $this->assertSame(['fit' => 'regular', 'fringe_cm' => 5], $rec['data']['features']);
        $this->assertNull($rec['data']['certifications']);
        // The data of a GET written back is unchanged; so is the flag form of the 0.1 key.
        $this->assertSame('unchanged', $this->json($put('product', 'KX-1', $rec['data']))['result']);
        $body['materials'][0]['fibers'][0] = ['fiber' => 'cotton', 'percentage' => 60, 'organic' => true];
        $this->assertSame('unchanged', $this->json($put('product', 'KX-1', $body))['result']);

        foreach ([['wheels' => 3], ['shell' => 'glass'], ['detachable_parts' => ['hood', 'hood']], ['expandable' => 'yes']] as $bad) {
            $p = $this->assertProblem($put('product', 'KX-1', ['features' => $bad] + $body), 422, 'validation_failed');
            $this->assertSame('/features/' . array_key_first($bad), $p['errors'][0]['pointer']);
        }
        $this->assertProblem($put('product', 'KX-1', ['footwear_materials' => ['upper' => 'plastic']] + $body), 422, 'validation_failed');
        $this->assertProblem($put('product', 'KX-1', ['certifications' => [['scheme' => 'GOTS', 'scope' => 'product']]] + $body), 422, 'validation_failed');
        $this->assertProblem($put('product', 'KX-1', ['contains_animal_origin' => 'no'] + $body), 422, 'validation_failed');
        $ok = $this->json($put('product', 'KX-1', ['features' => [], 'contains_animal_origin' => false, 'materials' => [['part' => 'main', 'fibers' => [['fiber' => 'cotton', 'percentage' => 100]]]]] + $body));
        $this->assertArrayNotHasKey('warning_details', $ok, 'a complete label has no warning details');
        $this->assertNull($this->json($this->http('GET', '/obx/v1/ingest/products/KX-1', ['bearer' => $token]))['data']['features']);

        $v = ['product_external_id' => 'KX-1', 'gtin' => '2000199901018', 'color' => ['code' => 'RED', 'family' => 'red'], 'measurements' => []];
        $this->json($put('variant', '2000199901018', $v), 201);
        $this->assertProblem($put('variant', '2000199901018', ['measurements' => ['weight_kg' => 0]] + $v), 422, 'validation_failed');
        $p = $this->assertProblem($put('variant', '2000199901018', ['measurements' => ['strap_length_cm' => ['min' => 130, 'max' => 80]]] + $v), 422, 'validation_failed');
        $this->assertSame('/measurements/strap_length_cm', $p['errors'][0]['pointer']);
        // The same in an import: the row is skipped with validation_failed in that column.
        $job = $this->importCsv($token, "brand,style_number,name:en,gtin,color_code,strap_length_cm\nKestrel,KX-1,Test scarf,2000199901018,RED,130-80\n");
        $this->assertSame(1, $job['import_run']['counts']['errors']);
        $err = array_values(array_filter($this->rowErrors($job), static fn ($e) => $e['severity'] === 'error'))[0];
        $this->assertSame(['strap_length_cm', 'validation_failed'], [$err['column'], $err['obx_error']]);
        $this->assertProblem($put('variant', '2000199901018', ['color' => ['code' => 'RED', 'family' => 'navy']] + $v), 422, 'validation_failed');
        $this->json($put('variant', '2000199901018', ['size_length' => '34', 'measurements' => ['strap_length_cm' => ['min' => 120, 'max' => 120], 'weight_kg' => 0.9]] + $v));

        [, $id, $secret] = $this->retailerClient();
        $client = $this->accessToken($id, $secret);
        $pid = (string) $this->app->db->value("SELECT id FROM obx_products WHERE external_id = 'KX-1'");
        $p = $this->json($this->http('GET', '/obx/v1/products/' . $pid, ['bearer' => $client]));
        foreach (['item_subtype', 'footwear_materials', 'certifications', 'features'] as $k) {
            $this->assertNull($p[$k], $k);
        }
        $this->assertFalse($p['contains_animal_origin']);
        $this->assertSame('red', $p['variants'][0]['color']['family']);
        $this->assertSame('34', $p['variants'][0]['size_length']);
        $this->assertSame(['weight_kg' => 0.9, 'strap_length_cm' => ['min' => 120, 'max' => 120]], $p['variants'][0]['measurements']);
    }

    public function testPortalCsvHasTheImportColumnsAndImportsBackUnchanged(): void
    {
        ['ingest' => $token] = $this->kestrelNode();
        $r = $this->json($this->http('POST', '/admin/api/retailers', ['browser' => 'admin', 'json' => ['name' => 'Shop One']]), 201);
        $inv = $this->json($this->http('POST', '/admin/api/users/invites', ['browser' => 'admin', 'json' => ['email' => 'r1@shop.example', 'role' => 'retailer', 'retailer_id' => $r['id']]]), 201);
        $this->http('GET', '/admin/api/session', ['browser' => 'r1']);
        $t = substr($inv['invite_url'], strrpos($inv['invite_url'], '/') + 1);
        $this->json($this->http('POST', '/admin/api/invites/' . $t . '/accept', ['browser' => 'r1', 'json' => ['password' => 'a very good passphrase']]));
        $res = $this->http('GET', '/admin/api/portal/products/data.csv', ['browser' => 'r1']);
        $this->assertSame(200, $res->status, $res->content());
        $csv = $res->content();
        $lines = preg_split('/\r\n/', trim(substr($csv, 3)));
        $header = str_getcsv($lines[0], ',', '"', '');
        foreach (['item_subtype', 'contains_animal_origin', 'footwear_materials', 'certifications', 'feature:shell', 'feature:neckline', 'color_family', 'size_length', 'height_cm', 'strap_length_cm', 'inseam_cm', 'image_files'] as $col) {
            $this->assertContains($col, $header);
        }
        $this->assertLessThan(array_search('feature:shell', $header, true), array_search('dpp_url', $header, true));
        $this->assertLessThan(array_search('variant_id', $header, true), array_search('feature:neckline', $header, true));
        $rows = [];
        foreach (array_slice($lines, 1) as $l) {
            $cells = str_getcsv($l, ',', '"', '');
            $rows[] = array_combine($header, $cells);
        }
        $boot = array_values(array_filter($rows, static fn ($r) => $r['variant_id'] === '2000101001027'))[0];
        $this->assertSame('upper: leather; lining_and_sock: leather; outer_sole: other', $boot['footwear_materials']);
        $this->assertSame('lwg:LWG-200-1010 (material)', $boot['certifications']);
        $this->assertSame('14.5', $boot['shaft_height_cm']);
        $this->assertSame('insole', $boot['feature:detachable_parts']);
        $bag = array_values(array_filter($rows, static fn ($r) => $r['style_number'] === 'KO-1009'))[0];
        $this->assertSame('85-140', $bag['strap_length_cm']);
        $this->assertSame('yes', $bag['feature:laptop_compartment']);
        $this->assertSame('outer: 100% polyester (recycled); lining: 100% polyamide', $bag['materials']);

        // The export is an import file: importing it changes nothing.
        $job = $this->importCsv($token, $csv);
        $this->assertSame(0, $job['import_run']['counts']['updated'], Json::encode($job['import_run']));
        $this->assertSame(0, $job['import_run']['counts']['errors'], Json::encode($job['import_run']));
    }

    public function testUpgradeOfA01Database(): void
    {
        $this->kestrelNode();
        [, $id, $secret] = $this->retailerClient();
        $client = $this->accessToken($id, $secret);
        [, $cursor] = $this->drainSnapshot($client);
        $db = $this->app->db;
        // Write KT-2001, KO-1006 and its sizes back as 0.1 stored them: without the 0.2 fields
        // that are not stated, and KT-2001 with the 0.1 fibre key.
        $before = $this->json($this->http('GET', '/obx/v1/products/' . $db->value("SELECT id FROM obx_products WHERE external_id = 'KT-2001'"), ['bearer' => $client]));
        $unsetNull = static function (array &$a, array $keys): void {
            foreach ($keys as $k) {
                if (array_key_exists($k, $a) && $a[$k] === null) {
                    unset($a[$k]);
                }
            }
        };
        foreach ($db->all("SELECT * FROM obx_products WHERE external_id IN ('KT-2001', 'KO-1006')") as $p) {
            $in = Json::decode((string) $p['input']);
            $unsetNull($in, ['item_subtype', 'contains_animal_origin', 'footwear_materials', 'certifications', 'features']);
            if ($p['external_id'] === 'KT-2001') {
                $in['materials'][0]['fibers'][0] = ['fiber' => 'recycled_polyester', 'percentage' => 100];
            }
            $rep = Json::decode((string) $p['rep']);
            $unsetNull($rep, ['item_subtype', 'contains_animal_origin', 'footwear_materials', 'certifications', 'features']);
            $rep['materials'] = $in['materials'];
            $db->update('obx_products', ['input' => Json::encode($in), 'rep' => Json::encode($rep), 'rep_hash' => hash('sha256', Json::canonical($rep)), 'etag' => '"old"'], ['id' => $p['id']]);
        }
        foreach ($db->all("SELECT v.* FROM obx_variants v JOIN obx_products p ON p.id = v.product_id WHERE p.external_id = 'KO-1006'") as $v) {
            $in = Json::decode((string) $v['input']);
            $unsetNull($in, ['size_length', 'measurements']);
            $rep = Json::decode((string) $v['rep']);
            $unsetNull($rep, ['size_length', 'measurements']);
            $db->update('obx_variants', ['input' => Json::encode($in), 'rep' => Json::encode($rep), 'rep_hash' => hash('sha256', Json::canonical($rep))], ['id' => $v['id']]);
        }
        $db->run("UPDATE obx_settings SET v = '3' WHERE k = 'schema_version'");
        Schema::migrate($db);
        $this->assertSame(Schema::latestVersion(), Schema::currentVersionOf($db));
        Schema::migrate($db); // nothing left to do

        // Only the product whose content changed (the 0.1 fibre key) has a new entry.
        $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $client, 'query' => ['cursor' => $cursor]]));
        $this->assertSame(['product upsert KT-2001'], array_map(static fn ($e) => $e['type'] . ' ' . $e['op'] . ' ' . $e['external_id'], $page['changes']));
        $after = $page['changes'][0]['data'];
        $this->assertSame(['fiber' => 'polyester', 'percentage' => 100, 'recycled' => true], $after['materials'][0]['fibers'][0]);
        $this->assertSame(array_keys($before), array_keys($after + ['variants' => [], 'assets' => []]));
        // The same file again is all unchanged: the stored model is the 0.2 one.
        $token = $this->ingestToken();
        $job = $this->importCsv($token, (string) file_get_contents(self::fixtures('kestrel/products.csv')));
        $this->assertSame(0, $job['import_run']['counts']['updated'], Json::encode($job['import_run']['counts']));
    }
}
