<?php

declare(strict_types=1);

namespace Obx\Node\Services;

use Obx\Node\App;
use Obx\Node\Catalog\Viewer;
use Obx\Node\Db\Db;
use Obx\Node\Support\Gtin;

/**
 * GTIN (EAN) lookup shared by the protocol (products:by-gtin) and the portal (paste a list).
 */
final class GtinLookup
{
    public function __construct(private App $app)
    {
    }

    /**
     * @param list<string> $inputs
     * @return array{results: list<array<string, mixed>>, products: list<array<string, mixed>>}
     */
    public function lookup(array $inputs, Viewer $v): array
    {
        $seen = [];
        $entries = [];
        foreach ($inputs as $raw) {
            $input = trim((string) $raw);
            $g = Gtin::normalizeLookup($input);
            $key = $g ?? 'invalid:' . $input;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $entries[] = ['input' => $input, 'gtin' => $g];
        }
        $gtins = array_values(array_filter(array_map(static fn ($e) => $e['gtin'], $entries)));
        $brandIds = $this->app->reader->brandIds($v);
        $matches = [];
        if ($gtins !== [] && $brandIds !== []) {
            foreach (array_chunk($gtins, 500) as $chunk) {
                $rows = $this->app->db->all(
                    'SELECT v.id, v.gtin, v.product_id FROM obx_variants v JOIN obx_products p ON p.id = v.product_id
                     WHERE v.visible = 1 AND p.visible = 1 AND v.gtin IN (' . Db::in($chunk) . ') AND p.brand_id IN (' . Db::in($brandIds) . ')',
                    array_merge($chunk, $brandIds)
                );
                foreach ($rows as $r) {
                    $matches[(string) $r['gtin']] = $r;
                }
            }
        }
        $results = [];
        $productOrder = [];
        foreach ($entries as $e) {
            if ($e['gtin'] === null) {
                $results[] = ['input' => $e['input'], 'gtin' => null, 'status' => 'invalid', 'product_id' => null, 'variant_id' => null];
                continue;
            }
            $m = $matches[$e['gtin']] ?? null;
            if ($m === null) {
                $results[] = ['input' => $e['input'], 'gtin' => $e['gtin'], 'status' => 'unmatched', 'product_id' => null, 'variant_id' => null];
                continue;
            }
            $results[] = ['input' => $e['input'], 'gtin' => $e['gtin'], 'status' => 'matched', 'product_id' => $m['product_id'], 'variant_id' => $m['id']];
            $productOrder[(string) $m['product_id']] = true;
        }
        $products = [];
        if ($productOrder !== []) {
            $ids = array_keys($productOrder);
            $rows = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach ($this->app->db->all('SELECT * FROM obx_products WHERE id IN (' . Db::in($chunk) . ')', $chunk) as $r) {
                    $rows[(string) $r['id']] = $r;
                }
            }
            $ordered = [];
            foreach ($ids as $id) {
                if (isset($rows[$id])) {
                    $ordered[] = $rows[$id];
                }
            }
            $products = $this->app->reader->details($ordered, $v);
        }
        return ['results' => $results, 'products' => $products];
    }
}
