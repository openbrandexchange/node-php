<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Unit;

use Obx\Node\Catalog\ArticleData;
use PHPUnit\Framework\TestCase;

/** The terms of materials and care, the products.csv cells of the article data (import-format.md §1.6) and rule 10. */
final class ArticleDataTest extends TestCase
{
    public function testTermsGetTheNamesOfTheVocabulary(): void
    {
        // A known code gets the vocabulary's name, also over a name that was given; main has none.
        $this->assertSame(['code' => 'lining', 'name' => ['en' => 'Lining', 'nl' => 'Voering', 'de' => 'Futter', 'fr' => 'Doublure']], ArticleData::term('parts', 'lining', ['en' => 'Inner']));
        $this->assertSame(['code' => 'main', 'name' => null], ArticleData::term('parts', 'main', ['en' => 'Main']));
        // Another code keeps what was given, without empty languages, or null.
        $this->assertSame(['code' => 'yak_wool', 'name' => ['en' => 'Yak wool']], ArticleData::term('materials', 'yak_wool', ['nl' => ' ', 'en' => 'Yak wool']));
        $this->assertSame(['code' => 'yak_wool', 'name' => null], ArticleData::term('materials', 'yak_wool'));
        $care = ArticleData::careEntry('wash_30', 'drying', '9_X', null, ['en' => 'Inside out']);
        $this->assertSame(['washing', '1_D_30', 'Wash at 30 °C', ['en' => 'Inside out']], [$care['category'], $care['symbol'], $care['name']['en'], $care['text']]);
        $this->assertSame(['code' => 'steam_only', 'category' => 'ironing', 'symbol' => null, 'name' => null, 'text' => null], ArticleData::careEntry('steam_only', 'ironing'));
    }

    public function testMaterialKeysOf01BecomeTheQualifier(): void
    {
        $this->assertSame(['polyester', 'recycled'], ArticleData::splitMaterialKey('recycled_polyester'));
        $this->assertSame(['cotton', 'organic'], ArticleData::splitMaterialKey('organic_recycled_cotton'));
        $this->assertSame(['wool', null], ArticleData::splitMaterialKey('wool'));
        $this->assertSame(['recycled_', null], ArticleData::splitMaterialKey('recycled_'));
        // The key wins over a qualifier that was also given.
        $f = ArticleData::fibre(['code' => 'organic_cotton'], 60.0, ['code' => 'regenerative']);
        $this->assertSame(['cotton', 60, 'organic', 'Biologisch'], [$f['material']['code'], $f['percentage'], $f['qualifier']['code'], $f['qualifier']['name']['nl']]);
        $this->assertNull(ArticleData::fibre(['code' => 'wool'], 30, null)['qualifier']);
    }

    public function testMaterialsOf02AreConverted(): void
    {
        $this->assertSame(
            [['part' => ['code' => 'lining'], 'fibers' => [
                ['material' => ['code' => 'polyester'], 'percentage' => 100, 'qualifier' => ['code' => 'recycled']],
                ['material' => ['code' => 'recycled_polyamide'], 'percentage' => 5, 'qualifier' => null],
                ['material' => ['code' => 'cotton'], 'percentage' => 1, 'qualifier' => null],
            ]]],
            ArticleData::materialsFromLegacy([['part' => 'lining', 'fibers' => [
                ['fiber' => 'polyester', 'percentage' => 100, 'recycled' => true],
                ['fiber' => 'recycled_polyamide', 'percentage' => 5],
                ['fiber' => 'cotton', 'percentage' => 1, 'organic' => false],
            ]]]),
        );
        $now = [['part' => ['code' => 'main', 'name' => null], 'fibers' => [['material' => ['code' => 'cotton', 'name' => null], 'percentage' => 100, 'qualifier' => null]]]];
        $this->assertSame($now, ArticleData::materialsFromLegacy($now));
        $this->assertNull(ArticleData::materialsFromLegacy(null));
    }

