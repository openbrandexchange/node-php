<?php

declare(strict_types=1);

namespace Obx\Node\Services;

use Obx\Node\Db\Db;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Ids;
use Obx\Node\Support\Json;

/**
 * Download and usage statistics for the brand. Organisation level only: retailer and account,
 * never IP addresses. Admin previews are not recorded.
 */
final class Stats
{
    public const IMAGE_KINDS = ['image', 'product_zip', 'selection_zip', 'bulk_zip', 'api_rendition', 'api_export'];

    public function __construct(private Db $db)
    {
    }

    /**
     * @param array<string, mixed>|null $retailer
     * @param array<string, mixed> $extra variant, product_id, asset_id, usage_event, files, bytes
     */
    public function record(string $kind, ?array $retailer, string $actorType, ?string $actorId, ?string $actorName, array $extra = []): void
    {
        $this->db->insert('obx_download_events', [
            'id' => Ids::new('evt'),
            'at' => Clock::iso(),
            'at_ts' => Clock::time(),
            'kind' => $kind,
            'retailer_id' => $retailer['id'] ?? null,
            'retailer_name' => $retailer['name'] ?? null,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'variant' => $extra['variant'] ?? null,
            'product_id' => $extra['product_id'] ?? null,
            'asset_id' => $extra['asset_id'] ?? null,
            'usage_event' => $extra['usage_event'] ?? null,
            'files' => (int) ($extra['files'] ?? 0),
            'bytes' => $extra['bytes'] ?? null,
        ]);
    }

    /** @return array<string, mixed> Stats */
    public function summary(int $days): array
    {
        $since = Clock::time() - $days * 86400;
        $rows = $this->db->all('SELECT * FROM obx_download_events WHERE at_ts >= ?', [$since]);
        $totals = ['downloads' => 0, 'images' => 0, 'zips' => 0, 'csv_exports' => 0, 'api_downloads' => 0, 'usage_events' => 0, 'active_retailers' => 0];
        $byDay = [];
        $retailers = [];
        $products = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', Clock::time() - $i * 86400);
            $byDay[$d] = ['date' => $d, 'downloads' => 0, 'images' => 0];
        }
        foreach ($rows as $r) {
            $day = substr((string) $r['at'], 0, 10);
            $kind = (string) $r['kind'];
            $files = (int) $r['files'];
            $rid = $r['retailer_id'] !== null ? (string) $r['retailer_id'] : null;
            if ($rid !== null) {
                $retailers[$rid] ??= ['retailer_id' => $rid, 'name' => (string) $r['retailer_name'], 'downloads' => 0, 'images' => 0, 'last_activity_at' => null];
                if ($retailers[$rid]['last_activity_at'] === null || strcmp((string) $r['at'], (string) $retailers[$rid]['last_activity_at']) > 0) {
                    $retailers[$rid]['last_activity_at'] = $r['at'];
                }
            }
            if ($kind === 'usage') {
                $totals['usage_events'] += max(1, $files);
                continue;
            }
            $totals['downloads']++;
            $images = in_array($kind, self::IMAGE_KINDS, true) ? $files : 0;
            $totals['images'] += $images;
            if (in_array($kind, ['product_zip', 'selection_zip', 'bulk_zip'], true)) {
                $totals['zips']++;
            }
            if ($kind === 'csv') {
                $totals['csv_exports']++;
            }
            if ($kind === 'api_rendition' || $kind === 'api_export') {
                $totals['api_downloads']++;
            }
            if (isset($byDay[$day])) {
                $byDay[$day]['downloads']++;
                $byDay[$day]['images'] += $images;
            }
            if ($rid !== null) {
                $retailers[$rid]['downloads']++;
                $retailers[$rid]['images'] += $images;
            }
            if ($r['product_id'] !== null) {
                $products[(string) $r['product_id']] = ($products[(string) $r['product_id']] ?? 0) + 1;
            }
        }
        $totals['active_retailers'] = count($retailers);
        $top = array_values($retailers);
        usort($top, static fn ($a, $b) => $b['downloads'] <=> $a['downloads'] ?: strcmp($a['name'], $b['name']));
        arsort($products);
        $topProducts = [];
        foreach (array_slice($products, 0, 20, true) as $pid => $count) {
            $p = $this->db->one('SELECT style_number, input FROM obx_products WHERE id = ?', [$pid]);
            $topProducts[] = [
                'product_id' => $pid,
                'style_number' => $p['style_number'] ?? '',
                'name' => $p !== null ? (Json::decode((string) $p['input'], [])['name'] ?? ['en' => $p['style_number']]) : ['en' => '(deleted)'],
                'downloads' => $count,
            ];
        }
        return [
            'days' => $days,
            'totals' => $totals,
            'by_day' => array_values($byDay),
            'top_retailers' => array_slice($top, 0, 20),
            'top_products' => $topProducts,
        ];
    }

    /** @param array<string, mixed> $e @return array<string, mixed> */
    public static function event(array $e): array
    {
        return [
            'id' => $e['id'],
            'at' => $e['at'],
            'kind' => $e['kind'],
            'retailer_id' => $e['retailer_id'],
            'retailer_name' => $e['retailer_name'],
            'actor_type' => $e['actor_type'],
            'actor_name' => $e['actor_name'],
            'variant' => $e['variant'],
            'product_id' => $e['product_id'],
            'asset_id' => $e['asset_id'],
            'usage_event' => $e['usage_event'],
            'files' => (int) $e['files'],
            'bytes' => $e['bytes'] !== null ? (int) $e['bytes'] : null,
        ];
    }
}
