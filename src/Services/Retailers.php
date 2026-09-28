<?php

declare(strict_types=1);

namespace Obx\Node\Services;

use Obx\Node\App;
use Obx\Node\Http\ApiError;
use Obx\Node\Security\Crypto;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Ids;
use Obx\Node\Support\Json;

/**
 * Retailers, their grants and their API clients ("API keys for your software").
 */
final class Retailers
{
    public function __construct(private App $app)
    {
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    public function json(array $r): array
    {
        $db = $this->app->db;
        return [
            'id' => $r['id'],
            'name' => $r['name'],
            'reference' => $r['reference'],
            'website' => $r['website'],
            'vat_number' => $r['vat_number'],
            'country' => $r['country'],
            'notes' => $r['notes'],
            'status' => $r['status'],
            'brand_ids' => Grants::brandIds($r),
            'allow_original' => $r['allow_original'] === null ? null : (bool) $r['allow_original'],
            'user_count' => (int) $db->value('SELECT COUNT(*) FROM obx_users WHERE retailer_id = ?', [$r['id']]),
            'api_client_count' => (int) $db->value("SELECT COUNT(*) FROM obx_api_clients WHERE retailer_id = ? AND status = 'active'", [$r['id']]),
            'last_activity_at' => $r['last_activity_at'],
            'created_at' => $r['created_at'],
        ];
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        $r = $this->app->db->one('SELECT * FROM obx_retailers WHERE id = ?', [$id]);
        if ($r === null) {
            throw new ApiError('not_found', 'No such retailer.');
        }
        return $r;
    }

    /**
     * Validates RetailerInput fields present in $in.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed> columns
     */
    private function columns(array $in, bool $create): array
    {
        $out = [];
        $str = static function (string $k, int $max) use ($in, &$out): void {
            if (!array_key_exists($k, $in)) {
                return;
            }
            $v = $in[$k];
            if ($v === null || (is_string($v) && trim($v) === '')) {
                $out[$k] = null;
                return;
            }
            if (!is_string($v) || mb_strlen($v, 'UTF-8') > $max) {
                throw ApiError::validation('/' . $k, 'At most ' . $max . ' characters.');
            }
            $out[$k] = trim($v);
        };
        if ($create || array_key_exists('name', $in)) {
            $name = $in['name'] ?? null;
            if (!is_string($name) || trim($name) === '' || mb_strlen($name, 'UTF-8') > 200) {
                throw ApiError::validation('/name', 'Enter the retailer\'s name.');
            }
            $out['name'] = trim($name);
        }
        $str('reference', 100);
        $str('website', 2048);
        $str('vat_number', 32);
        $str('notes', 5000);
        if (array_key_exists('country', $in)) {
            $c = $in['country'];
            if ($c !== null && (!is_string($c) || !preg_match('/^[A-Z]{2}$/', $c))) {
                throw ApiError::validation('/country', 'Two capital letters, e.g. NL.');
            }
            $out['country'] = $c;
        }
        if (array_key_exists('status', $in)) {
            if (!in_array($in['status'], ['active', 'blocked'], true)) {
                throw ApiError::validation('/status', 'active or blocked.');
            }
            $out['status'] = $in['status'];
        }
        if (array_key_exists('brand_ids', $in)) {
            $ids = $in['brand_ids'];
            if ($ids !== null) {
                if (!is_array($ids) || !array_is_list($ids)) {
                    throw ApiError::validation('/brand_ids', 'A list of brand ids, or null for all brands.');
                }
                $ids = array_values(array_unique(array_map('strval', $ids)));
                sort($ids);
                foreach ($ids as $id) {
                    if ($this->app->db->value('SELECT id FROM obx_brands WHERE id = ?', [$id]) === null) {
                        throw ApiError::validation('/brand_ids', 'Unknown brand ' . $id . '.');
                    }
                }
            }
            $out['brand_ids'] = $ids === null ? null : Json::encode($ids);
        }
        if (array_key_exists('allow_original', $in)) {
            $v = $in['allow_original'];
            if ($v !== null && !is_bool($v)) {
                throw ApiError::validation('/allow_original', 'true, false or null.');
            }
            $out['allow_original'] = $v === null ? null : ($v ? 1 : 0);
        }
        return $out;
    }

    /** @param array<string, mixed> $in @return array<string, mixed> row */
    public function create(array $in): array
    {
        $cols = $this->columns($in, true);
        $now = Clock::iso();
        $row = $cols + [
            'id' => Ids::new('ret'),
            'reference' => null,
            'website' => null,
            'vat_number' => null,
            'country' => null,
            'notes' => null,
            'status' => 'active',
            'brand_ids' => null,
            'allow_original' => null,
            'grant_id' => Ids::new('gnt'),
            'grant_updated_at' => $now,
            'last_activity_at' => null,
            'created_at' => $now,
        ];
        $this->app->db->insert('obx_retailers', $row);
        return $this->get((string) $row['id']);
    }

    /** @param array<string, mixed> $patch @return array<string, mixed> row */
    public function update(string $id, array $patch): array
    {
        return $this->app->db->tx(function () use ($id, $patch) {
            $before = $this->get($id);
            $cols = $this->columns($patch, false);
            if ($cols !== []) {
                $this->app->db->update('obx_retailers', $cols, ['id' => $id]);
            }
            $after = $this->get($id);
            $grantChanged = $before['brand_ids'] !== $after['brand_ids'] || $before['allow_original'] !== $after['allow_original'];
            if ($before['status'] !== $after['status']) {
                if ($after['status'] === 'blocked') {
                    // Tokens stay so the next API call answers access_revoked (not invalid_token).
                    $this->app->auth->endRetailerSessions($id);
                }
                $grantChanged = true;
            }
            if ($grantChanged) {
                $visibleChanged = $before['brand_ids'] !== $after['brand_ids'] || $before['allow_original'] !== $after['allow_original'] || $after['status'] === 'active';
                $this->app->grants->touch($id, $visibleChanged);
                $this->app->webhooks->grantUpdated($id);
            }
            return $this->get($id);
        });
    }

    public function delete(string $id): void
    {
        $this->app->db->tx(function () use ($id) {
            $this->get($id);
            $this->app->auth->endRetailerSessions($id);
            $clients = $this->app->db->column('SELECT id FROM obx_api_clients WHERE retailer_id = ?', [$id]);
            foreach ($clients as $cid) {
                $this->app->db->delete('obx_access_tokens', ['client_id' => $cid]);
                $this->app->db->run("DELETE FROM obx_webhooks WHERE owner_type = 'client' AND owner_id = ?", [$cid]);
            }
            $this->app->db->delete('obx_api_clients', ['retailer_id' => $id]);
            $privacy = new Privacy($this->app);
            foreach ($this->app->db->column('SELECT id FROM obx_users WHERE retailer_id = ?', [$id]) as $uid) {
                $privacy->forgetUser((string) $uid);
            }
            $this->app->db->delete('obx_users', ['retailer_id' => $id]);
            // Statistics keep the retailer name only (no accounts, no personal data).
            $this->app->db->run("UPDATE obx_download_events SET actor_name = NULL, actor_id = NULL WHERE retailer_id = ?", [$id]);
            // Access requests hold the contact person's name and e-mail address.
            $this->app->db->delete('obx_access_requests', ['retailer_id' => $id]);
            $this->app->db->delete('obx_retailers', ['id' => $id]);
        });
    }

    // ---------------------------------------------------------------- API clients

    /** @param array<string, mixed> $c @return array<string, mixed> */
    public static function clientJson(array $c): array
    {
        return [
            'id' => $c['id'],
            'client_id' => $c['client_id'],
            'name' => $c['name'],
            'retailer_id' => $c['retailer_id'],
            'scopes' => Json::decode((string) $c['scopes'], []),
            'status' => $c['status'],
            'created_at' => $c['created_at'],
            'last_used_at' => $c['last_used_at'],
        ];
    }

    /**
     * @param list<string>|null $scopes
     * @return array{row: array<string, mixed>, secret: string}
     */
    public function createClient(string $retailerId, string $name, ?array $scopes): array
    {
        $retailer = $this->get($retailerId);
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 200) {
            throw ApiError::validation('/name', 'Give the key a name, e.g. "Webshop".');
        }
        $allowed = $this->app->grants->scopes($retailer);
        if ($scopes === null) {
            // node-admin.yaml ApiClientCreate: catalog:read and media:read, plus media:original when
            // the retailer may download originals; usage:write only when asked for explicitly.
            $scopes = array_values(array_intersect($allowed, ['catalog:read', 'media:read', 'media:original']));
        } else {
            $scopes = array_values(array_unique($scopes));
            if ($scopes === [] || array_diff($scopes, Grants::ALL_SCOPES) !== []) {
                throw ApiError::validation('/scopes', 'Scopes: ' . implode(', ', Grants::ALL_SCOPES) . '.');
            }
            if (array_diff($scopes, $allowed) !== []) {
                throw ApiError::validation('/scopes', 'Not allowed for this retailer: ' . implode(', ', array_diff($scopes, $allowed)) . '.');
            }
        }
        $secret = Crypto::token('obxs_', 32);
        $row = [
            'id' => Ids::new('cli'),
            'client_id' => Crypto::token('obxc_', 16),
            'secret_hash' => Crypto::hashToken($secret),
            'name' => $name,
            'retailer_id' => $retailerId,
            'scopes' => Json::encode(array_values(array_intersect(Grants::ALL_SCOPES, $scopes))),
            'status' => 'active',
            'created_at' => Clock::iso(),
            'last_used_at' => null,
        ];
        $this->app->db->insert('obx_api_clients', $row);
        return ['row' => $row, 'secret' => $secret];
    }

