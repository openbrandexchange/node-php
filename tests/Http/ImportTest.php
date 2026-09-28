<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Http;

use Obx\Node\Support\Json;
use Obx\Node\Tests\Support\NodeTestCase;

/**
 * Canonical imports (docs/import-format.md): package, separate files, image manifest, mirror,
 * dry run, zip-slip and row errors.
 */
final class ImportTest extends NodeTestCase
{
    private function counts(): array
    {
        $db = $this->app->db;
        return [
            'brands' => (int) $db->value('SELECT COUNT(*) FROM obx_brands WHERE visible = 1'),
            'products' => (int) $db->value('SELECT COUNT(*) FROM obx_products WHERE visible = 1'),
            'variants' => (int) $db->value('SELECT COUNT(*) FROM obx_variants WHERE visible = 1'),
            'assets' => (int) $db->value('SELECT COUNT(*) FROM obx_assets WHERE visible = 1'),
        ];
    }

    private function expectedCounts(): array
    {
        $c = Json::decode((string) file_get_contents(self::fixtures('expected/counts.json')));
        unset($c['note']);
        return $c;
    }

    /** @param array<string, mixed> $files @param array<string, mixed> $options */
    private function import(string $token, array $files, array $options = [], array $post = []): array
    {
        if ($options !== []) {
            $post['options'] = Json::encode($options);
        }
        $job = $this->json($this->http('POST', '/obx/v1/ingest/imports', ['bearer' => $token, 'files' => $files, 'post' => $post]), 202);
        return $this->json($this->http('GET', '/obx/v1/ingest/jobs/' . $job['id'], ['bearer' => $token]));
    }

