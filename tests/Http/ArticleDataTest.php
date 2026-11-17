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
                    if ($k === 'detail_prefix') {
                        $this->assertStringStartsWith($v, (string) $errors[$i]['detail'], $c['file'] . ' error ' . $i);
                        continue;
                    }
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
        $expected = Json::decode((string) file_get_contents(self::fixtures('imports/label-warnings.expected.json')));
        $codes = $expected['codes'];
        $got = array_map(
            static fn ($e) => ['file' => $e['file'], 'row' => $e['row'], 'column' => $e['column'], 'severity' => $e['severity'], 'obx_error' => $e['obx_error']]
                + ($e['obx_error'] === 'material_unknown' ? ['value' => $e['value']] : []),
            array_values(array_filter($all, static fn ($e) => in_array($e['obx_error'], $codes, true))),
        );
        $this->assertSame($expected['warnings'], $got);
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
                        $this->assertSame(['material', 'percentage', 'qualifiers'], array_keys($f));
                        $this->assertTrue(array_is_list($f['qualifiers']));
                        $codes = array_column($f['qualifiers'], 'code');
                        $this->assertSame(array_values(array_unique($codes)), $codes, 'a code appears once');
                        $this->assertDoesNotMatchRegularExpression('/^(recycled|organic)_/', $f['material']['code']);
                        $this->assertNameOf('materials', $f['material'], $d['external_id']);
                        foreach ($f['qualifiers'] as $q) {
                            $this->assertNameOf('qualifiers', $q, $d['external_id']);
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
                ['material' => ['code' => 'wool', 'name' => ['en' => 'Sheep']], 'percentage' => 30, 'qualifiers' => []],
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
            ['material' => ['code' => 'cotton', 'name' => $vocab('materials', 'cotton')], 'percentage' => 60, 'qualifiers' => [['code' => 'organic', 'name' => $vocab('qualifiers', 'organic')]]],
            ['material' => ['code' => 'wool', 'name' => $vocab('materials', 'wool')], 'percentage' => 30, 'qualifiers' => []],
        ]]], $rec['data']['materials']);
        $this->assertSame(['code' => 'wash_30', 'category' => 'washing', 'symbol' => '1_D_30'], array_slice($rec['data']['care'][0], 0, 3));
        $this->assertSame(['en' => 'Inside out'], $rec['data']['care'][0]['text']);
        $this->assertSame(['code' => 'steam_only', 'category' => 'ironing', 'symbol' => null, 'name' => ['en' => 'Steam only'], 'text' => null], $rec['data']['care'][1]);
        $this->assertSame(['fit' => 'regular', 'fringe_cm' => 5], $rec['data']['features']);
        $this->assertNull($rec['data']['certifications']);
        // The data of a GET written back is unchanged; so is the qualifier form of the 0.1 key.
        $this->assertSame('unchanged', $this->json($put('product', 'KX-1', $rec['data']))['result']);
        $body['materials'][0]['fibers'][0] = ['material' => ['code' => 'cotton'], 'percentage' => 60, 'qualifiers' => [['code' => 'organic', 'name' => ['en' => 'Bio']], ['code' => 'organic']]];
        $this->assertSame('unchanged', $this->json($put('product', 'KX-1', $body))['result']);
        // Several qualifiers keep their order; the 0.1 key's comes first and counts once.
        $several = $body;
        $several['materials'][0]['fibers'][0] = ['material' => ['code' => 'recycled_polyester'], 'percentage' => 60, 'qualifiers' => [['code' => 'post_consumer'], ['code' => 'recycled']]];
        $this->json($put('product', 'KX-1', $several));
        $f = $this->json($this->http('GET', '/obx/v1/ingest/products/KX-1', ['bearer' => $token]))['data']['materials'][0]['fibers'][0];
        $this->assertSame(['material' => ['code' => 'polyester', 'name' => $vocab('materials', 'polyester')], 'percentage' => 60, 'qualifiers' => [
            ['code' => 'recycled', 'name' => $vocab('qualifiers', 'recycled')], ['code' => 'post_consumer', 'name' => $vocab('qualifiers', 'post_consumer')]]], $f);
        // An unknown material and qualifier keep the name they were given.
        $odd = $body;
        $odd['materials'][0]['fibers'][1] = ['material' => ['code' => 'yak_wool', 'name' => ['en' => 'Yak wool']], 'percentage' => 30, 'qualifiers' => [['code' => 'wild_harvested', 'name' => ['en' => 'Wild harvested']], ['code' => 'in_conversion', 'name' => ['en' => 'Not kept']]]];
        $this->json($put('product', 'KX-1', $odd));
        $f = $this->json($this->http('GET', '/obx/v1/ingest/products/KX-1', ['bearer' => $token]))['data']['materials'][0]['fibers'][1];
        $this->assertSame(['material' => ['code' => 'yak_wool', 'name' => ['en' => 'Yak wool']], 'percentage' => 30, 'qualifiers' => [
            ['code' => 'wild_harvested', 'name' => ['en' => 'Wild harvested']], ['code' => 'in_conversion', 'name' => $vocab('qualifiers', 'in_conversion')]]], $f);
        $this->json($put('product', 'KX-1', $body));

        // 0.2 shapes and invalid terms are refused.
        foreach ([
            '/materials/0/part' => [['part' => 'main', 'fibers' => [['material' => ['code' => 'cotton'], 'percentage' => 100]]]],
            '/materials/0/fibers/0/material' => [['part' => ['code' => 'main'], 'fibers' => [['fiber' => 'cotton', 'percentage' => 100]]]],
            '/materials/0/fibers/0/qualifiers/1/code' => [['part' => ['code' => 'main'], 'fibers' => [['material' => ['code' => 'cotton'], 'percentage' => 100, 'qualifiers' => [['code' => 'organic'], ['code' => 'Regenerative']]]]]],
            '/materials/0/fibers/0/qualifiers' => [['part' => ['code' => 'main'], 'fibers' => [['material' => ['code' => 'cotton'], 'percentage' => 100, 'qualifiers' => ['code' => 'organic']]]]],
            '/materials/0/fibers/0/material/name/en' => [['part' => ['code' => 'main'], 'fibers' => [['material' => ['code' => 'cotton', 'name' => ['en' => '']], 'percentage' => 100]]]],
        ] as $pointer => $bad) {
            $p = $this->assertProblem($put('product', 'KX-1', ['materials' => $bad] + $body), 422, 'validation_failed');
            $this->assertSame($pointer, $p['errors'][0]['pointer']);
        }
        // The draft single qualifier (ingest.md §3.1, conformance I-MAT-1): a list of one, null =
        // none, "qualifiers" wins; one warning qualifier_deprecated per write; never stored.
        $draft = ['materials' => [['part' => ['code' => 'main'], 'fibers' => [
            ['material' => ['code' => 'polyester'], 'percentage' => 50, 'qualifier' => ['code' => 'recycled']],
            ['material' => ['code' => 'cotton'], 'percentage' => 45, 'qualifier' => ['code' => 'organic'], 'qualifiers' => [['code' => 'regenerative']]],
            ['material' => ['code' => 'elastane'], 'percentage' => 5, 'qualifier' => null],
        ]]]] + $body;
        $res = $this->json($put('product', 'KX-1', $draft));
        $this->assertSame([['code' => 'qualifier_deprecated', 'pointer' => '/materials/0/fibers/0/qualifier']],
            array_map(static fn ($w) => ['code' => $w['code'], 'pointer' => $w['pointer']], array_values(array_filter($res['warning_details'], static fn ($w) => $w['code'] === 'qualifier_deprecated'))));
        $this->assertCount(1, array_filter($res['warnings'], static fn ($w) => str_starts_with($w, 'qualifier_deprecated')));
        $stored = $this->json($this->http('GET', '/obx/v1/ingest/products/KX-1', ['bearer' => $token]))['data']['materials'][0]['fibers'];
        $this->assertSame([['recycled'], ['regenerative'], []], array_map(static fn ($f) => array_column($f['qualifiers'], 'code'), $stored));
        $this->assertStringNotContainsString('"qualifier"', Json::encode($stored));
        $plain = $draft;
        $plain['materials'][0]['fibers'] = array_map(static function ($f) {
            unset($f['qualifier']);
            return $f;
        }, $plain['materials'][0]['fibers']);
        $plain['materials'][0]['fibers'][0]['qualifiers'] = [['code' => 'recycled']];
        $this->assertSame('unchanged', $this->json($put('product', 'KX-1', $plain))['result']);
        $this->assertSame('unchanged', $this->json($put('product', 'KX-1', $draft))['result'], 'the draft form that gives the stored list is unchanged');
        $this->json($put('product', 'KX-1', $body));

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
        // One code per bracket (import-format.md §1.4): "(a, b)" skips the row; "(a) (b)" is two.
        $job = $this->importCsv($token, "brand,style_number,name:en,materials\nKestrel,KX-1,Test scarf,\"100% cotton (organic, recycled)\"\n");
        $this->assertSame(1, $job['import_run']['counts']['errors']);
        $err = array_values(array_filter($this->rowErrors($job), static fn ($e) => $e['severity'] === 'error'))[0];
        $this->assertSame(['materials', 'validation_failed'], [$err['column'], $err['obx_error']]);
        $job = $this->importCsv($token, "brand,style_number,name:en,materials\nKestrel,KX-1,Test scarf,100% cotton (organic) (in conversion) (organic)\n");
        $this->assertSame([0, 1], [$job['import_run']['counts']['errors'], $job['import_run']['counts']['updated']], Json::encode($job['import_run']));
        $this->assertSame(['organic', 'in_conversion'], array_column(Json::decode((string) $this->app->db->value("SELECT input FROM obx_products WHERE external_id = 'KX-1'"))['materials'][0]['fibers'][0]['qualifiers'], 'code'));
        $this->json($put('product', 'KX-1', ['features' => [], 'contains_animal_origin' => false, 'materials' => [['part' => ['code' => 'main'], 'fibers' => [['material' => ['code' => 'cotton'], 'percentage' => 100]]]]] + $body));
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
        $this->assertSame([['part' => ['code' => 'main', 'name' => null], 'fibers' => [['material' => ['code' => 'cotton', 'name' => $vocab('materials', 'cotton')], 'percentage' => 100, 'qualifiers' => []]]]], $p['materials']);
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
        $boot = array_values(array_filter($rows, static fn ($r) => $r['gtin'] === '2000101001027'))[0];
        $this->assertSame('KO-1010-BRN-42', $boot['variant_id'], 'the export fills variant_id with the external id');
        $this->assertSame('upper: leather; lining_and_sock: leather; outer_sole: other', $boot['footwear_materials']);
        $this->assertSame('lwg:LWG-200-1010 (material)', $boot['certifications']);
        $this->assertSame('14.5', $boot['shaft_height_cm']);
        $this->assertSame('insole', $boot['feature:detachable_parts']);
        $bag = array_values(array_filter($rows, static fn ($r) => $r['style_number'] === 'KO-1009'))[0];
        $this->assertSame('85-140', $bag['strap_length_cm']);
        $this->assertSame('yes', $bag['feature:laptop_compartment']);
        $this->assertSame('outer: 100% polyester (recycled) (post_consumer); lining: 100% polyamide', $bag['materials']);
        $tee = array_values(array_filter($rows, static fn ($r) => $r['style_number'] === 'KO-1003'))[0];
        $this->assertSame('main: 100% cotton (organic) (regenerative)', $tee['materials']);

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
                    $q = $f['qualifiers'][0]['code'] ?? null;
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
        $this->assertSame([['code' => 'recycled', 'name' => ArticleData::vocabulary('qualifiers')['recycled']['name']]], $before['KT-2001']['materials'][0]['fibers'][0]['qualifiers']);
        // The same file again is all unchanged: the stored model is the 0.3 one.
        $token = $this->ingestToken();
        $job = $this->importCsv($token, (string) file_get_contents(self::fixtures('kestrel/products.csv')));
        $this->assertSame(0, $job['import_run']['counts']['updated'], Json::encode($job['import_run']['counts']));
    }

    public function testUpgradeOfADraft03DatabaseWithOneQualifierPerFibre(): void
    {
        $this->kestrelNode();
        [, $id, $secret] = $this->retailerClient();
        $client = $this->accessToken($id, $secret);
        [, $cursor] = $this->drainSnapshot($client);
        $db = $this->app->db;
        $row = $db->one("SELECT * FROM obx_products WHERE external_id = 'KO-1008'");
        $this->assertSame('|recycled|', $row['qualifiers']);
        $before = $this->json($this->http('GET', '/obx/v1/products/' . $row['id'], ['bearer' => $client]));
        // As the 0.3 draft stored it: "qualifier" (a term or null) instead of "qualifiers", and
        // a database from before schema 6 has no qualifiers column yet (here: emptied).
        $draft = static function (array $materials): array {
            foreach ($materials as &$part) {
                foreach ($part['fibers'] as &$f) {
                    $f = ['material' => $f['material'], 'percentage' => $f['percentage'], 'qualifier' => $f['qualifiers'][0] ?? null];
                }
            }
            return $materials;
        };
        $in = Json::decode((string) $row['input']);
        $in['materials'] = $draft($in['materials']);
        $rep = Json::decode((string) $row['rep']);
        $rep['materials'] = $draft($rep['materials']);
        $db->update('obx_products', ['input' => Json::encode($in), 'rep' => Json::encode($rep), 'rep_hash' => hash('sha256', Json::canonical($rep)), 'etag' => '"old"', 'qualifiers' => null], ['id' => $row['id']]);
        $db->run("UPDATE obx_settings SET v = '5' WHERE k = 'schema_version'");
        Schema::migrate($db);
        $this->assertSame(Schema::latestVersion(), Schema::currentVersionOf($db));
        Schema::migrate($db);

        $this->assertSame('|recycled|', $db->value("SELECT qualifiers FROM obx_products WHERE external_id = 'KO-1008'"));
        $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $client, 'query' => ['cursor' => $cursor]]));
        $this->assertSame(['product upsert KO-1008'], array_map(static fn ($e) => $e['type'] . ' ' . $e['op'] . ' ' . $e['external_id'], $page['changes']));
        $this->assertEquals($before['materials'], $page['changes'][0]['data']['materials']);
        $this->assertSame('unchanged', $this->json($this->http('PUT', '/obx/v1/ingest/products/KO-1008', ['bearer' => $this->ingestToken(), 'json' => Json::decode((string) $db->value("SELECT input FROM obx_products WHERE external_id = 'KO-1008'"))]))['result']);
    }

    public function testVariantIdentityOfTheSpecFixtures(): void
    {
        ['ingest' => $token] = $this->kestrelNode();
        [, $id, $secret] = $this->retailerClient();
        $client = $this->accessToken($id, $secret);
        [, $cursor] = $this->drainSnapshot($client);
        $ids = null;
        foreach (Json::decode((string) file_get_contents(self::fixtures('imports/variant-identity.expected.json')))['cases'] as $c) {
            $job = $this->importCsv($token, (string) file_get_contents(self::fixtures('imports/' . $c['file'])));
            foreach ($c['counts'] as $k => $want) {
                $this->assertSame($want, $job['import_run']['counts'][$k], $c['file'] . ' counts.' . $k . ' ' . Json::encode($job['import_run']['counts']));
            }
            $errors = array_values(array_filter($this->rowErrors($job), static fn ($e) => $e['severity'] === 'error'));
            $this->assertCount(count($c['errors']), $errors, $c['file'] . ': ' . Json::encode($errors));
            foreach ($c['errors'] as $n => $want) {
                foreach ($want as $k => $v) {
                    $this->assertSame($v, $errors[$n][$k], $c['file'] . ' error ' . $n . ' ' . $k);
                }
            }
            $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $client, 'query' => ['cursor' => $cursor, 'limit' => '500']]));
            $cursor = $page['next_cursor'];
            $this->assertSame(
                array_map(static fn ($w) => $w['type'] . ' ' . $w['op'] . ' ' . $w['external_id'], $c['changes']),
                array_map(static fn ($e) => $e['type'] . ' ' . $e['op'] . ' ' . $e['external_id'], $page['changes']),
                $c['file'] . ' change entries',
            );
            foreach ($c['products'] as $ext => $fields) {
                $pid = (string) $this->app->db->value('SELECT id FROM obx_products WHERE external_id = ?', [$ext]);
                $p = $this->json($this->http('GET', '/obx/v1/products/' . $pid, ['bearer' => $client]));
                $got = array_map(static fn ($v) => array_intersect_key($v, array_flip(['external_id', 'gtin', 'gtin_original', 'size', 'size_length'])), $p['variants']);
                $this->assertEquals($fields['variants'], $got, $c['file'] . ' ' . $ext . '.variants');
                // The protocol id of each variant stays the same after every file.
                $now = array_column($p['variants'], 'id', 'external_id');
                if ($ids !== null) {
                    $this->assertSame($ids, $now, $c['file'] . ': variant ids');
                }
                $ids = $now;
            }
        }
        $this->assertSame('KL-8101-IND-32_34', \Obx\Node\Import\ImportEngine::defaultVariantId('KL-8101', 'IND', '32/34', null));
        $this->assertSame('KO-1005-COG-ONE SIZE', \Obx\Node\Import\ImportEngine::defaultVariantId('KO-1005', 'COG', 'ONE SIZE', ''));
        $this->assertSame('KX-1-RED-34', \Obx\Node\Import\ImportEngine::defaultVariantId('KX-1', 'RED', null, '34'));
        // Ingest: gtin_conflict names the variant that has the GTIN and its product.
        $p = $this->assertProblem($this->http('PUT', '/obx/v1/ingest/variants/KX-OTHER', ['bearer' => $token, 'json' => [
            'product_external_id' => 'KL-8101', 'gtin' => '2000100101025', 'color' => ['code' => 'IND'],
        ]]), 409, 'gtin_conflict');
        $this->assertSame('/gtin', $p['errors'][0]['pointer']);
        $this->assertStringContainsString('KO-1001-NVY-M', $p['errors'][0]['detail']);
        $this->assertStringContainsString('KO-1001', $p['errors'][0]['detail']);
    }

    public function testExtensionMembersAreKeptAndUnknownMembersWarn(): void
    {
        ['ingest' => $token] = $this->kestrelNode();
        [, $id, $secret] = $this->retailerClient();
        $client = $this->accessToken($id, $secret);
        [, $cursor] = $this->drainSnapshot($client);
        $put = fn (string $kind, string $ext, array $body) => $this->http('PUT', '/obx/v1/ingest/' . $kind . 's/' . rawurlencode($ext), ['bearer' => $token, 'json' => $body]);
        $body = Json::decode((string) file_get_contents(self::fixtures('examples/ProductInput.extension.json')));
        $body['brand_external_id'] = 'kestrel';
        $body['colour_typo'] = 'blue';
        $body['materials'][0]['fibers'][0]['grade'] = 'A';
        $body['x-kestrel-big'] = str_repeat('x', 9000);
        $res = $this->json($put('product', 'KO-1007', $body), 201);
        $this->assertContains('Unknown member "/colour_typo" ignored.', $res['warnings']);
        $this->assertContains('Unknown member "/materials/0/fibers/0/grade" ignored.', $res['warnings']);
        $rec = $this->json($this->http('GET', '/obx/v1/ingest/products/KO-1007', ['bearer' => $token]))['data'];
        $this->assertSame(180, $rec['x-kestrel-fabric-weight-gsm']);
        $this->assertSame(['en' => 'Wrinkles are part of the look'], $rec['x-kestrel-care-note']);
        $this->assertSame('Mill 7', $rec['materials'][0]['fibers'][0]['material']['x-kestrel-mill']);
        $this->assertArrayNotHasKey('colour_typo', $rec);
        $this->assertSame('unchanged', $this->json($put('product', 'KO-1007', $rec))['result']);
        $pid = (string) $this->app->db->value("SELECT id FROM obx_products WHERE external_id = 'KO-1007'");
        $p = $this->json($this->http('GET', '/obx/v1/products/' . $pid, ['bearer' => $client]));
        $this->assertSame(180, $p['x-kestrel-fabric-weight-gsm']);
        $this->assertSame('Mill 7', $p['materials'][0]['fibers'][0]['material']['x-kestrel-mill']);
        $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $client, 'query' => ['cursor' => $cursor]]));
        $this->assertSame(180, $page['changes'][0]['data']['x-kestrel-fabric-weight-gsm']);
        // A change of an extension is an update; PATCH null removes it.
        $rec['x-kestrel-fabric-weight-gsm'] = 190;
        $this->assertSame('updated', $this->json($put('product', 'KO-1007', $rec))['result']);
        $res = $this->json($this->http('PATCH', '/obx/v1/ingest/products/KO-1007', ['bearer' => $token, 'json' => ['x-kestrel-care-note' => null], 'headers' => ['Content-Type' => 'application/merge-patch+json']]));
        $this->assertSame('updated', $res['result']);
        $rec = $this->json($this->http('GET', '/obx/v1/ingest/products/KO-1007', ['bearer' => $token]))['data'];
        $this->assertArrayNotHasKey('x-kestrel-care-note', $rec);
        // An import leaves extension members alone, also those of a fibre it states again.
        $job = $this->importCsv($token, "brand,style_number,name:en,materials\nKestrel,KO-1007,Linen Shirt,main: 100% linen\n");
        $this->assertSame(0, $job['import_run']['counts']['errors']);
        $rec = $this->json($this->http('GET', '/obx/v1/ingest/products/KO-1007', ['bearer' => $token]))['data'];
        $this->assertSame(190, $rec['x-kestrel-fabric-weight-gsm']);
        $this->assertSame('Mill 7', $rec['materials'][0]['fibers'][0]['material']['x-kestrel-mill']);
        // Variants, colours and assets keep theirs too; too many is refused.
        $v = ['product_external_id' => 'KO-1007', 'color' => ['code' => 'SND', 'x-kestrel-dye' => 'natural'], 'size' => 'M', 'x-kestrel-plu' => 4711];
        $this->json($put('variant', 'KO-1007-SND-M', $v), 201);
        $pv = $this->json($this->http('GET', '/obx/v1/products/' . $pid, ['bearer' => $client]))['variants'][0];
        $this->assertSame([4711, 'natural'], [$pv['x-kestrel-plu'], $pv['color']['x-kestrel-dye']]);
        $this->assertProblem($put('variant', 'KO-1007-SND-M', ['x-kestrel-huge' => str_repeat('y', 70000)] + $v), 422, 'validation_failed');
    }

    public function testPercentageMayBeNullAndUnknownMaterialsWarn(): void
    {
        ['ingest' => $token] = $this->kestrelNode();
        $body = ['brand_external_id' => 'kestrel', 'style_number' => 'KX-2', 'name' => ['en' => 'Bag'], 'item_type' => 'bag', 'contains_animal_origin' => true,
            'materials' => [
                ['part' => ['code' => 'outer'], 'fibers' => [['material' => ['code' => 'leather']]]],
                ['part' => ['code' => 'lining'], 'fibers' => [['material' => ['code' => 'cotton'], 'percentage' => 60], ['material' => ['code' => 'katoen'], 'percentage' => null]]],
            ]];
        $res = $this->json($this->http('PUT', '/obx/v1/ingest/products/KX-2', ['bearer' => $token, 'json' => $body]), 201);
        $this->assertSame([['code' => 'material_unknown', 'pointer' => '/materials/1/fibers/1/material/code']],
            array_map(static fn ($w) => ['code' => $w['code'], 'pointer' => $w['pointer']], $res['warning_details']));
        $rec = $this->json($this->http('GET', '/obx/v1/ingest/products/KX-2', ['bearer' => $token]))['data'];
        $this->assertSame([null, 60, null], [$rec['materials'][0]['fibers'][0]['percentage'], $rec['materials'][1]['fibers'][0]['percentage'], $rec['materials'][1]['fibers'][1]['percentage']]);
        $this->assertSame('unchanged', $this->json($this->http('PUT', '/obx/v1/ingest/products/KX-2', ['bearer' => $token, 'json' => $rec]))['result']);
        $this->assertSame('outer: leather; lining: 60% cotton, katoen', ArticleData::formatMaterials($rec['materials']));
        $over = ArticleData::labelWarnings(['item_type' => 'shirt', 'contains_animal_origin' => false, 'materials' => [['part' => ['code' => 'main'], 'fibers' => [
            ['material' => ['code' => 'cotton'], 'percentage' => 70], ['material' => ['code' => 'polyester'], 'percentage' => 40], ['material' => ['code' => 'elastane'], 'percentage' => null]]]]]);
        $this->assertSame(['fibre_percentages_not_100'], array_column($over, 'code'));
        $this->assertProblem($this->http('PUT', '/obx/v1/ingest/products/KX-2', ['bearer' => $token, 'json' => ['materials' => [['part' => ['code' => 'main'], 'fibers' => [['material' => ['code' => 'cotton'], 'percentage' => 0]]]]] + $body]), 422, 'validation_failed');
    }

    public function testLanguageTagsWithScriptAndNumericRegion(): void
    {
        ['ingest' => $token] = $this->kestrelNode();
        $body = ['brand_external_id' => 'kestrel', 'style_number' => 'KX-3', 'name' => ['en' => 'Scarf', 'zh-Hant' => '圍巾', 'es-419' => 'Bufanda', 'sr-Latn-RS' => 'Šal']];
        $this->json($this->http('PUT', '/obx/v1/ingest/products/KX-3', ['bearer' => $token, 'json' => $body]), 201);
        $this->assertProblem($this->http('PUT', '/obx/v1/ingest/products/KX-3', ['bearer' => $token, 'json' => ['name' => ['zh-hant' => 'x']] + $body]), 422, 'validation_failed');
        $job = $this->importCsv($token, "brand,style_number,name:en,name:ZH_hant,name:es-419,name:x-klingon\nKestrel,KX-3,Scarf,圍巾 2,Bufanda 2,nope\n");
        $this->assertSame(0, $job['import_run']['counts']['errors']);
        $name = Json::decode((string) $this->app->db->value("SELECT input FROM obx_products WHERE external_id = 'KX-3'"))['name'];
        $this->assertSame(['en' => 'Scarf', 'es-419' => 'Bufanda 2', 'sr-Latn-RS' => 'Šal', 'zh-Hant' => '圍巾 2'], $name);
        $this->assertSame('sr-Latn-RS', \Obx\Node\Catalog\Validator::canonicalLanguage('SR_latn_rs'));
        $this->assertNull(\Obx\Node\Catalog\Validator::canonicalLanguage('english'));
    }
}
