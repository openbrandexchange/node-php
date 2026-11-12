<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Unit;

use Obx\Node\Import\Csv;
use Obx\Node\Import\Formats;
use Obx\Node\Import\SafeZip;
use Obx\Node\Http\ApiError;
use Obx\Node\Support\Gtin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?array}> the table in docs/import-format.md §2.1 */
    public static function filenames(): array
    {
        return [
            'plain' => ['KO-1001_NVY_1.jpg', ['style' => 'KO-1001', 'color' => 'NVY', 'position' => 1, 'role' => 'packshot', 'view' => null]],
            'view' => ['KO-1001_NVY_2_back.jpg', ['style' => 'KO-1001', 'color' => 'NVY', 'position' => 2, 'role' => 'packshot', 'view' => 'back']],
            'role' => ['KO-1003_RST_3_model.png', ['style' => 'KO-1003', 'color' => 'RST', 'position' => 3, 'role' => 'model', 'view' => null]],
            'underscore style + both' => ['AB_12_34_BLK_2_packshot_back.JPG', ['style' => 'AB_12_34', 'color' => 'BLK', 'position' => 2, 'role' => 'packshot', 'view' => 'back']],
            'detail' => ['KO-1004_IND_3_detail.jpg', ['style' => 'KO-1004', 'color' => 'IND', 'position' => 3, 'role' => 'detail', 'view' => 'detail']],
            'no position' => ['KO-1001_NVY_front.jpg', null],
            'two-part view' => ['KO-9_RED_4_tilted_left.webp', ['style' => 'KO-9', 'color' => 'RED', 'position' => 4, 'role' => 'packshot', 'view' => 'tilted_left']],
            'leading zero' => ['KO-9_RED_01.jpg', null],
            'not an image' => ['KO-9_RED_1.gif', null],
        ];
    }

    #[DataProvider('filenames')]
    public function testFilenamePattern(string $name, ?array $expected): void
    {
        $this->assertSame($expected, Formats::parseFilename($name));
    }

    /**
     * The spec's shared test vectors (fixtures/expected/image-filenames.json, synced into
     * tests/fixtures/spec): what the importer makes of each name.
     */
    public function testSpecFilenameVectors(): void
    {
        $doc = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/spec/expected/image-filenames.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertGreaterThanOrEqual(20, count($doc['cases']));
        foreach ($doc['cases'] as $case) {
            $parsed = Formats::parseFilename($case['name']);
            if (isset($case['error'])) {
                $this->assertNull($parsed, $case['name'] . ' is not a valid image name');
                continue;
            }
            $this->assertNotNull($parsed, $case['name']);
            $color = strcasecmp($parsed['color'], 'ALL') === 0 ? null : $parsed['color'];
            $got = [
                'style' => $parsed['style'], 'color' => $color, 'position' => $parsed['position'],
                'role' => $parsed['role'], 'view' => $parsed['view'],
                'external_id' => $parsed['style'] . '_' . ($color ?? 'ALL') . '_' . $parsed['position'],
            ];
            $want = [
                'style' => $case['style'], 'color' => $case['color'], 'position' => $case['position'],
                'role' => $case['role'], 'view' => $case['view'], 'external_id' => $case['external_id'],
            ];
            $this->assertSame($want, $got, $case['name']);
        }
    }

    public function testMaterialsGrammar(): void
    {
        $w = [];
        $this->assertSame(
            [['part' => 'outer', 'fibers' => [['fiber' => 'cotton', 'percentage' => 65], ['fiber' => 'polyamide', 'percentage' => 35]]], ['part' => 'lining', 'fibers' => [['fiber' => 'polyester', 'percentage' => 100]]]],
            Formats::parseMaterials('outer: 65% cotton, 35% polyamide; lining: 100% polyester', $w)
        );
        $this->assertSame([], $w);
        // 0.2: the 0.1 keys and the flags give the same fibre (import-format.md §1.4).
        $this->assertSame([['part' => 'main', 'fibers' => [['fiber' => 'cotton', 'percentage' => 100, 'organic' => true]]]], Formats::parseMaterials('100% Organic Cotton', $w));
        $this->assertSame(Formats::parseMaterials('100% recycled_polyester', $w), Formats::parseMaterials('100% polyester (Recycled)', $w));
        $this->assertSame([['part' => 'main', 'fibers' => [['fiber' => 'cotton', 'percentage' => 100, 'recycled' => true, 'organic' => true]]]], Formats::parseMaterials('100% cotton (organic) (recycled)', $w));
        $this->assertSame(12.5, Formats::parseMaterials('87,5% wool, 12.5% cashmere', $w)[0]['fibers'][1]['percentage']);
        $this->assertNull(Formats::parseMaterials('cotton', $w));
        Formats::parseMaterials('main: 90% cotton', $w);
        $this->assertSame([], $w, 'a sum other than 100 is the label warning fibre_percentages_not_100, not a parse warning');
    }

    public function testGtin(): void
    {
        $this->assertTrue(Gtin::valid('2000100101025'));
        $this->assertFalse(Gtin::valid('2000100101026'));
        $this->assertTrue(Gtin::valid('96385074'));
        $this->assertTrue(Gtin::valid('036000291452'));
        $this->assertFalse(Gtin::valid('12345'));
        $this->assertSame('02000100101025', Gtin::normalizeLookup(' 2000 100101025 '));
        $this->assertNull(Gtin::normalizeLookup('96385074000'), '11 digits are not a GTIN');
        $this->assertNull(Gtin::normalizeLookup('ABC'));
    }

    public function testCsvReadsBomSemicolonsAndQuotedNewlines(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($f, "\xEF\xBB\xBFStyle Number;Name:NL;EAN;colour_code\r\nKO-1;\"Trui; \"\"warm\"\"\nzacht\";2000100101025;NVY\r\n;;;\r\nKO-2;Jas;;BLK\r\n");
        $csv = Csv::read($f);
        unlink($f);
        $this->assertSame(';', $csv['delimiter']);
        $this->assertSame(['style_number', 'name:nl', 'gtin', 'color_code'], $csv['headers']);
        $this->assertCount(2, $csv['rows']);
        $this->assertSame("Trui; \"warm\"\nzacht", $csv['rows'][0]['values']['name:nl']);
        $this->assertSame(3, $csv['rows'][1]['row'], 'empty rows are skipped but keep their number');
    }

    public function testCsvRejectsNonUtf8(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($f, "style_number,name\nKO-1,Pull \xE9t\xE9\n");
        try {
            Csv::read($f);
            file_put_contents($f, "style_number,name\xE9\n");
            Csv::read($f);
            $this->fail('expected import_invalid');
        } catch (ApiError $e) {
            $this->assertSame('import_invalid', $e->obxError);
        } finally {
            unlink($f);
        }
    }

    public function testCsvCellsAreFormulaSafe(): void
    {
        $this->assertSame("'=SUM(A1)", Csv::cell('=SUM(A1)'));
        $this->assertSame("'@x", Csv::cell('@x'));
        $this->assertSame('-12', Csv::cell('-12'));
        $this->assertSame('2000100101025', Csv::cell('2000100101025'));
        $this->assertSame("\"a;b\";c\r\n", Csv::line(['a;b', 'c'], ';'));
    }

    public function testZipNamesAreChecked(): void
    {
        foreach (['../evil.jpg', '/abs.jpg', 'C:/x.jpg', 'a/../../b.jpg', 'folder/CON.jpg'] as $bad) {
            try {
                SafeZip::assertSafeName($bad);
                $this->fail($bad . ' should be refused');
            } catch (ApiError $e) {
                $this->assertSame('import_invalid', $e->obxError);
            }
        }
        SafeZip::assertSafeName('images/KO-1001_NVY_1.jpg');
        $this->assertTrue(SafeZip::ignored('__MACOSX/images/._a.jpg'));
        $this->assertTrue(SafeZip::ignored('x/.DS_Store'));
        $this->assertFalse(SafeZip::ignored('x/a.jpg'));
    }
}
