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
            'plain' => ['MN-1001_NVY_1.jpg', ['style' => 'MN-1001', 'color' => 'NVY', 'position' => 1, 'role' => 'packshot', 'view' => null]],
            'view' => ['MN-1001_NVY_2_back.jpg', ['style' => 'MN-1001', 'color' => 'NVY', 'position' => 2, 'role' => 'packshot', 'view' => 'back']],
            'role' => ['MN-1003_RST_3_model.png', ['style' => 'MN-1003', 'color' => 'RST', 'position' => 3, 'role' => 'model', 'view' => null]],
            'underscore style + both' => ['AB_12_34_BLK_2_packshot_back.JPG', ['style' => 'AB_12_34', 'color' => 'BLK', 'position' => 2, 'role' => 'packshot', 'view' => 'back']],
            'detail' => ['MN-1004_IND_3_detail.jpg', ['style' => 'MN-1004', 'color' => 'IND', 'position' => 3, 'role' => 'detail', 'view' => 'detail']],
            'no position' => ['MN-1001_NVY_front.jpg', null],
            'two-part view' => ['MN-9_RED_4_tilted_left.webp', ['style' => 'MN-9', 'color' => 'RED', 'position' => 4, 'role' => 'packshot', 'view' => 'tilted_left']],
            'leading zero' => ['MN-9_RED_01.jpg', null],
            'not an image' => ['MN-9_RED_1.gif', null],
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
        $fibre = static fn (string $code, int|float $pct, string ...$qs) => ['material' => ['code' => $code], 'percentage' => $pct, 'qualifiers' => array_map(static fn ($q) => ['code' => $q], $qs)];
        $this->assertSame(
            [['part' => ['code' => 'outer'], 'fibers' => [$fibre('cotton', 65), $fibre('polyamide', 35)]], ['part' => ['code' => 'lining'], 'fibers' => [$fibre('polyester', 100)]]],
            Formats::parseMaterials('outer: 65% cotton, 35% polyamide; lining: 100% polyester', $w)
        );
        $this->assertSame([], $w);
        // The 0.1 keys and the qualifier give the same fibre (import-format.md §1.4).
        $this->assertSame([['part' => ['code' => 'main'], 'fibers' => [$fibre('cotton', 100, 'organic')]]], Formats::parseMaterials('100% Organic Cotton', $w));
        $this->assertSame(Formats::parseMaterials('100% recycled_polyester', $w), Formats::parseMaterials('100% polyester (Recycled)', $w));
        $this->assertSame([['part' => ['code' => 'main'], 'fibers' => [$fibre('merino_wool', 100, 'regenerative')]]], Formats::parseMaterials('100% Merino Wool (regenerative)', $w));
        $this->assertSame([['part' => ['code' => 'main'], 'fibers' => [$fibre('cotton', 100, 'in_conversion')]]], Formats::parseMaterials('100% cotton (in conversion)', $w));
        // Several qualifiers, one code per bracket, in order; a repeat counts once; the 0.1 key first.
        $this->assertSame([['part' => ['code' => 'main'], 'fibers' => [$fibre('polyester', 100, 'recycled', 'post_consumer')]]], Formats::parseMaterials('100% polyester (recycled) (post_consumer)', $w));
        $this->assertSame([['part' => ['code' => 'main'], 'fibers' => [$fibre('cotton', 95, 'organic', 'regenerative'), $fibre('elastane', 5)]]], Formats::parseMaterials('95% cotton (organic)(regenerative) (organic), 5% elastane', $w));
        $this->assertSame([['part' => ['code' => 'main'], 'fibers' => [$fibre('polyester', 100, 'recycled', 'post_consumer')]]], Formats::parseMaterials('100% recycled_polyester (post consumer) (recycled)', $w));
        foreach (['100% cotton (organic, recycled)', 'outer: 100% polyester (recycled,post_consumer); lining: 100% cotton'] as $bad) {
            try {
                Formats::parseMaterials($bad, $w);
                $this->fail('two codes in one pair of brackets: ' . $bad);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('one code per bracket', $e->getMessage());
            }
        }
        $this->assertSame([87.5, 12.5], array_column(Formats::parseMaterials('87.5% wool, 12.5% cashmere', $w)[0]['fibers'], 'percentage'));
        try {
            Formats::parseMaterials('87,5% wool, 12,5% cashmere', $w);
            $this->fail('a decimal comma');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('materials: decimal comma', $e->getMessage());
        }
        // A fibre without a percentage (import-format.md §1.4).
        $this->assertSame([null, 60, null], array_merge(...array_map(static fn ($p) => array_column($p['fibers'], 'percentage'), Formats::parseMaterials('outer: leather; lining: 60% cotton, polyester', $w))));
        $this->assertSame(null, Formats::parseMaterials('cotton', $w)[0]['fibers'][0]['percentage'], 'a material alone has no percentage');
        $this->assertNull(Formats::parseMaterials('100%', $w));
        $this->assertNull(Formats::parseMaterials('120% cotton', $w));
        $this->assertNull(Formats::parseMaterials('100% cotton ()', $w));
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
        file_put_contents($f, "\xEF\xBB\xBFStyle Number;Name:NL;EAN;colour_code\r\nMN-1;\"Trui; \"\"warm\"\"\nzacht\";2000100101025;NVY\r\n;;;\r\nMN-2;Jas;;BLK\r\n");
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
        file_put_contents($f, "style_number,name\nMN-1,Pull \xE9t\xE9\n");
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
        SafeZip::assertSafeName('images/MN-1001_NVY_1.jpg');
        $this->assertTrue(SafeZip::ignored('__MACOSX/images/._a.jpg'));
        $this->assertTrue(SafeZip::ignored('x/.DS_Store'));
        $this->assertFalse(SafeZip::ignored('x/a.jpg'));
    }

    public function testExifOrientationTurnsRenditionsUpright(): void
    {
        if (!\Obx\Node\Images\Renderer::available()) {
            $this->markTestSkipped('GD is not available');
        }
        $img = imagecreatetruecolor(40, 20);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        // APP1 Exif with IFD0 Orientation = 6 (rotate 90 degrees clockwise), big-endian TIFF.
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00" . "\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00" . $tiff;
        $with = "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
        $dir = sys_get_temp_dir() . '/obx-exif-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/in.jpg', $with);
        $this->assertSame(6, \Obx\Node\Images\ImageInfo::exifOrientation($dir . '/in.jpg'));
        file_put_contents($dir . '/plain.jpg', $jpeg);
        $this->assertSame(1, \Obx\Node\Images\ImageInfo::exifOrientation($dir . '/plain.jpg'));
        $out = \Obx\Node\Images\Renderer::render($dir . '/in.jpg', 'image/jpeg', $dir . '/out');
        $this->assertSame([20, 40], [$out['web']['width'], $out['web']['height']]);
        foreach (glob($dir . '/out/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($dir . '/out');
        unlink($dir . '/in.jpg');
        unlink($dir . '/plain.jpg');
        rmdir($dir);
    }
}
