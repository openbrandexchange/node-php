<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Unit;

use Obx\Node\Import\WorkbookError;
use Obx\Node\Import\Xlsx;
use Obx\Node\Support\Json;
use Obx\Node\Tests\Support\NodeTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Excel conversion (docs/import-format.md §5) against the spec fixtures
 * fixtures/imports/xlsx/expected.json: rows, canonical CSV, conversion errors and warnings,
 * workbook refusals, and the number, date and format vectors.
 */
final class XlsxTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function expected(): array
    {
        return Json::decode((string) file_get_contents(NodeTestCase::fixtures('imports/xlsx/expected.json')));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function cases(): array
    {
        $out = [];
        foreach (self::expected()['cases'] as $c) {
            $out[$c['file']] = [$c];
        }
        return $out;
    }

    /** @param array<string, mixed> $c */
    #[DataProvider('cases')]
    public function testFixture(array $c): void
    {
        $path = NodeTestCase::fixtures('imports/xlsx/' . $c['file']);
        if (isset($c['error'])) {
            try {
                Xlsx::convert($path, $c['role']);
                $this->fail($c['file'] . ' should be refused');
            } catch (WorkbookError $e) {
                $this->assertSame($c['error']['obx_error'], $e->obxError, $e->getMessage());
                $this->assertStringContainsStringIgnoringCase($c['error']['detail_contains'], $e->getMessage());
            }
            return;
        }
        $res = Xlsx::convert($path, $c['role']);
        $this->assertSame($c['sheet'], $res['sheet']);
        $rows = Xlsx::rows($res);
        $csv = Xlsx::canonicalCsv($rows);
        if (isset($c['csv_file'])) {
            $this->assertSame((string) file_get_contents(NodeTestCase::fixtures(substr($c['csv_file'], strlen('fixtures/')))), $csv);
        } else {
            $this->assertSame($c['rows'], $rows);
            $this->assertSame($c['csv'], $csv);
        }
        $this->assertSame(self::strip($c['errors']), self::strip($res['errors']));
        $this->assertSame(self::strip($c['warnings']), self::strip($res['warnings']));
        foreach ([[$c['errors'], $res['errors']], [$c['warnings'], $res['warnings']]] as [$want, $got]) {
            foreach ($want as $i => $w) {
                $this->assertStringStartsWith($w['detail_prefix'], $got[$i]['detail']);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $errors
     * @return list<array<string, mixed>>
     */
    private static function strip(array $errors): array
    {
        return array_map(static function (array $e): array {
            unset($e['detail'], $e['detail_prefix']);
            ksort($e);
            return $e;
        }, $errors);
    }

    public function testNumberDateAndFormatVectors(): void
    {
        $x = self::expected();
        foreach ($x['number_vectors'] as $v) {
            $t = Xlsx::numberText($v['v']);
            $w = Xlsx::zeroPad($v['format']);
            if ($w > 0 && $t !== null && ctype_digit($t) && strlen($t) < $w) {
                $t = str_pad($t, $w, '0', STR_PAD_LEFT);
            }
            $this->assertSame($v['text'], $t, Json::encode($v));
        }
        foreach ($x['date_vectors'] as $v) {
            $this->assertSame($v['text'], Xlsx::serialText($v['v'], $v['kind'], $v['date1904']), Json::encode($v));
        }
        foreach ($x['format_vectors'] as $v) {
            $this->assertSame($v['kind'], Xlsx::dateKind($v['id'], $v['code']), Json::encode($v));
        }
    }

    public function testCellTextRules(): void
    {
        $this->assertSame("a\rb", Xlsx::unescape('a_x000D_b'));
        $this->assertSame('_x0031_', Xlsx::unescape('_x005F_x0031_'));
        $this->assertSame('😀', Xlsx::unescape('_xD83D__xDE00_'));
        $this->assertNull(Xlsx::numberText('abc'));
        $this->assertNull(Xlsx::numberText('.'));
        $this->assertNull(Xlsx::numberText('1E99999'), 'an absurd exponent is not a number');
        $this->assertSame('2026-03-01', Xlsx::isoCellText('2026-03-01T00:00:00Z'));
        $this->assertSame('2026-03-01T12:30:00Z', Xlsx::isoCellText('2026-03-01T12:30'));
        $this->assertNull(Xlsx::isoCellText('1 March 2026'));
        $this->assertSame('row 12, column ean (C): ', Xlsx::locator(12, 'ean', 3));
        $this->assertSame('row 12: ', Xlsx::locator(12, null, null));
        $this->assertSame('column ean (C): ', Xlsx::locator(null, 'ean', 3));
        $this->assertSame('column M: ', Xlsx::locator(null, null, 13));
        $this->assertSame('ALL', Xlsx::colLetter(1000));
    }

    public function testWhatIsAWorkbook(): void
    {
        $this->assertTrue(Xlsx::isWorkbook('Products.XLSX'));
        $this->assertFalse(Xlsx::isWorkbook('products.csv', Xlsx::CONTENT_TYPE), 'the name decides');
        $this->assertTrue(Xlsx::isWorkbook('', Xlsx::CONTENT_TYPE . '; charset=binary'));
        $this->assertFalse(Xlsx::isWorkbook('', 'text/csv'));
        $this->assertTrue(Xlsx::isOtherSpreadsheet('old.xls'));
        $this->assertTrue(Xlsx::isOtherSpreadsheet('macros.xlsm'));
        $this->assertTrue(Xlsx::isOtherSpreadsheet('sheet.ods'));
        $this->assertFalse(Xlsx::isOtherSpreadsheet('products.xlsx'));
    }

    /** An entry that inflates beyond its declared size is stopped while it is read. */
    public function testDeclaredSizesThatLieAreCaught(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip builds the test file');
        }
        $path = sys_get_temp_dir() . '/obx-xlsx-' . bin2hex(random_bytes(4)) . '.xlsx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $body = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . str_repeat(' ', 5000) . '</Types>';
        $zip->addFromString('[Content_Types].xml', $body);
        $zip->setCompressionName('[Content_Types].xml', \ZipArchive::CM_STORE);
        $zip->close();
        $bytes = (string) file_get_contents($path);
        $cd = strpos($bytes, "PK\x01\x02");
        $this->assertIsInt($cd);
        // Central directory: compressed size at +20, uncompressed size at +24. Declare 100 bytes.
        $bytes = substr_replace($bytes, pack('V', 100), $cd + 24, 4);
        file_put_contents($path, $bytes);
        try {
            Xlsx::convert($path, 'products');
            $this->fail('should be refused');
        } catch (WorkbookError $e) {
            $this->assertSame('workbook_too_large', $e->obxError, $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function testAnEmptyFileAndAPlainZipAreInvalid(): void
    {
        $path = sys_get_temp_dir() . '/obx-xlsx-' . bin2hex(random_bytes(4)) . '.xlsx';
        file_put_contents($path, '');
        try {
            Xlsx::convert($path, 'products');
            $this->fail('should be refused');
        } catch (WorkbookError $e) {
            $this->assertSame('workbook_invalid', $e->obxError);
        }
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $zip->addFromString('hello.txt', 'hi');
            $zip->close();
            try {
                Xlsx::convert($path, 'products');
                $this->fail('should be refused');
            } catch (WorkbookError $e) {
                $this->assertSame('workbook_invalid', $e->obxError);
                $this->assertStringContainsString('[Content_Types].xml', $e->getMessage());
            }
        }
        @unlink($path);
    }
}
