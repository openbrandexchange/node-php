<?php

declare(strict_types=1);

namespace Obx\Node\Catalog;

use Obx\Node\Db\Db;
use Obx\Node\Http\ApiError;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Json;

/**
 * Schema 4 (0.2.0): brings products and variants written by 0.1 to the 0.2 stored model.
 *
 * - The write model gets the 0.2 fields (null) and normalised fibres (recycled_polyester →
 *   polyester with recycled: true), so sending the same data again after the upgrade is
 *   "unchanged" (the ETag is computed over the canonical model).
 * - The protocol representation gets the 0.2 properties. When the only difference is that
 *   the new properties appear as null, the stored representation is replaced without a change
 *   entry (nothing a consumer knows changed). When a 0.1 fibre key was normalised, the content
 *   did change: a visible object gets an upsert entry and a new updated_at.
 *
 * Idempotent: running it again changes nothing. Works the same on SQLite and MySQL.
 */
final class ArticleDataUpgrade
{
    private const NEW_PRODUCT = ['item_subtype', 'contains_animal_origin', 'footwear_materials', 'certifications', 'features'];
    private const NEW_VARIANT = ['size_length', 'measurements'];

    public static function run(Db $db): void
    {
        $log = new ChangeLog($db);
        $now = Clock::iso();
        foreach (['product', 'variant'] as $kind) {
            $table = Writer::TABLES[$kind];
            $last = '';
            while (true) {
                $rows = $db->all('SELECT * FROM ' . $table . ' WHERE id > ? ORDER BY id LIMIT 500', [$last]);
                if ($rows === []) {
                    break;
                }
                $brands = [];
                if ($kind === 'variant') {
                    $ids = array_values(array_unique(array_map(static fn ($r) => (string) $r['product_id'], $rows)));
                    foreach ($db->all('SELECT id, brand_id FROM obx_products WHERE id IN (' . Db::in($ids) . ')', $ids) as $p) {
                        $brands[(string) $p['id']] = (string) $p['brand_id'];
                    }
                }
                foreach ($rows as $row) {
                    $last = (string) $row['id'];
                    self::upgradeRow($db, $log, $kind, $table, $row, $brands, $now);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $brands product id => brand id (variants)
     */
    private static function upgradeRow(Db $db, ChangeLog $log, string $kind, string $table, array $row, array $brands, string $now): void
    {
        $stored = Json::decode((string) $row['input'], null);
        if (!is_array($stored)) {
            return;
        }
        try {
            [$model] = $kind === 'product' ? (new Validator())->product($stored) : (new Validator())->variant($stored);
        } catch (ApiError) {
            return; // not valid under 0.1 either; left as it is
        }
        $set = [];
        $input = Json::encode($model);
        if ($input !== (string) $row['input']) {
            $set['input'] = $input;
            $set['etag'] = Writer::etagOf($kind, $model, (string) $row['status'], '', '');
        }
        if ((string) $row['rep_hash'] !== '') {
            $fresh = ['input' => $input] + $row;
            $brandId = $kind === 'product' ? (string) $row['brand_id'] : ($brands[(string) $row['product_id']] ?? null);
            if ($brandId !== null) {
                $rep = $kind === 'product' ? Representation::product($fresh) : Representation::variant($fresh, $brandId);
                $hash = hash('sha256', Json::canonical($rep));
                if ($hash !== (string) $row['rep_hash']) {
                    $set['rep'] = Json::encode($rep);
                    $set['rep_hash'] = $hash;
                    $old = Json::decode((string) $row['rep'], []);
                    if ((int) $row['visible'] === 1 && Json::canonical(self::withoutNewNulls($kind, $rep)) !== Json::canonical($old)) {
                        $set['updated_at'] = $now;
                        $set['last_seq'] = $log->append($kind, 'upsert', (string) $row['id'], (string) $row['external_id'], $brandId,
                            $kind === 'variant' ? (string) $row['product_id'] : null, $now);
                    }
                }
            }
        }
        if ($set !== []) {
            $db->update($table, $set, ['id' => $row['id']]);
        }
    }

    /**
     * The representation without the 0.2 properties that are null (what a 0.1 node wrote).
     *
     * @param array<string, mixed> $rep
     * @return array<string, mixed>
     */
    private static function withoutNewNulls(string $kind, array $rep): array
    {
        foreach ($kind === 'product' ? self::NEW_PRODUCT : self::NEW_VARIANT as $k) {
            if (array_key_exists($k, $rep) && $rep[$k] === null) {
                unset($rep[$k]);
            }
        }
        if ($kind === 'variant' && is_array($rep['color'] ?? null) && array_key_exists('family', $rep['color']) && $rep['color']['family'] === null) {
            unset($rep['color']['family']);
        }
        return $rep;
    }
}
