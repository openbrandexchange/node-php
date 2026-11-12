<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Http;

use Obx\Node\Catalog\ArticleData;
use Obx\Node\Db\Schema;
use Obx\Node\Support\Json;
use Obx\Node\Tests\Support\NodeTestCase;

/**
 * Article data of spec 0.3 (docs/protocol.md §5.9): the import columns and rule 9 per key
 * (fixtures/imports, conformance I-IMP-7), label warnings (rule 10, I-IMP-8), the Ingest API
 * write rules (ingest.md §3.4) with materials and care as terms with names, the shape of every
 * protocol object (P-CAT-6), the portal CSV and the upgrade of a 0.1 and a 0.2 database.
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
                    $this->assertSame(['code', 'name'], array_keys($part['part']));
                    $this->assertNameOf('parts', $part['part'], $d['external_id']);
                    foreach ($part['fibers'] as $f) {
                        $this->assertSame(['material', 'percentage', 'qualifier'], array_keys($f));
                        $this->assertDoesNotMatchRegularExpression('/^(recycled|organic)_/', $f['material']['code']);
                        $this->assertNameOf('materials', $f['material'], $d['external_id']);
                        if ($f['qualifier'] !== null) {
                            $this->assertNameOf('qualifiers', $f['qualifier'], $d['external_id']);
                        }
                    }
                }
                foreach ((array) $d['care'] as $c) {
                    $this->assertSame(['code', 'category', 'symbol', 'name', 'text'], array_keys($c));
                    $known = ArticleData::vocabulary('care')[$c['code']];
                    $this->assertSame([$known['category'], $known['symbol'], $known['name']], [$c['category'], $c['symbol'], $c['name']], $d['external_id'] . ' ' . $c['code']);
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

    /**
     * A term of the Kestrel catalog has exactly the vocabulary's name; main has none.
     *
     * @param array{code: string, name: mixed} $term
     */
    private function assertNameOf(string $vocabulary, array $term, string $where): void
    {
        $known = ArticleData::vocabulary($vocabulary)[$term['code']] ?? null;
        $this->assertNotNull($known, $where . ': ' . $term['code'] . ' is in vocabularies/' . $vocabulary . '.json');
        $this->assertEquals($known['name'], $term['name'], $where . ' ' . $term['code']);
        if ($term['code'] === 'main') {
            $this->assertNull($term['name']);
        }
        foreach ((array) $term['name'] as $text) {
            $this->assertNotSame('', trim((string) $text));
        }
    }

    public function testIngestWritesNormaliseValidateAndWarn(): void
    {
        $this->kestrelNode();
        $token = $this->ingestToken();
        $put = fn (string $kind, string $ext, array $body) => $this->http('PUT', '/obx/v1/ingest/' . $kind . 's/' . rawurlencode($ext), ['bearer' => $token, 'json' => $body]);
        $body = [
            'brand_external_id' => 'kestrel', 'style_number' => 'KX-1', 'name' => ['en' => 'Test scarf'], 'item_type' => 'scarf',
            'materials' => [['part' => ['code' => 'main'], 'fibers' => [
                ['material' => ['code' => 'organic_cotton'], 'percentage' => 60],
                ['material' => ['code' => 'wool', 'name' => ['en' => 'Sheep']], 'percentage' => 30, 'qualifier' => null],
            ]]],
            'care' => [['code' => 'wash_30', 'text' => ['en' => 'Inside out']], ['code' => 'steam_only', 'category' => 'ironing', 'name' => ['en' => 'Steam only']]],
            'features' => ['fit' => 'regular', 'fringe_cm' => 5], 'certifications' => [],
        ];
        $res = $this->json($put('product', 'KX-1', $body), 201);
        $this->assertSame([
            ['code' => 'animal_origin_unknown', 'pointer' => '/contains_animal_origin'],
            ['code' => 'fibre_percentages_not_100', 'pointer' => '/materials/0'],
        ], array_map(static fn ($w) => ['code' => $w['code'], 'pointer' => $w['pointer']], $res['warning_details']));
        $this->assertCount(2, array_filter($res['warnings'], static fn ($w) => str_starts_with($w, 'animal_origin_unknown') || str_starts_with($w, 'fibre_percentages_not_100')));
        $this->assertStringContainsString('contain wool', implode(' ', $res['warnings']), 'the label warning names the material code');
        $rec = $this->json($this->http('GET', '/obx/v1/ingest/products/KX-1', ['bearer' => $token]));
        // Known codes get the vocabulary's names (a name sent for one is not kept), the 0.1 key
        // its qualifier; an unknown care code keeps what was sent.
        $vocab = static fn (string $v, string $code) => ArticleData::vocabulary($v)[$code]['name'];
        $this->assertSame([['part' => ['code' => 'main', 'name' => null], 'fibers' => [
            ['material' => ['code' => 'cotton', 'name' => $vocab('materials', 'cotton')], 'percentage' => 60, 'qualifier' => ['code' => 'organic', 'name' => $vocab('qualifiers', 'organic')]],
            ['material' => ['code' => 'wool', 'name' => $vocab('materials', 'wool')], 'percentage' => 30, 'qualifier' => null],
        ]]], $rec['data']['materials']);
        $this->assertSame(['code' => 'wash_30', 'category' => 'washing', 'symbol' => '1_D_30'], array_slice($rec['data']['care'][0], 0, 3));
        $this->assertSame(['en' => 'Inside out'], $rec['data']['care'][0]['text']);
        $this->assertSame(['code' => 'steam_only', 'category' => 'ironing', 'symbol' => null, 'name' => ['en' => 'Steam only'], 'text' => null], $rec['data']['care'][1]);
        $this->assertSame(['fit' => 'regular', 'fringe_cm' => 5], $rec['data']['features']);
        $this->assertNull($rec['data']['certifications']);
        // The data of a GET written back is unchanged; so is the qualifier form of the 0.1 key.
        $this->assertSame('unchanged', $this->json($put('product', 'KX-1', $rec['data']))['result']);
        $body['materials'][0]['fibers'][0] = ['material' => ['code' => 'cotton'], 'percentage' => 60, 'qualifier' => ['code' => 'organic', 'name' => ['en' => 'Bio']]];
        $this->assertSame('unchanged', $this->json($put('product', 'KX-1', $body))['result']);
        // An unknown material and qualifier keep the name they were given.
        $odd = $body;
        $odd['materials'][0]['fibers'][1] = ['material' => ['code' => 'yak_wool', 'name' => ['en' => 'Yak wool']], 'percentage' => 30, 'qualifier' => ['code' => 'in_conversion']];
        $this->json($put('product', 'KX-1', $odd));
        $f = $this->json($this->http('GET', '/obx/v1/ingest/products/KX-1', ['bearer' => $token]))['data']['materials'][0]['fibers'][1];
        $this->assertSame(['material' => ['code' => 'yak_wool', 'name' => ['en' => 'Yak wool']], 'percentage' => 30, 'qualifier' => ['code' => 'in_conversion', 'name' => null]], $f);
        $this->json($put('product', 'KX-1', $body));

        // 0.2 shapes and invalid terms are refused.
        foreach ([
            '/materials/0/part' => [['part' => 'main', 'fibers' => [['material' => ['code' => 'cotton'], 'percentage' => 100]]]],
            '/materials/0/fibers/0/material' => [['part' => ['code' => 'main'], 'fibers' => [['fiber' => 'cotton', 'percentage' => 100]]]],
            '/materials/0/fibers/0/qualifier/code' => [['part' => ['code' => 'main'], 'fibers' => [['material' => ['code' => 'cotton'], 'percentage' => 100, 'qualifier' => ['code' => 'Organic']]]]],
            '/materials/0/fibers/0/material/name/en' => [['part' => ['code' => 'main'], 'fibers' => [['material' => ['code' => 'cotton', 'name' => ['en' => '']], 'percentage' => 100]]]],
        ] as $pointer => $bad) {
            $p = $this->assertProblem($put('product', 'KX-1', ['materials' => $bad] + $body), 422, 'validation_failed');
            $this->assertSame($pointer, $p['errors'][0]['pointer']);
        }
        foreach (['/care/0/category' => ['code' => 'x_care', 'category' => 'folding'], '/care/0/symbol' => ['code' => 'x_care', 'symbol' => 'C40']] as $pointer => $bad) {
            $p = $this->assertProblem($put('product', 'KX-1', ['care' => [$bad]] + $body), 422, 'validation_failed');
            $this->assertSame($pointer, $p['errors'][0]['pointer']);
        }

        foreach ([['wheels' => 3], ['shell' => 'glass'], ['detachable_parts' => ['hood', 'hood']], ['expandable' => 'yes']] as $bad) {
            $p = $this->assertProblem($put('product', 'KX-1', ['features' => $bad] + $body), 422, 'validation_failed');
            $this->assertSame('/features/' . array_key_first($bad), $p['errors'][0]['pointer']);
        }
        $this->assertProblem($put('product', 'KX-1', ['footwear_materials' => ['upper' => 'plastic']] + $body), 422, 'validation_failed');
        $this->assertProblem($put('product', 'KX-1', ['certifications' => [['scheme' => 'GOTS', 'scope' => 'product']]] + $body), 422, 'validation_failed');
        $this->assertProblem($put('product', 'KX-1', ['contains_animal_origin' => 'no'] + $body), 422, 'validation_failed');
        $ok = $this->json($put('product', 'KX-1', ['features' => [], 'contains_animal_origin' => false, 'materials' => [['part' => ['code' => 'main'], 'fibers' => [['material' => ['code' => 'cotton'], 'percentage' => 100]]]]] + $body));
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
        // One qualifier per fibre (import-format.md §1.4).
        $job = $this->importCsv($token, "brand,style_number,name:en,materials\nKestrel,KX-1,Test scarf,100% cotton (organic) (recycled)\n");
        $this->assertSame(1, $job['import_run']['counts']['errors']);
        $err = array_values(array_filter($this->rowErrors($job), static fn ($e) => $e['severity'] === 'error'))[0];
        $this->assertSame(['materials', 'validation_failed'], [$err['column'], $err['obx_error']]);
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
        $this->assertSame([['part' => ['code' => 'main', 'name' => null], 'fibers' => [['material' => ['code' => 'cotton', 'name' => $vocab('materials', 'cotton')], 'percentage' => 100, 'qualifier' => null]]]], $p['materials']);
        $this->assertSame('steam_only', $p['care'][1]['code']);
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

    public function testUpgradeOfA01AndA02Database(): void
    {
        $this->kestrelNode();
        [, $id, $secret] = $this->retailerClient();
        $client = $this->accessToken($id, $secret);
        [, $cursor] = $this->drainSnapshot($client);
        $db = $this->app->db;
        $productOf = fn (string $ext) => $this->json($this->http('GET', '/obx/v1/products/' . $db->value('SELECT id FROM obx_products WHERE external_id = ?', [$ext]), ['bearer' => $client]));
        $before = ['KT-2001' => $productOf('KT-2001'), 'KO-1006' => $productOf('KO-1006')];
        // Write KT-2001 back as 0.1 stored it (without the 0.2 fields that are not stated, the
        // 0.1 fibre key), KO-1006 as 0.2 stored it (part and fibre as strings, the flags, care
        // without names), and KO-1006's sizes without the 0.2 fields.
        $unsetNull = static function (array &$a, array $keys): void {
            foreach ($keys as $k) {
                if (array_key_exists($k, $a) && $a[$k] === null) {
                    unset($a[$k]);
                }
            }
        };
        $legacy = static function (array $materials, bool $v01): array {
            $out = [];
            foreach ($materials as $part) {
                $fibers = [];
                foreach ($part['fibers'] as $f) {
                    $q = $f['qualifier']['code'] ?? null;
                    $fibers[] = $v01 && $q !== null
                        ? ['fiber' => $q . '_' . $f['material']['code'], 'percentage' => $f['percentage']]
                        : ['fiber' => $f['material']['code'], 'percentage' => $f['percentage']] + ($q !== null ? [$q => true] : []);
                }
                $out[] = ['part' => $part['part']['code'], 'fibers' => $fibers];
            }
            return $out;
        };
        foreach ($db->all("SELECT * FROM obx_products WHERE external_id IN ('KT-2001', 'KO-1006')") as $p) {
            $v01 = $p['external_id'] === 'KT-2001';
            $in = Json::decode((string) $p['input']);
            $in['materials'] = $legacy($in['materials'], $v01);
            $in['care'] = array_map(static fn ($c) => ['code' => $c['code'], 'text' => $c['text']], (array) $in['care']);
            $rep = Json::decode((string) $p['rep']);
            $rep['materials'] = $in['materials'];
            $rep['care'] = $in['care'];
            if ($v01) {
                $unsetNull($in, ['item_subtype', 'contains_animal_origin', 'footwear_materials', 'certifications', 'features']);
                $unsetNull($rep, ['item_subtype', 'contains_animal_origin', 'footwear_materials', 'certifications', 'features']);
            }
            $db->update('obx_products', ['input' => Json::encode($in), 'rep' => Json::encode($rep), 'rep_hash' => hash('sha256', Json::canonical($rep)), 'etag' => '"old"'], ['id' => $p['id']]);
        }
        $this->assertStringContainsString('"fiber":"recycled_polyester"', (string) $db->value("SELECT input FROM obx_products WHERE external_id = 'KT-2001'"));
        foreach ($db->all("SELECT v.* FROM obx_variants v JOIN obx_products p ON p.id = v.product_id WHERE p.external_id = 'KO-1006'") as $v) {
            $in = Json::decode((string) $v['input']);
            $unsetNull($in, ['size_length', 'measurements']);
            $rep = Json::decode((string) $v['rep']);
            $unsetNull($rep, ['size_length', 'measurements']);
            $db->update('obx_variants', ['input' => Json::encode($in), 'rep' => Json::encode($rep), 'rep_hash' => hash('sha256', Json::canonical($rep))], ['id' => $v['id']]);
        }
        foreach (['3', '4'] as $from) {
            $db->run("UPDATE obx_settings SET v = ? WHERE k = 'schema_version'", [$from]);
            Schema::migrate($db);
            $this->assertSame(Schema::latestVersion(), Schema::currentVersionOf($db));
        }
        Schema::migrate($db); // nothing left to do

        // The products whose materials and care changed shape have a new entry, in the 0.3 form;
        // their sizes, whose content did not change, have none.
        $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $client, 'query' => ['cursor' => $cursor]]));
        $got = array_map(static fn ($e) => $e['type'] . ' ' . $e['op'] . ' ' . $e['external_id'], $page['changes']);
        sort($got);
        $this->assertSame(['product upsert KO-1006', 'product upsert KT-2001'], $got);
        foreach ($page['changes'] as $e) {
            $after = $e['data'];
            $want = $before[$after['external_id']];
            $this->assertSame(array_keys($want), array_keys($after + ['variants' => [], 'assets' => []]));
            $this->assertEquals($want['materials'], $after['materials'], $after['external_id']);
            $this->assertEquals($want['care'], $after['care'], $after['external_id']);
        }
        $this->assertSame(['code' => 'recycled', 'name' => ArticleData::vocabulary('qualifiers')['recycled']['name']], $before['KT-2001']['materials'][0]['fibers'][0]['qualifier']);
        // The same file again is all unchanged: the stored model is the 0.3 one.
        $token = $this->ingestToken();
        $job = $this->importCsv($token, (string) file_get_contents(self::fixtures('kestrel/products.csv')));
        $this->assertSame(0, $job['import_run']['counts']['updated'], Json::encode($job['import_run']['counts']));
    }
}
