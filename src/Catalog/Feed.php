<?php

declare(strict_types=1);

namespace Obx\Node\Catalog;

use Obx\Node\Db\Db;
use Obx\Node\Http\ApiError;
use Obx\Node\Security\Crypto;
use Obx\Node\Services\Grants;
use Obx\Node\Services\Settings;
use Obx\Node\Support\Clock;

/**
 * GET /obx/v1/changes: a snapshot of everything visible, then the live log (docs/protocol.md §6).
 */
final class Feed
{
    public const TYPES = ['brand', 'product', 'variant', 'asset'];
    public const RETENTION_DAYS = 90;

    public function __construct(
        private Db $db,
        private Crypto $crypto,
        private Reader $reader,
        private Grants $grants,
        private Settings $settings,
    ) {
    }

    public function liveCursor(int $seq): string
    {
        return $this->crypto->seal('cursor', ['p' => 'l', 's' => $seq]);
    }

    /**
     * @param array<string, mixed> $retailer retailer row
     * @return array<string, mixed> ChangesPage
     */
    public function page(?string $cursor, int $limit, Viewer $v, array $retailer): array
    {
        if ($cursor === null || $cursor === '') {
            $hw = (int) ($this->db->value('SELECT MAX(seq) FROM obx_changes') ?? 0);
            $state = ['p' => 's', 'hw' => $hw, 't' => 0, 'id' => '', 'ts' => Clock::time()];
            return $this->snapshot($state, $limit, $v, $retailer);
        }
        $state = $this->crypto->unseal('cursor', $cursor);
        if ($state === null || !isset($state['p'])) {
            throw new ApiError('cursor_invalid', 'This cursor is malformed or comes from another node. Restart without a cursor.');
        }
        if ($state['p'] === 's') {
            if ((int) ($state['ts'] ?? 0) < Clock::time() - 86400) {
                throw new ApiError('cursor_expired', 'Snapshot cursors expire after 24 hours. Restart without a cursor.');
            }
            return $this->snapshot($state, $limit, $v, $retailer);
        }
        if ($state['p'] === 'l' && isset($state['s']) && is_int($state['s'])) {
            $pruned = (int) ($this->settings->state()['pruned_seq'] ?? 0);
            if ($state['s'] < $pruned) {
                throw new ApiError('cursor_expired', 'This cursor is older than the retained history. Restart without a cursor.');
            }
            return $this->live((int) $state['s'], $limit, $v, $retailer);
        }
        throw new ApiError('cursor_invalid', 'This cursor is malformed. Restart without a cursor.');
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $retailer
     * @return array<string, mixed>
     */
    private function snapshot(array $state, int $limit, Viewer $v, array $retailer): array
    {
        $t = (int) $state['t'];
        $lastId = (string) $state['id'];
        $changes = [];
        $now = Clock::time();
        $brandIds = $this->reader->brandIds($v);
        while ($t < 4 && count($changes) < $limit) {
            $type = self::TYPES[$t];
            $need = $limit - count($changes);
            $rows = $this->snapshotRows($type, $lastId, $need, $brandIds, $now);
            foreach ($rows as $row) {
                $changes[] = $this->entry($type, 'upsert', $row, (int) $row['last_seq'], $v);
                $lastId = (string) $row['id'];
            }
            if (count($rows) < $need) {
                $t++;
                $lastId = '';
            }
        }
        if ($changes === [] && $t >= 4) {
            // Snapshot exhausted on a page boundary: continue live.
            return $this->live((int) $state['hw'], $limit, $v, $retailer);
        }
        $next = $this->crypto->seal('cursor', ['p' => 's', 'hw' => (int) $state['hw'], 't' => $t, 'id' => $lastId, 'ts' => (int) $state['ts']]);
        return ['changes' => $changes, 'next_cursor' => $next, 'has_more' => true, 'phase' => 'snapshot'];
    }

    /**
     * @param list<string> $brandIds
     * @return list<array<string, mixed>>
     */
    private function snapshotRows(string $type, string $afterId, int $limit, array $brandIds, int $now): array
    {
        if ($brandIds === []) {
            return [];
        }
        $in = Db::in($brandIds);
        $sql = match ($type) {
            'brand' => 'SELECT b.* FROM obx_brands b WHERE b.visible = 1 AND b.id > ? AND b.id IN (' . $in . ') ORDER BY b.id',
            'product' => 'SELECT p.* FROM obx_products p WHERE p.visible = 1 AND p.id > ? AND p.brand_id IN (' . $in . ') ORDER BY p.id',
            'variant' => 'SELECT v.*, p.brand_id AS brand_id FROM obx_variants v JOIN obx_products p ON p.id = v.product_id
                          WHERE v.visible = 1 AND v.id > ? AND p.brand_id IN (' . $in . ') ORDER BY v.id',
            'asset' => 'SELECT a.*, p.brand_id AS brand_id FROM obx_assets a JOIN obx_products p ON p.id = a.product_id
                        WHERE a.visible = 1 AND a.id > ? AND p.brand_id IN (' . $in . ') AND (a.expires_ts IS NULL OR a.expires_ts > ' . $now . ') ORDER BY a.id',
            default => throw new \InvalidArgumentException('Unknown object type ' . $type),
        };
        return $this->db->all($sql . ' LIMIT ' . $limit, array_merge([$afterId], $brandIds));
    }

    /**
     * @param array<string, mixed> $retailer
     * @return array<string, mixed>
     */
    private function live(int $after, int $limit, Viewer $v, array $retailer): array
    {
        $brandFilter = '';
        $params = [$after];
        if ($v->brandIds !== null) {
            if ($v->brandIds === []) {
                $brandFilter = ' AND 1 = 0';
            } else {
                $brandFilter = ' AND brand_id IN (' . Db::in($v->brandIds) . ')';
                $params = array_merge($params, $v->brandIds);
            }
        }
        $params[] = (string) $retailer['id'];
        $sql = 'SELECT * FROM obx_changes WHERE seq > ? AND ((type <> \'grant\'' . $brandFilter . ') OR (type = \'grant\' AND retailer_id = ?)) ORDER BY seq LIMIT ' . ($limit + 1);
        $rows = $this->db->all($sql, $params);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $after;
        $changes = [];
        $brandIds = null;
        foreach ($rows as $c) {
            $last = (int) $c['seq'];
            $entry = $this->liveEntry($c, $v, $retailer, $brandIds);
            if ($entry !== null) {
                $changes[] = $entry;
            }
        }
        return ['changes' => $changes, 'next_cursor' => $this->liveCursor($last), 'has_more' => $hasMore, 'phase' => 'live'];
    }

    /**
     * @param array<string, mixed> $c
     * @param array<string, mixed> $retailer
     * @param list<string>|null $brandIds
     * @return array<string, mixed>|null
     */
    private function liveEntry(array $c, Viewer $v, array $retailer, ?array &$brandIds): ?array
    {
        $base = [
            'seq' => (int) $c['seq'],
            'type' => $c['type'],
            'op' => $c['op'],
            'id' => $c['obj_id'],
            'external_id' => $c['external_id'],
            'brand_id' => $c['brand_id'],
            'product_id' => $c['product_id'],
            'updated_at' => $c['updated_at'],
        ];
        if ($c['type'] === 'grant') {
            $base['data'] = $this->grants->grant($retailer);
            $base['resync'] = (int) $c['resync'] === 1;
            return $base;
        }
        if ($c['op'] === 'delete') {
            return $base;
        }
        $brandIds ??= $this->reader->brandIds($v);
        $now = Clock::time();
        switch ($c['type']) {
            case 'brand':
                $row = $this->db->one('SELECT * FROM obx_brands WHERE id = ? AND visible = 1', [$c['obj_id']]);
                if ($row === null || !in_array($row['id'], $brandIds, true)) {
                    return null;
                }
                $base['updated_at'] = $row['updated_at'];
                $base['data'] = $this->reader->brand($row);
                return $base;
            case 'product':
                $row = $this->db->one('SELECT * FROM obx_products WHERE id = ? AND visible = 1', [$c['obj_id']]);
                if ($row === null || !in_array($row['brand_id'], $brandIds, true)) {
                    return null;
                }
                $base['brand_id'] = $row['brand_id'];
                $base['updated_at'] = $row['updated_at'];
                $base['data'] = $this->reader->product($row);
                return $base;
            case 'variant':
                $row = $this->db->one('SELECT v.*, p.brand_id AS brand_id FROM obx_variants v JOIN obx_products p ON p.id = v.product_id WHERE v.id = ? AND v.visible = 1', [$c['obj_id']]);
                if ($row === null || !in_array($row['brand_id'], $brandIds, true)) {
                    return null;
                }
                $base['brand_id'] = $row['brand_id'];
                $base['product_id'] = $row['product_id'];
                $base['updated_at'] = $row['updated_at'];
                $base['data'] = $this->reader->variant($row);
                return $base;
            case 'asset':
                $row = $this->db->one('SELECT a.*, p.brand_id AS brand_id FROM obx_assets a JOIN obx_products p ON p.id = a.product_id WHERE a.id = ? AND a.visible = 1 AND (a.expires_ts IS NULL OR a.expires_ts > ?)', [$c['obj_id'], $now]);
                if ($row === null || !in_array($row['brand_id'], $brandIds, true)) {
                    return null;
                }
                $base['brand_id'] = $row['brand_id'];
                $base['product_id'] = $row['product_id'];
                $base['updated_at'] = $row['updated_at'];
                $base['data'] = $this->reader->asset($row, $v);
                return $base;
        }
        return null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function entry(string $type, string $op, array $row, int $seq, Viewer $v): array
    {
        $data = match ($type) {
            'brand' => $this->reader->brand($row),
            'product' => $this->reader->product($row),
            'variant' => $this->reader->variant($row),
            'asset' => $this->reader->asset($row, $v),
            default => throw new \InvalidArgumentException('Unknown object type ' . $type),
        };
        return [
            'seq' => max(1, $seq),
            'type' => $type,
            'op' => $op,
            'id' => $row['id'],
            'external_id' => $row['external_id'],
            'brand_id' => $type === 'brand' ? $row['id'] : $row['brand_id'],
            'product_id' => in_array($type, ['variant', 'asset'], true) ? $row['product_id'] : null,
            'updated_at' => $row['updated_at'],
            'data' => $data,
        ];
    }

    /** Deletes change entries older than the retention window; remembers the cut. */
    public function prune(): int
    {
        $cutTs = Clock::time() - self::RETENTION_DAYS * 86400;
        $maxSeq = $this->db->value('SELECT MAX(seq) FROM obx_changes WHERE created_ts < ?', [$cutTs]);
        if ($maxSeq === null) {
            return 0;
        }
        $n = $this->db->run('DELETE FROM obx_changes WHERE seq <= ?', [(int) $maxSeq])->rowCount();
        $this->settings->updateState(['pruned_seq' => (int) $maxSeq]);
        return $n;
    }
}