    /** @param array{row: array<string, mixed>, secret: string} $c @return array<string, mixed> */
    public function withSecret(array $c): array
    {
        $base = $this->app->baseUrl();
        return [
            'client' => self::clientJson($c['row']),
            'client_secret' => $c['secret'],
            'token_endpoint' => $base . '/obx/v1/oauth/token',
            'discovery_url' => $base . '/.well-known/obx',
        ];
    }

    /** @return array{row: array<string, mixed>, secret: string} */
    public function rotateClient(string $id, ?string $retailerId = null): array
    {
        $c = $this->client($id, $retailerId);
        if ($c['status'] !== 'active') {
            throw new ApiError('conflict', 'This key was revoked; create a new one.');
        }
        $secret = Crypto::token('obxs_', 32);
        $this->app->db->update('obx_api_clients', ['secret_hash' => Crypto::hashToken($secret)], ['id' => $id]);
        $this->app->db->delete('obx_access_tokens', ['client_id' => $id]);
        // A key made on approval waits for the requester's software. Once it is renewed (the brand
        // hands it over itself, e.g. when the retailer asked on the web form), that waiting secret
        // no longer works, so a later poll of the access request must not hand it out.
        $this->app->db->run('UPDATE obx_access_requests SET claimed = 1, secret_enc = NULL WHERE api_client_id = ? AND secret_enc IS NOT NULL', [$id]);
        return ['row' => $this->client($id, $retailerId), 'secret' => $secret];
    }

    public function revokeClient(string $id, ?string $retailerId = null): void
    {
        $this->client($id, $retailerId);
        $this->app->db->update('obx_api_clients', ['status' => 'revoked'], ['id' => $id]);
    }

    /** @return array<string, mixed> */
    public function client(string $id, ?string $retailerId = null): array
    {
        $c = $this->app->db->one('SELECT * FROM obx_api_clients WHERE id = ?', [$id]);
        if ($c === null || ($retailerId !== null && $c['retailer_id'] !== $retailerId)) {
            throw new ApiError('not_found', 'No such API key.');
        }
        return $c;
    }
}