    public function testPackageImportThenRepeatIsUnchanged(): void
    {
        ['ingest' => $token] = $this->kestrelNode();
        $this->assertSame($this->expectedCounts(), $this->counts());
        [, $id, $secret] = $this->retailerClient();
        $client = $this->accessToken($id, $secret);
        [, $cursor] = $this->drainSnapshot($client);
        $again = $this->importKestrel($token);
        $this->assertSame('succeeded', $again['status']);
        $counts = $again['import_run']['counts'];
        $this->assertSame(0, $counts['created'], Json::encode($counts));
        $this->assertSame(0, $counts['updated'], Json::encode($counts));
        $this->assertSame(8 + 40 + 27, $counts['unchanged']);
        $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $client, 'query' => ['cursor' => $cursor]]));
        $this->assertSame([], $page['changes']);
    }

    public function testSeparateFilesAndManifestGiveTheSameCatalog(): void
    {
        $this->freshNode();
        $token = $this->ingestToken();
        $images = [];
        foreach (glob(self::fixtures('kestrel/images/*.png')) as $img) {
            $images[] = $this->upload($img, basename($img), 'image/png');
        }
        $job = $this->import($token, [
            'csv' => [$this->upload(self::fixtures('kestrel/products.csv'), 'products.csv', 'text/csv')],
            'assets_csv' => [$this->upload(self::fixtures('kestrel/assets.csv'), 'assets.csv', 'text/csv')],
            'images' => $images,
        ]);
        $this->assertSame('succeeded', $job['status'], Json::encode($job));
        $this->assertSame(0, $job['import_run']['counts']['errors'], Json::encode($job['import_run']['errors_preview']));
        $this->assertSame($this->expectedCounts(), $this->counts());

        // Same data via an images manifest on a second fresh node: blobs first, then the CSVs.
        $this->resetNode();
        $this->setupNode();
        $token = $this->ingestToken();
        $manifest = [];
        foreach (glob(self::fixtures('kestrel/images/*.png')) as $img) {
            $sha = hash_file('sha256', $img);
            $this->http('PUT', '/obx/v1/ingest/blobs/' . $sha, ['bearer' => $token, 'body' => file_get_contents($img)]);
            $manifest[] = ['file' => basename($img), 'sha256' => $sha];
        }
        $job = $this->import($token, [
            'csv' => [$this->upload(self::fixtures('kestrel/products.csv'), 'products.csv', 'text/csv')],
            'assets_csv' => [$this->upload(self::fixtures('kestrel/assets.csv'), 'assets.csv', 'text/csv')],
        ], [], ['images_manifest' => Json::encode($manifest)]);
        $this->assertSame('succeeded', $job['status']);
        $this->assertSame(0, $job['import_run']['counts']['errors'], Json::encode($job['import_run']['errors_preview']));
        $this->assertSame($this->expectedCounts(), $this->counts());
        $ai = Json::decode((string) $this->app->db->value("SELECT rep FROM obx_assets WHERE external_id = 'KO-1003_RST_3'"));
        $this->assertTrue($ai['provenance']['ai_generated']);
        $this->assertSame('generated', $ai['provenance']['source']);
    }

    public function testMirrorUnpublishesMissingProductsAndRefusesMassUnpublish(): void
    {
        ['ingest' => $token] = $this->kestrelNode();
        $lines = file(self::fixtures('kestrel/products.csv'), FILE_IGNORE_NEW_LINES);
        $withoutBeanie = implode("\n", array_filter($lines, static fn ($l) => !str_contains($l, 'KO-1006'))) . "\n";
        $csv = $this->dir . '/mirror.csv';
        file_put_contents($csv, $withoutBeanie);
        $job = $this->import($token, ['csv' => [$this->upload($csv, 'products.csv', 'text/csv')]], ['mode' => 'mirror']);
        $this->assertSame('succeeded', $job['status'], Json::encode($job));
        $this->assertSame(1, $job['import_run']['counts']['unpublished']);
        $this->assertSame('unpublished', $this->app->db->value("SELECT status FROM obx_products WHERE external_id = 'KO-1006'"));
        $this->assertSame('published', $this->app->db->value("SELECT status FROM obx_products WHERE external_id = 'KO-1001'"));

        $onlyOne = implode("\n", array_filter($lines, static fn ($l) => str_starts_with($l, 'brand,') || str_contains($l, 'KO-1001'))) . "\n";
        file_put_contents($csv, $onlyOne);
        $job = $this->import($token, ['csv' => [$this->upload($csv, 'products.csv', 'text/csv')]], ['mode' => 'mirror']);
        $this->assertSame('failed', $job['status']);
        $this->assertSame('mass_unpublish_refused', $job['import_run']['error']['obx_error']);
        $this->assertSame(0, (int) $this->app->db->value("SELECT COUNT(*) FROM obx_products WHERE status = 'unpublished' AND external_id <> 'KO-1006'"));

        // Mirror needs the delete scope.
        $writeOnly = $this->ingestToken(['ingest:read', 'ingest:write']);
        $res = $this->http('POST', '/obx/v1/ingest/imports', ['bearer' => $writeOnly, 'files' => ['csv' => [$this->upload($csv, 'products.csv')]], 'post' => ['options' => '{"mode":"mirror"}']]);
        $this->assertProblem($res, 403, 'insufficient_scope');
    }

    public function testZipSlipIsRefusedAndNothingIsWritten(): void
    {
        $this->freshNode();
        $token = $this->ingestToken();
        $zipPath = $this->kestrelZip(null, ['../evil.jpg' => 'x']);
        $job = $this->import($token, ['package' => [$this->upload($zipPath, 'bad.zip', 'application/zip')]]);
        $this->assertSame('failed', $job['status']);
        $this->assertSame('import_invalid', $job['import_run']['error']['obx_error']);
        $this->assertSame(0, (int) $this->app->db->value('SELECT COUNT(*) FROM obx_products'));
    }

    public function testRowErrorsCarryLocationAndJobStillSucceeds(): void
    {
        $this->freshNode();
        $token = $this->ingestToken();
        $csv = "style_number,name:en,gtin,color_code,size,colour_name:en,Unknown Column\n"
            . "ER-1,Good product,2000100101018,NVY,S,Navy,x\n"
            . "ER-1,Good product,2.00010010102E+12,NVY,M,Navy,x\n"
            . "ER-2,,2000100101025,NVY,S,Navy,x\n"
            . "ER-3,Third,2000100101026,RED,S,Red,x\n";
        $file = $this->dir . '/errors.csv';
        file_put_contents($file, $csv);
        $job = $this->import($token, ['csv' => [$this->upload($file, 'products.csv')], 'images' => [$this->upload(self::fixtures('kestrel/images/KO-1001_NVY_1_front.png'), 'ZZ-9_NVY_1.png')]]);
        $this->assertSame('succeeded', $job['status']);
        $errors = $this->json($this->http('GET', '/obx/v1/ingest/jobs/' . $job['id'] . '/results', ['bearer' => $token]))['items'];
        $find = static function (array $errors, ?int $row, ?string $col, string $code) {
            foreach ($errors as $e) {
                if ($e['row'] === $row && $e['column'] === $col && $e['obx_error'] === $code) {
                    return $e;
                }
            }
            return null;
        };
        $this->assertNotNull($find($errors, 2, 'gtin', 'invalid_gtin'), Json::encode($errors));
        $this->assertNotNull($find($errors, 3, 'name', 'validation_failed'), Json::encode($errors));
        $this->assertNotNull($find($errors, 4, 'gtin', 'invalid_gtin'), Json::encode($errors));
        $warning = $find($errors, null, 'Unknown Column', 'validation_failed');
        $this->assertNotNull($warning);
        $this->assertSame('warning', $warning['severity']);
        $image = null;
        foreach ($errors as $e) {
            if ($e['file'] === 'ZZ-9_NVY_1.png') {
                $image = $e;
            }
        }
        $this->assertSame('unknown_parent', $image['obx_error'] ?? null);
        $this->assertSame(1, (int) $this->app->db->value("SELECT COUNT(*) FROM obx_variants"));
        $run = $job['import_run'];
        $this->assertSame(4, $run['counts']['rows']);
        $this->assertGreaterThanOrEqual(4, $run['counts']['errors']);
    }

    public function testDryRunWritesNothing(): void
    {
        $this->freshNode();
        $token = $this->ingestToken();
        $job = $this->import($token, ['package' => [$this->upload($this->kestrelZip(), 'kestrel.zip')]], ['dry_run' => true]);
        $this->assertSame('succeeded', $job['status'], Json::encode($job));
        $this->assertTrue($job['import_run']['dry_run']);
        $this->assertSame(8 + 40 + 27 + 1, $job['import_run']['counts']['created']);
        $this->assertSame(0, (int) $this->app->db->value('SELECT COUNT(*) FROM obx_products'));
        $this->assertSame(1, (int) $this->app->db->value('SELECT COUNT(*) FROM obx_brands'));
    }

    public function testAdminUploadAndImportTemplate(): void
    {
        $this->freshNode();
        $csv = "style_number,name,color_code,size\nAD-1,Admin tee,BLK,M\n";
        $file = $this->dir . '/admin.csv';
        file_put_contents($file, $csv);
        $run = $this->json($this->http('POST', '/admin/api/imports', ['browser' => 'admin', 'files' => ['csv' => [$this->upload($file, 'products.csv')]]]), 202);
        $run = $this->json($this->http('GET', '/admin/api/imports/' . $run['id'], ['browser' => 'admin']));
        $this->assertSame('succeeded', $run['status']);
        $this->assertSame('upload', $run['trigger']);
        $name = Json::decode((string) $this->app->db->value("SELECT input FROM obx_products WHERE external_id = 'AD-1'"))['name'];
        $this->assertSame(['nl' => 'Admin tee'], $name, 'plain name uses the default language');
        $this->assertSame('AD-1-BLK-M', $this->app->db->value('SELECT external_id FROM obx_variants'));
        $list = $this->json($this->http('GET', '/admin/api/imports', ['browser' => 'admin']));
        $this->assertCount(1, $list['items']);
        $tpl = $this->http('GET', '/admin/api/import-template.csv', ['browser' => 'admin']);
        $this->assertSame(200, $tpl->status);
        $this->assertStringContainsString('style_number', $tpl->content());
        $this->assertStringContainsString('name:nl', $tpl->content());
    }
}
