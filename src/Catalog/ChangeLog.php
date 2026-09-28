<?php

declare(strict_types=1);

namespace Obx\Node\Catalog;

use Obx\Node\Db\Db;
use Obx\Node\Support\Clock;

/**
 * Appends entries to the change feed (obx_changes). Entries store identity and routing only;
 * `data` is always the current object, read at feed time.
 */
final class ChangeLog
{
    public int $appended = 0;

    public function __construct(private Db $db)
    {
    }

    public function append(string $type, string $op, string $id, ?string $externalId, ?string $brandId, ?string $productId, string $updatedAt, ?string $retailerId = null, bool $resync = false): int
    {
        $this->db->insert('obx_changes', [
            'type' => $type,
            'op' => $op,
            'obj_id' => $id,
            'external_id' => $externalId,
            'brand_id' => $brandId,
            'product_id' => $productId,
            'retailer_id' => $retailerId,
            'resync' => $resync ? 1 : 0,
            'updated_at' => $updatedAt,
            'created_ts' => Clock::time(),
        ]);
        $this->appended++;
        return $this->db->lastId();
    }

    public function latestSeq(): int
    {
        return (int) ($this->db->value('SELECT MAX(seq) FROM obx_changes') ?? 0);
    }

    /** Writes a grant entry (resync) for one retailer. */
    public function grant(string $grantId, string $retailerId, string $updatedAt, bool $resync = true): int
    {
        return $this->append('grant', 'upsert', $grantId, null, null, null, $updatedAt, $retailerId, $resync);
    }
}