    public function testFootwearAndCertificationCells(): void
    {
        $this->assertSame(['upper' => 'leather', 'lining_and_sock' => null, 'outer_sole' => 'coated_leather'], ArticleData::parseFootwear('Upper: Leather; outer sole: coated leather'));
        foreach (['upper: plastic', 'heel: leather', 'leather', 'upper: leather; upper: textile', ' ; '] as $bad) {
            try {
                ArticleData::parseFootwear($bad);
                $this->fail('accepted ' . $bad);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('upper: leather; outer_sole: other', ArticleData::formatFootwear(['upper' => 'leather', 'lining_and_sock' => null, 'outer_sole' => 'other']));
        $certs = ArticleData::parseCertifications('GOTS:CU 123456; rws:CU 200101 (Material); oeko tex standard 100');
        $this->assertSame([
            ['scheme' => 'gots', 'licence_number' => 'CU 123456', 'scope' => 'product'],
            ['scheme' => 'rws', 'licence_number' => 'CU 200101', 'scope' => 'material'],
            ['scheme' => 'oeko_tex_standard_100', 'licence_number' => null, 'scope' => 'product'],
        ], $certs);
        $this->assertSame('gots:CU 123456; rws:CU 200101 (material); oeko_tex_standard_100', ArticleData::formatCertifications($certs));
    }

    public function testMeasurementAndFeatureCells(): void
    {
        $this->assertSame(2.6, ArticleData::parseMeasurement('2,6'));
        $this->assertSame(55, ArticleData::parseMeasurement('55'));
        foreach (['2.6 kg', '0', '-3', '1.000,5', ''] as $bad) {
            try {
                ArticleData::parseMeasurement($bad);
                $this->fail('accepted ' . $bad);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(['min' => 80, 'max' => 130], ArticleData::parseStrapLength('80-130'));
        $this->assertSame(['min' => 120, 'max' => 120], ArticleData::parseStrapLength('120'));
        $this->assertSame('80-130', ArticleData::formatMeasurement(['strap_length_cm' => ['min' => 80, 'max' => 130]], 'strap_length_cm'));
        $this->assertSame('120', ArticleData::formatMeasurement(['strap_length_cm' => ['min' => 120, 'max' => 120]], 'strap_length_cm'));
        $this->assertSame('15.6', ArticleData::formatMeasurement(['laptop_max_inch' => 15.6], 'laptop_max_inch'));
        $this->assertSame('grey', ArticleData::parseColorFamily('Gray'));
        $this->assertSame('multicolour', ArticleData::parseColorFamily('multicolor'));

        $this->assertSame(4, ArticleData::parseFeature('wheels', '4'));
        $this->assertSame('tsa_combination', ArticleData::parseFeature('lock', 'TSA combination'));
        $this->assertTrue(ArticleData::parseFeature('expandable', 'yes'));
        $this->assertSame(['shoulder_strap', 'lining'], ArticleData::parseFeature('detachable_parts', 'shoulder_strap|lining|shoulder strap'));
        $this->assertSame('5 cm', ArticleData::parseFeature('fringe', '5 cm'));
        try {
            ArticleData::parseStrapLength('130-80');
            $this->fail('accepted a strap with min above max');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        foreach ([['wheels', '3'], ['shell', 'glass'], ['expandable', 'perhaps'], ['adjustable_parts', 'sleeve']] as [$k, $v]) {
            try {
                ArticleData::parseFeature($k, $v);
                $this->fail('accepted ' . $k . '=' . $v);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(['shell' => 'hard', 'wheels' => 4, 'a_key' => 'x', 'z_key' => 1], ArticleData::orderFeatures(['z_key' => 1, 'wheels' => 4, 'a_key' => 'x', 'shell' => 'hard']));
    }

    public function testLabelWarnings(): void
    {
        $codes = static fn (array $p) => array_column(ArticleData::labelWarnings($p), 'code');
        $this->assertSame(['fibre_composition_missing'], $codes(['item_type' => 't_shirt', 'contains_animal_origin' => false]));
        $this->assertSame(['animal_origin_unknown'], $codes(['item_type' => 'scarf', 'materials' => [['part' => ['code' => 'main'], 'fibers' => [['material' => ['code' => 'merino_wool'], 'percentage' => 100]]]]]));
        $this->assertSame([], $codes(['item_type' => 'scarf', 'contains_animal_origin' => true, 'materials' => [['part' => ['code' => 'main'], 'fibers' => [['material' => ['code' => 'wool'], 'percentage' => 100]]]]]));
        $this->assertSame(['fibre_percentages_not_100', 'fibre_percentages_not_100'], $codes(['item_type' => 'jacket', 'contains_animal_origin' => false, 'materials' => [
            ['part' => ['code' => 'outer'], 'fibers' => [['material' => ['code' => 'cotton'], 'percentage' => 60]]],
            ['part' => ['code' => 'lining'], 'fibers' => [['material' => ['code' => 'cotton'], 'percentage' => 99.5]]],
        ]]));
        $this->assertSame(['footwear_materials_missing'], $codes(['item_type' => 'boots', 'footwear_materials' => ['upper' => 'leather', 'lining_and_sock' => 'textile', 'outer_sole' => null]]));
        $this->assertSame([], $codes(['item_type' => 'shoes', 'footwear_materials' => ['upper' => 'leather', 'lining_and_sock' => 'textile', 'outer_sole' => 'other']]));
        $this->assertSame([], $codes(['item_type' => 'watch']));
        // fibre_percentages_not_100 is a label warning for textile item types only (rule 10).
        $this->assertSame([], $codes(['item_type' => 'watch', 'materials' => [['part' => ['code' => 'strap'], 'fibers' => [['material' => ['code' => 'leather'], 'percentage' => 80]]]]]));
        $this->assertSame([], $codes([]));
    }
}
