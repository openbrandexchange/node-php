<?php

declare(strict_types=1);

namespace Obx\Node\Tests\Unit;

use Obx\Node\Catalog\ArticleData;
use PHPUnit\Framework\TestCase;

/** The products.csv cells of the 0.2 article data (import-format.md §1.6) and rule 10. */
final class ArticleDataTest extends TestCase
{
    public function testFibreKeysOf01AreNormalised(): void
    {
        $this->assertSame(['fiber' => 'polyester', 'percentage' => 100, 'recycled' => true], ArticleData::normalizeFiber(['fiber' => 'recycled_polyester', 'percentage' => 100]));
        $this->assertSame(['fiber' => 'cotton', 'percentage' => 50, 'recycled' => true, 'organic' => true], ArticleData::normalizeFiber(['fiber' => 'organic_recycled_cotton', 'percentage' => 50]));
        $this->assertSame(['fiber' => 'wool', 'percentage' => 30], ArticleData::normalizeFiber(['fiber' => 'wool', 'percentage' => 30, 'recycled' => false]));
        $this->assertSame('recycled_', ArticleData::normalizeFiber(['fiber' => 'recycled_', 'percentage' => 1])['fiber']);
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
        $this->assertSame(['animal_origin_unknown'], $codes(['item_type' => 'scarf', 'materials' => [['part' => 'main', 'fibers' => [['fiber' => 'merino_wool', 'percentage' => 100]]]]]));
        $this->assertSame([], $codes(['item_type' => 'scarf', 'contains_animal_origin' => true, 'materials' => [['part' => 'main', 'fibers' => [['fiber' => 'wool', 'percentage' => 100]]]]]));
        $this->assertSame(['fibre_percentages_not_100', 'fibre_percentages_not_100'], $codes(['item_type' => 'jacket', 'contains_animal_origin' => false, 'materials' => [
            ['part' => 'outer', 'fibers' => [['fiber' => 'cotton', 'percentage' => 60]]],
            ['part' => 'lining', 'fibers' => [['fiber' => 'cotton', 'percentage' => 99.5]]],
        ]]));
        $this->assertSame(['footwear_materials_missing'], $codes(['item_type' => 'boots', 'footwear_materials' => ['upper' => 'leather', 'lining_and_sock' => 'textile', 'outer_sole' => null]]));
        $this->assertSame([], $codes(['item_type' => 'shoes', 'footwear_materials' => ['upper' => 'leather', 'lining_and_sock' => 'textile', 'outer_sole' => 'other']]));
        $this->assertSame([], $codes(['item_type' => 'watch']));
        // fibre_percentages_not_100 is a label warning for textile item types only (rule 10).
        $this->assertSame([], $codes(['item_type' => 'watch', 'materials' => [['part' => 'strap', 'fibers' => [['fiber' => 'leather', 'percentage' => 80]]]]]));
        $this->assertSame([], $codes([]));
    }
}
