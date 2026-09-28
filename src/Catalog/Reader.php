<?php

declare(strict_types=1);

namespace Obx\Node\Catalog;

use Obx\Node\Db\Db;
use Obx\Node\Security\Crypto;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Json;

/**
 * Read side: protocol objects as a viewer may see them, with signed rendition URLs.
 * Visibility comes from the reconciled `visible` flags (plus the rights expiry check).
 */
final class Reader
{
    public string $baseUrl = 'http://localhost';

    public function __construct(private Db $db, private Crypto $crypto)
    {
    }

    // ---------------------------------------------------------------- brands

    /** @return list<array<string, mixed>> stored rows of brands the viewer sees */
    public function brandRows(Viewer $v): array
    {
        $rows = $this->db->all('SELECT * FROM obx_brands WHERE visible = 1 ORDER BY id');
        if ($v->brandIds !== null) {
            $rows = array_values(array_filter($rows, static fn ($r) => in_array($r['id'], $v->brandIds, true)));
        }
        return $rows;
    }

    /** @return list<string> */
    public function brandIds(Viewer $v): array
    {
        return array_map(static fn ($r) => (string) $r['id'], $this->brandRows($v));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function brand(array $row): array
    {
        $rep = Json::decode((string) $row['rep'], []);
        $rep['updated_at'] = $row['updated_at'];
        return $rep;
    }

    // ---------------------------------------------------------------- products

    /** @param array<string, mixed> $row @return array<string, mixed> product without arrays */
    public function product(array $row): array
    {
        $rep = Json::decode((string) $row['rep'], []);
        $rep['updated_at'] = $row['updated_at'];
        return $rep;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function variant(array $row): array
    {
        $rep = Json::decode((string) $row['rep'], []);
        $rep['updated_at'] = $row['updated_at'];
        return $rep;
    }

    /** @return array<string, mixed>|null the product row when visible to $v */
    public function visibleProductRow(string $id, Viewer $v): ?array
    {
        $row = $this->db->one('SELECT * FROM obx_products WHERE id = ? AND visible = 1', [$id]);
        if ($row === null || ($v->brandIds !== null && !in_array($row['brand_id'], $v->brandIds, true))) {
            return null;
        }
        return $row;
    }

    /**
     * ProductDetail for several visible product rows (variants and assets embedded).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function details(array $rows, Viewer $v): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn ($r) => (string) $r['id'], $rows);
        $variants = [];
        $assets = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->db->all('SELECT * FROM obx_variants WHERE visible = 1 AND product_id IN (' . Db::in($chunk) . ')', $chunk) as $r) {
                $variants[$r['product_id']][] = $r;
            }
            $params = array_merge($chunk, [Clock::time()]);
            foreach ($this->db->all('SELECT * FROM obx_assets WHERE visible = 1 AND product_id IN (' . Db::in($chunk) . ') AND (expires_ts IS NULL OR expires_ts > ?)', $params) as $r) {
                $assets[$r['product_id']][] = $r;
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $p = $this->product($row);
            $vs = $variants[$row['id']] ?? [];
            usort($vs, static fn ($a, $b) => strcmp((string) $a['external_id'], (string) $b['external_id']));
            $p['variants'] = array_map(fn ($r) => $this->variant($r), $vs);
            $as = $assets[$row['id']] ?? [];
            usort($as, [Writer::class, 'assetOrderCmp']);
            $p['assets'] = array_map(fn ($r) => $this->asset($r, $v), $as);
            $out[] = $p;
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function detail(string $id, Viewer $v): ?array
    {
        $row = $this->visibleProductRow($id, $v);
        return $row === null ? null : $this->details([$row], $v)[0];
    }

    // ---------------------------------------------------------------- assets

    /** @return array<string, mixed>|null visible asset row */
    public function visibleAssetRow(string $id, Viewer $v): ?array
    {
        $row = $this->db->one(
            'SELECT a.*, p.brand_id AS brand_id FROM obx_assets a JOIN obx_products p ON p.id = a.product_id
             WHERE a.id = ? AND a.visible = 1 AND (a.expires_ts IS NULL OR a.expires_ts > ?)',
            [$id, Clock::time()]
        );
        if ($row === null || ($v->brandIds !== null && !in_array($row['brand_id'], $v->brandIds, true))) {
            return null;
        }
        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function asset(array $row, Viewer $v): array
    {
        $rep = Json::decode((string) $row['rep'], []);
        $rep['updated_at'] = $row['updated_at'];
        $renditions = [];
        foreach ((array) ($rep['renditions'] ?? []) as $name => $r) {
            if ($name === 'original' && !$v->originalAllowed) {
                continue;
            }
            [$url, $exp] = $this->renditionUrl((string) $row['id'], (string) $name, $v->actor);
            $renditions[$name] = [
                'name' => $name,
                'url' => $url,
                'url_expires_at' => $exp,
                'content_type' => $r['content_type'],
                'width' => $r['width'],
                'height' => $r['height'],
                'bytes' => $r['bytes'],
                'sha256' => $r['sha256'],
            ];
        }
        $rep['renditions'] = $renditions;
        // Keep the schema's property order: renditions then updated_at.
        $updated = $rep['updated_at'];
        unset($rep['updated_at']);
        $rep['updated_at'] = $updated;
        return $rep;
    }

    // ---------------------------------------------------------------- signed URLs

    public static function hourExpiry(): int
    {
        return (intdiv(Clock::time(), 3600) + 2) * 3600;
    }

    /**
     * Signed rendition URL. Actors: "c:<client>" (API client, downloads counted),
     * "v:<user>" (portal display, not counted), "d:<user>" (portal download, counted),
     * "a:<admin>" (admin catalog preview, also unpublished files).
     *
     * @return array{0: string, 1: string}
     */
    public function renditionUrl(string $assetId, string $name, string $actor): array
    {
        $exp = self::hourExpiry();
        $path = '/obx/v1/assets/' . rawurlencode($assetId) . '/renditions/' . $name;
        $sig = $this->crypto->sign('url', "GET\n" . $path . "\n" . $exp . "\n" . $actor);
        return [$this->baseUrl . $path . '?exp=' . $exp . '&act=' . rawurlencode($actor) . '&sig=' . $sig, Clock::iso((float) $exp)];
    }

    /** Verifies a signed URL; returns the actor or null. */
    public function verifySigned(string $path, ?string $exp, ?string $actor, ?string $sig): ?string
    {
        if ($exp === null || $actor === null || $sig === null || !ctype_digit($exp) || (int) $exp < Clock::time()) {
            return null;
        }
        if (!$this->crypto->verify('url', "GET\n" . $path . "\n" . $exp . "\n" . $actor, $sig)) {
            return null;
        }
        return $actor;
    }

    /** Generic signed path (exports, download jobs). @return array{0: string, 1: string} */
    public function signedPath(string $path, string $actor, ?int $exp = null): array
    {
        $exp ??= self::hourExpiry();
        $sig = $this->crypto->sign('url', "GET\n" . $path . "\n" . $exp . "\n" . $actor);
        return [$this->baseUrl . $path . '?exp=' . $exp . '&act=' . rawurlencode($actor) . '&sig=' . $sig, Clock::iso((float) $exp)];
    }
}
