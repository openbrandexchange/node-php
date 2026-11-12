<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Http;

use Obx\Node\Import\Xlsx;
use Obx\Node\Support\Json;
use Obx\Node\Tests\Support\NodeTestCase;

/**
 * Excel workbooks imported as products.csv / assets.csv (docs/import-format.md §5): the
 * fixtures of fixtures/imports/xlsx/expected.json through the Ingest API (conformance I-IMP-9
 * and I-IMP-10), the admin upload, packages, the change feed and scheduled pulls.
 */
final class XlsxImportTest extends NodeTestCase
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** @param array<string, mixed> $files @param array<string, mixed> $options */
    private function import(string $token, array $files, array $options = [], array $post = []): array
    {
        if ($options !== []) {
            $post['options'] = Json::encode($options);
        }
        $job = $this->json($this->http('POST', '/obx/v1/ingest/imports', ['bearer' => $token, 'files' => $files, 'post' => $post]), 202);
        return $this->json($this->http('GET', '/obx/v1/ingest/jobs/' . $job['id'], ['bearer' => $token]));
    }

    /** @return list<array<string, mixed>> */
    private function results(string $token, string $jobId): array
    {
        return $this->json($this->http('GET', '/obx/v1/ingest/jobs/' . $jobId . '/results', ['bearer' => $token, 'query' => ['limit' => '500']]))['items'];
    }

    /** @return array<string, mixed> */
    private static function expected(): array
    {
        return Json::decode((string) file_get_contents(self::fixtures('imports/xlsx/expected.json')));
    }

    private function workbook(string $file, string $name): array
    {
        return $this->upload(self::fixtures('imports/xlsx/' . $file), $name, self::XLSX);
    }

    private function catalogSize(): int
    {
        return (int) $this->app->db->value('SELECT COUNT(*) FROM obx_products') + (int) $this->app->db->value('SELECT COUNT(*) FROM obx_variants');
    }

    /**
     * @param list<array<string, mixed>> $errors
     * @return list<array<string, mixed>>
     */
    private static function comparable(array $errors): array
    {
        $out = array_map(static fn ($e) => [
            'file' => $e['file'], 'row' => $e['row'], 'column' => $e['column'], 'severity' => $e['severity'],
            'obx_error' => $e['obx_error'], 'value' => $e['value'] ?? null,
        ], $errors);
        usort($out, static fn ($a, $b) => [$a['row'] ?? 0, $a['column'] ?? ''] <=> [$b['row'] ?? 0, $b['column'] ?? '']);
        return $out;
    }

    /**
     * I-IMP-9: each converting case as a dry run on a node that holds the Kestrel fixtures.
     * Two cases need more than the expected.json description says: date1904.xlsx has no brand
     * column, so it runs on a node with one brand (with two, as after the fixtures, every row
     * needs a brand); assets-template.xlsx names two images, so the import sends an images
     * manifest with them (an assets row whose file is not provided is files_missing, §2.2).
     */
    public function testConvertingCasesGiveTheExpectedRowErrors(): void
    {
        foreach (self::expected()['cases'] as $c) {
            if (isset($c['error'])) {
                continue;
            }
            $token = $c['file'] === 'date1904.xlsx' ? $this->freshToken() : $this->kestrelToken();
            $before = $this->catalogSize();
            $field = $c['role'] === 'products' ? 'csv' : 'assets_csv';
            $options = ['dry_run' => true];
            $post = [];
            if ($c['role'] === 'assets') {
                $manifest = [];
                foreach (Xlsx::rows(Xlsx::convert(self::fixtures('imports/xlsx/' . $c['file']), 'assets')) as $i => $row) {
                    if ($i > 0 && $row[0] !== '') {
                        $manifest[] = ['file' => $row[0], 'sha256' => hash_file('sha256', self::fixtures('kestrel/images/' . $row[0]))];
                    }
                }
                $post['images_manifest'] = Json::encode($manifest);
            }
            $job = $this->import($token, [$field => [$this->workbook($c['file'], 'upload-' . $c['file'])]], $options, $post);
            $this->assertSame('succeeded', $job['status'], $c['file'] . ': ' . Json::encode($job));
            $items = $this->results($token, $job['id']);
            $errors = array_values(array_filter($items, static fn ($e) => $e['severity'] === 'error'));
            $want = $c['import_errors'] ?? $c['errors'];
            $this->assertSame(self::comparable($want), self::comparable($errors), $c['file'] . ': ' . Json::encode($items));
            foreach ($want as $w) {
                $hit = array_values(array_filter($errors, static fn ($e) => $e['row'] === $w['row'] && $e['column'] === $w['column']));
                $this->assertStringStartsWith($w['detail_prefix'], $hit[0]['detail'], $c['file']);
            }
            foreach ($c['warnings'] as $w) {
                $hit = array_filter($items, static fn ($e) => $e['severity'] === 'warning' && $e['file'] === $w['file'] && $e['row'] === $w['row'] && str_starts_with($e['detail'], $w['detail_prefix']));
                $this->assertNotEmpty($hit, $c['file'] . ' warning ' . $w['detail_prefix'] . ': ' . Json::encode($items));
            }
            foreach ($items as $e) {
                $this->assertSame($c['role'] . '.xlsx', $e['file'], 'every error names the workbook, whatever the upload was called');
            }
            $this->assertSame($before, $this->catalogSize(), 'dry runs write nothing');
        }
    }

    private function freshToken(): string
    {
        $this->resetNode(); // the snapshots are built on an empty node
        $this->freshNode();
        return $this->ingestToken();
    }

    private function kestrelToken(): string
    {
        $this->resetNode();
        return $this->kestrelNode()['ingest'];
    }

    /** I-IMP-10: each refusal is one ImportRowError with row null, and nothing is written. */
    public function testRefusedWorkbooksGiveOneErrorAndWriteNothing(): void
    {
        $this->freshNode();
        $token = $this->ingestToken();
        foreach (self::expected()['cases'] as $c) {
            if (!isset($c['error'])) {
                continue;
            }
            $job = $this->import($token, ['csv' => [$this->workbook($c['file'], $c['file'])]]);
            $this->assertSame('succeeded', $job['status'], $c['file'] . ': ' . Json::encode($job));
            $items = $this->results($token, $job['id']);
            $this->assertCount(1, $items, $c['file'] . ': ' . Json::encode($items));
            $e = $items[0];
            $this->assertSame(['products.xlsx', null, null, 'error', $c['error']['obx_error']], [$e['file'], $e['row'], $e['column'], $e['severity'], $e['obx_error']], $c['file']);
            $this->assertStringContainsStringIgnoringCase($c['error']['detail_contains'], $e['detail'], $c['file']);
            $this->assertSame(0, $this->catalogSize(), $c['file']);
        }
    }

    /**
     * The template as it is gives the rows of fixtures/kestrel/products.csv: on an empty node it
     * creates what the CSV would, and the CSV afterwards changes nothing (and the other way round,
     * on a node that holds the fixtures the workbook is all unchanged with no change entries).
     */
    public function testTheProductsTemplateImportsLikeProductsCsv(): void
    {
        $this->freshNode();
        $token = $this->ingestToken();
        $job = $this->import($token, ['csv' => [$this->workbook('products-template.xlsx', 'products.xlsx')]]);
        $this->assertSame('succeeded', $job['status'], Json::encode($job));
        $counts = $job['import_run']['counts'];
        $this->assertSame(0, $counts['errors'], Json::encode($this->results($token, $job['id'])));
        $this->assertSame(['products.xlsx'], array_column($job['import_run']['files'], 'name'));
        $this->assertGreaterThan(0, $counts['created']);
        $csv = $this->upload(self::fixtures('kestrel/products.csv'), 'products.csv');
        $again = $this->import($token, ['csv' => [$csv]]);
        $this->assertSame(0, $again['import_run']['counts']['created'] + $again['import_run']['counts']['updated'], Json::encode($again['import_run']['counts']));
        $this->assertSame($counts['created'] - 1, $again['import_run']['counts']['unchanged'], 'the same objects, less the brand the first import created');

        ['ingest' => $token] = $this->kestrelNode();
        [, $id, $secret] = $this->retailerClient();
        $client = $this->accessToken($id, $secret);
        [, $cursor] = $this->drainSnapshot($client);
        $job = $this->import($token, ['csv' => [$this->workbook('products-template.xlsx', 'Kestrel catalogue.xlsx')]]);
        $c = $job['import_run']['counts'];
        $this->assertSame([0, 0, 0], [$c['created'], $c['updated'], $c['errors']], Json::encode($c));
        $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $client, 'query' => ['cursor' => $cursor]]));
        $this->assertSame([], $page['changes']);
    }

    /** A product from a workbook reaches retailers through /changes like any other. */
    public function testAWorkbookProductAppearsInTheChangeFeed(): void
    {
        ['ingest' => $token] = $this->kestrelNode();
        [, $id, $secret] = $this->retailerClient();
        $client = $this->accessToken($id, $secret);
        [, $cursor] = $this->drainSnapshot($client);
        $job = $this->import($token, ['csv' => [$this->workbook('edge-cases.xlsx', 'edge-cases.xlsx')]]);
        $this->assertSame('succeeded', $job['status']);
        $page = $this->json($this->http('GET', '/obx/v1/changes', ['bearer' => $client, 'query' => ['cursor' => $cursor]]));
        $seen = array_map(static fn ($e) => $e['type'] . ' ' . $e['op'] . ' ' . $e['external_id'], $page['changes']);
        $this->assertContains('product upsert KX-9001', $seen, Json::encode($seen));
        $this->assertContains('variant upsert 02000901000022', $seen, 'a zero-padded GTIN keeps its leading zero (the variant id is the GTIN)');
        $this->assertNotContains('product upsert KX-9005', $seen, 'the #N/A row is skipped');
    }

    public function testAdminUploadPackagesAndOtherSpreadsheets(): void
    {
        $token = $this->freshToken();
        // The admin upload takes the workbook in the csv field.
        $run = $this->json($this->http('POST', '/admin/api/imports', ['browser' => 'admin', 'files' => ['csv' => [$this->workbook('date1904.xlsx', 'dates.xlsx')]]]), 202);
        $run = $this->json($this->http('GET', '/admin/api/imports/' . $run['id'], ['browser' => 'admin']));
        $this->assertSame('succeeded', $run['status'], Json::encode($run));
        $this->assertSame(0, $run['counts']['errors'], Json::encode($run['errors_preview']));
        $this->assertSame(['dates.xlsx'], array_column($run['files'], 'name'));
        $attrs = Json::decode((string) $this->app->db->value("SELECT input FROM obx_products WHERE external_id = 'KX-9101'"))['attributes'] ?? null;
        $this->assertSame('2026-03-01', $attrs['launch_date'] ?? null, Json::encode($attrs));

        // A part without a file name is a workbook by its content type.
        $job = $this->import($token, ['csv' => [$this->workbook('date1904.xlsx', '')]], ['dry_run' => true]);
        $this->assertSame('succeeded', $job['status']);
        $this->assertSame(0, $job['import_run']['counts']['errors']);
        $this->assertSame(2, $job['import_run']['counts']['rows']);

        // Other spreadsheet files are not workbooks: 415.
        $xls = $this->dir . '/old.xls';
        file_put_contents($xls, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
        $this->assertProblem($this->http('POST', '/obx/v1/ingest/imports', ['bearer' => $token, 'files' => ['csv' => [$this->upload($xls, 'old.xls')]]]), 415, 'unsupported_media_type');

        // A package: products.xlsx and assets.xlsx take the place of the CSV files.
        $token = $this->kestrelToken();
        $zipPath = $this->dir . '/wb.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFile(self::fixtures('imports/xlsx/products-template.xlsx'), 'export/products.xlsx');
        $zip->addFile(self::fixtures('imports/xlsx/assets-template.xlsx'), 'export/assets.xlsx');
        foreach (glob(self::fixtures('kestrel/images') . '/*') ?: [] as $img) {
            $zip->addFile($img, 'images/' . basename($img));
        }
        $zip->close();
        $job = $this->import($token, ['package' => [$this->upload($zipPath, 'wb.zip', 'application/zip')]], ['dry_run' => true]);
        $this->assertSame('succeeded', $job['status'], Json::encode($job));
        $this->assertSame(0, $job['import_run']['counts']['errors'], Json::encode($this->results($token, $job['id'])));
        $this->assertGreaterThan(0, $job['import_run']['counts']['unchanged']);

        // Both products.csv and products.xlsx in one package: import_invalid.
        $both = $this->kestrelZip(null, ['products.xlsx' => (string) file_get_contents(self::fixtures('imports/xlsx/products-template.xlsx'))]);
        $job = $this->import($token, ['package' => [$this->upload($both, 'both.zip', 'application/zip')]]);
        $this->assertSame('failed', $job['status']);
        $this->assertSame('import_invalid', $job['import_run']['error']['obx_error']);
    }

    /** A scheduled pull from a web address that names a workbook. */
    public function testAWebAddressCanPointAtAWorkbook(): void
    {
        $this->freshNode();
        $site = $this->dir . '/site';
        mkdir($site);
        copy(self::fixtures('imports/xlsx/products-template.xlsx'), $site . '/catalog.xlsx');
        $port = random_int(40001, 50000);
        $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $site], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            for ($i = 0; $i < 100 && ($s = @fsockopen('127.0.0.1', $port, $e, $es, 0.2)) === false; $i++) {
                usleep(50000);
            }
            $src = $this->json($this->http('POST', '/admin/api/import-sources', ['browser' => 'admin', 'json' => [
                'name' => 'Workbook', 'kind' => 'https_url', 'config' => ['url' => 'http://127.0.0.1:' . $port . '/catalog.xlsx'],
                'schedule' => ['frequency' => 'hourly'],
            ]]), 201);
            $run = $this->json($this->http('POST', '/admin/api/import-sources/' . $src['id'] . '/run', ['browser' => 'admin', 'json' => []]), 202);
            $run = $this->json($this->http('GET', '/admin/api/imports/' . $run['id'], ['browser' => 'admin']));
            $this->assertSame('succeeded', $run['status'], Json::encode($run));
            $this->assertSame(0, $run['counts']['errors'], Json::encode($run['errors_preview']));
            $this->assertGreaterThan(0, $run['counts']['created']);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }
}
