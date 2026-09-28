<?php

declare(strict_types=1);

namespace Obx\Node\Services;

use Obx\Node\App;
use Obx\Node\Config;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Json;

/**
 * GDPR requests: a machine-readable copy of everything the node stores about a retailer
 * (company record, accounts, API keys without secrets, access requests, downloads, audit
 * entries) or about one account. Deletion is DELETE /admin/api/retailers/{id} or
 * /admin/api/users/{id}; see Retailers::delete and forgetUser().
 */
final class Privacy
{
    public function __construct(private App $app)
    {
    }

    /** @return array<string, mixed> */
    public function retailerExport(string $retailerId): array
    {
        $db = $this->app->db;
        $svc = new Retailers($this->app);
        $retailer = $svc->json($svc->get($retailerId));
        $users = $db->all('SELECT * FROM obx_users WHERE retailer_id = ? ORDER BY created_at', [$retailerId]);
        $clients = $db->all('SELECT * FROM obx_api_clients WHERE retailer_id = ? ORDER BY created_at', [$retailerId]);
        $clientIds = array_map(static fn ($c) => (string) $c['id'], $clients);
        $webhooks = [];
        foreach ($clientIds as $cid) {
            foreach ($db->all("SELECT id, url, events, status, created_at, last_delivery_at FROM obx_webhooks WHERE owner_type = 'client' AND owner_id = ?", [$cid]) as $w) {
                $w['events'] = Json::decode((string) $w['events'], []);
                $webhooks[] = $w;
            }
        }
        $requests = $db->all('SELECT id, status, input, language, created_at, decided_at, reason FROM obx_access_requests WHERE retailer_id = ? ORDER BY created_at', [$retailerId]);
        $userIds = array_map(static fn ($u) => (string) $u['id'], $users);
        return [
            'format' => 'obx-personal-data-export/1',
            'exported_at' => Clock::iso(),
            'node' => ['software' => Config::SOFTWARE, 'version' => Config::VERSION, 'public_url' => $this->app->publicUrl()],
            'controller' => $this->controller(),
            'retention' => $this->app->retention->periods(),
            'retailer' => $retailer,
            'accounts' => array_map(fn (array $u): array => $this->account($u), $users),
            'api_clients' => array_map(static fn (array $c): array => [
                'id' => $c['id'],
                'client_id' => $c['client_id'],
                'name' => $c['name'],
                'scopes' => Json::decode((string) $c['scopes'], []),
                'status' => $c['status'],
                'created_at' => $c['created_at'],
                'last_used_at' => $c['last_used_at'],
            ], $clients),
            'webhooks' => $webhooks,
            'access_requests' => array_map(static fn (array $r): array => [
                'id' => $r['id'],
                'status' => $r['status'],
                'request' => Json::decode((string) $r['input'], []),
                'language' => $r['language'],
                'created_at' => $r['created_at'],
                'decided_at' => $r['decided_at'],
                'reason' => $r['reason'],
            ], $requests),
            'downloads' => $this->downloads('retailer_id = ?', [$retailerId]),
            'selections' => $this->selections($userIds),
            'audit_log' => $this->auditEntries($userIds),
        ];
    }

    /** @return array<string, mixed> */
    public function userExport(string $userId): array
    {
        $db = $this->app->db;
        $u = $db->one('SELECT * FROM obx_users WHERE id = ?', [$userId]);
        if ($u === null) {
            throw new \Obx\Node\Http\ApiError('not_found', 'No such account.');
        }
        return [
            'format' => 'obx-personal-data-export/1',
            'exported_at' => Clock::iso(),
            'node' => ['software' => Config::SOFTWARE, 'version' => Config::VERSION, 'public_url' => $this->app->publicUrl()],
            'controller' => $this->controller(),
            'retention' => $this->app->retention->periods(),
            'account' => $this->account($u),
            'retailer' => $u['retailer_id'] !== null ? $db->one('SELECT id, name, reference, website, country, created_at FROM obx_retailers WHERE id = ?', [$u['retailer_id']]) : null,
            'downloads' => $this->downloads('actor_id = ?', [$userId]),
            'selections' => $this->selections([$userId]),
            'audit_log' => $this->auditEntries([$userId]),
        ];
    }

    /**
     * Removes an account's personal data from the records that outlive it: download
     * statistics keep the retailer only, audit entries keep the action and a pseudonymous id.
     */
    public function forgetUser(string $userId): void
    {
        $db = $this->app->db;
        $db->run('UPDATE obx_download_events SET actor_name = NULL, actor_id = NULL WHERE actor_id = ?', [$userId]);
        $db->run('UPDATE obx_audit_log SET actor_name = NULL, actor_email = NULL, ip = NULL WHERE actor_id = ?', [$userId]);
        $db->delete('obx_selections', ['user_id' => $userId]);
    }

    /** The brand as the controller of the data (GDPR art. 4(7)). @return array<string, mixed> */
    private function controller(): array
    {
        $company = $this->app->settings->all()['company'];
        return ['name' => $company['name'] ?? null, 'website' => $company['website'] ?? null, 'contact_email' => $company['contact_email'] ?? null];
    }

    /** @param array<string, mixed> $u @return array<string, mixed> */
    private function account(array $u): array
    {
        return [
            'id' => $u['id'],
            'email' => $u['email'],
            'name' => $u['name'],
            'role' => $u['role'],
            'status' => $u['status'],
            'language' => $u['language'],
            'created_at' => $u['created_at'],
            'last_login_at' => $u['last_login_at'],
            'password_set' => $u['password_hash'] !== null,
            'sessions' => $this->app->db->all('SELECT created_ts, seen_ts, expires_ts FROM obx_sessions WHERE user_id = ?', [$u['id']]),
        ];
    }

    /** @param list<mixed> $params @return list<array<string, mixed>> */
    private function downloads(string $where, array $params): array
    {
        $rows = $this->app->db->all('SELECT * FROM obx_download_events WHERE ' . $where . ' ORDER BY at_ts', $params);
        return array_map(static fn (array $e): array => Stats::event($e), $rows);
    }

    /** @param list<string> $userIds @return list<array<string, mixed>> */
    private function selections(array $userIds): array
    {
        $out = [];
        foreach ($userIds as $uid) {
            $s = $this->app->db->one('SELECT * FROM obx_selections WHERE user_id = ?', [$uid]);
            if ($s !== null) {
                $out[] = ['user_id' => $uid, 'product_ids' => Json::decode((string) $s['product_ids'], []), 'updated_at' => $s['updated_at']];
            }
        }
        return $out;
    }

    /** @param list<string> $userIds @return list<array<string, mixed>> */
    private function auditEntries(array $userIds): array
    {
        $out = [];
        foreach ($userIds as $uid) {
            foreach ($this->app->db->all('SELECT * FROM obx_audit_log WHERE actor_id = ? ORDER BY id', [$uid]) as $r) {
                $out[] = Audit::json($r);
            }
        }
        return $out;
    }
}
