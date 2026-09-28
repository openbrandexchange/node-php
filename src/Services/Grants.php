<?php

declare(strict_types=1);

namespace Obx\Node\Services;

use Obx\Node\Catalog\ChangeLog;
use Obx\Node\Catalog\Viewer;
use Obx\Node\Db\Db;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Json;

/**
 * A retailer's grant: which brands it sees, which scopes its clients may hold and whether
 * originals are allowed (brand setting AND retailer setting).
 */
final class Grants
{
    public const ALL_SCOPES = ['catalog:read', 'media:read', 'media:original', 'usage:write'];

    public function __construct(private Db $db, private Settings $settings, private ChangeLog $log)
    {
    }

    /** @param array<string, mixed> $retailer */
    public function originalAllowed(array $retailer): bool
    {
        return (bool) $this->settings->get('portal', 'allow_original_download') && ($retailer['allow_original'] === null || (int) $retailer['allow_original'] === 1);
    }

    /** @param array<string, mixed> $retailer @return list<string>|null */
    public static function brandIds(array $retailer): ?array
    {
        $ids = Json::decode(is_string($retailer['brand_ids']) ? $retailer['brand_ids'] : null, null);
        return is_array($ids) ? array_values(array_map('strval', $ids)) : null;
    }

    /** @param array<string, mixed> $retailer @return list<string> */
    public function scopes(array $retailer): array
    {
        $scopes = self::ALL_SCOPES;
        if (!$this->originalAllowed($retailer)) {
            $scopes = array_values(array_diff($scopes, ['media:original']));
        }
        return $scopes;
    }

    /** @param array<string, mixed> $retailer @return array<string, mixed> */
    public function grant(array $retailer): array
    {
        return [
            'id' => $retailer['grant_id'],
            'retailer' => ['id' => $retailer['id'], 'name' => $retailer['name']],
            'brand_ids' => self::brandIds($retailer),
            'scopes' => $this->scopes($retailer),
            'original_allowed' => $this->originalAllowed($retailer),
            'status' => $retailer['status'] === 'active' ? 'active' : 'revoked',
            'updated_at' => $retailer['grant_updated_at'],
        ];
    }

    /**
     * Viewer for an API client of $retailer with the token's scopes.
     *
     * @param array<string, mixed> $retailer
     * @param list<string> $scopes
     */
    public function apiViewer(array $retailer, array $scopes, string $clientId, string $clientName): Viewer
    {
        $orig = $this->originalAllowed($retailer) && in_array('media:original', $scopes, true);
        return new Viewer((string) $retailer['id'], self::brandIds($retailer), $orig, 'c:' . $clientId, false, (string) $retailer['name'], $clientName);
    }

    /** @param array<string, mixed> $retailer */
    public function portalViewer(array $retailer, string $userId, string $userName): Viewer
    {
        return new Viewer((string) $retailer['id'], self::brandIds($retailer), $this->originalAllowed($retailer), 'v:' . $userId, false, (string) $retailer['name'], $userName);
    }

    /** Records a grant change for one retailer (resync = visible set changed). */
    public function touch(string $retailerId, bool $resync = true): void
    {
        $now = Clock::iso();
        $this->db->run('UPDATE obx_retailers SET grant_updated_at = ? WHERE id = ?', [$now, $retailerId]);
        $r = $this->db->one('SELECT grant_id FROM obx_retailers WHERE id = ?', [$retailerId]);
        if ($r !== null) {
            $this->log->grant((string) $r['grant_id'], $retailerId, $now, $resync);
        }
    }

    /** Grant entries for every retailer (the brand toggled original downloads). */
    public function touchAll(bool $resync = true): void
    {
        foreach ($this->db->column("SELECT id FROM obx_retailers WHERE status = 'active'") as $id) {
            $this->touch((string) $id, $resync);
        }
    }
}
