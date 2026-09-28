<?php

declare(strict_types=1);

namespace Obx\Node\Services;

use Obx\Node\App;
use Obx\Node\Db\Db;
use Obx\Node\Http\ApiError;
use Obx\Node\Security\Crypto;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Ids;
use Obx\Node\Support\Json;

/**
 * Webhooks for retailers' software (owner "client") and for the brand's own systems (owner
 * "admin"). Events are signed (HMAC-SHA256 over "<timestamp>.<body>"), delivered from an outbox
 * with exponential backoff for 24 hours; a subscription failing for 3 days is disabled.
 */
final class Webhooks
{
    public const RETRY_WINDOW = 86400;
    public const DISABLE_AFTER = 3 * 86400;

    public function __construct(private App $app)
    {
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function json(array $row, ?string $secret = null): array
    {
        return [
            'id' => $row['id'],
            'url' => $row['url'],
            'events' => Json::decode((string) $row['events'], []),
            'secret' => $secret,
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'last_delivery_at' => $row['last_delivery_at'],
            'last_status' => $row['last_status'] !== null ? (int) $row['last_status'] : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function list(string $ownerType, string $ownerId): array
    {
        $rows = $this->app->db->all('SELECT * FROM obx_webhooks WHERE owner_type = ? AND owner_id = ? ORDER BY created_at', [$ownerType, $ownerId]);
        return array_map(fn ($r) => $this->json($r), $rows);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $allowedEvents
     * @return array<string, mixed>
     */
    public function create(string $ownerType, string $ownerId, array $body, array $allowedEvents, ?int $max): array
    {
        $url = $body['url'] ?? null;
        if (!is_string($url) || strlen($url) > 2048 || !preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ApiError::validation('/url', 'An https:// address.');
        }
        $this->app->http->check($url, ['https']);
        $events = $body['events'] ?? null;
        if (!is_array($events) || $events === [] || !array_is_list($events)) {
            throw ApiError::validation('/events', 'Choose at least one event: ' . implode(', ', $allowedEvents) . '.');
        }
        $events = array_values(array_unique(array_map('strval', $events)));
        if (array_diff($events, $allowedEvents) !== []) {
            throw ApiError::validation('/events', 'Unknown events: ' . implode(', ', array_diff($events, $allowedEvents)) . '. Allowed: ' . implode(', ', $allowedEvents) . '.');
        }
        if ($max !== null && (int) $this->app->db->value('SELECT COUNT(*) FROM obx_webhooks WHERE owner_type = ? AND owner_id = ?', [$ownerType, $ownerId]) >= $max) {
            throw new ApiError('conflict', 'At most ' . $max . ' webhook subscriptions per client.');
        }
        $secret = Crypto::token('whsec_', 24);
        $row = [
            'id' => Ids::new('whk'),
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'url' => $url,
            'events' => Json::encode($events),
            'secret_enc' => $this->app->crypto->encrypt($secret),
            'status' => 'active',
            'created_at' => Clock::iso(),
            'last_delivery_at' => null,
            'last_status' => null,
            'failing_since_ts' => null,
            'notified_seq' => $this->app->changes->latestSeq(),
        ];
        $this->app->db->insert('obx_webhooks', $row);
        return $this->json($row, $secret);
    }

    /** @return array<string, mixed> */
    public function get(string $ownerType, string $ownerId, string $id): array
    {
        $row = $this->app->db->one('SELECT * FROM obx_webhooks WHERE id = ? AND owner_type = ? AND owner_id = ?', [$id, $ownerType, $ownerId]);
        if ($row === null) {
            throw new ApiError('not_found', 'No such webhook.');
        }
        return $row;
    }

    public function delete(string $ownerType, string $ownerId, string $id): void
    {
        $this->get($ownerType, $ownerId, $id);
        $this->app->db->delete('obx_webhooks', ['id' => $id]);
        $this->app->db->delete('obx_webhook_outbox', ['webhook_id' => $id]);
    }

    /** @param array<string, mixed> $patch @param list<string> $allowedEvents @return array<string, mixed> */
    public function patch(string $ownerType, string $ownerId, string $id, array $patch, array $allowedEvents): array
    {
        $row = $this->get($ownerType, $ownerId, $id);
        $set = [];
        if (array_key_exists('events', $patch)) {
            $events = $patch['events'];
            if (!is_array($events) || $events === [] || array_diff($events, $allowedEvents) !== []) {
                throw ApiError::validation('/events', 'Allowed events: ' . implode(', ', $allowedEvents) . '.');
            }
            $set['events'] = Json::encode(array_values(array_unique($events)));
        }
        if (array_key_exists('status', $patch)) {
            if (!in_array($patch['status'], ['active', 'disabled'], true)) {
                throw ApiError::validation('/status', 'active or disabled.');
            }
            $set['status'] = $patch['status'];
            if ($patch['status'] === 'active') {
                $set['failing_since_ts'] = null;
            }
        }
        if ($set !== []) {
            $this->app->db->update('obx_webhooks', $set, ['id' => $id]);
        }
        return $this->json($this->get($ownerType, $ownerId, $id));
    }

    /** @return array<string, mixed> WebhookDelivery */
    public function test(string $ownerType, string $ownerId, string $id): array
    {
        $row = $this->get($ownerType, $ownerId, $id);
        $event = $this->event('webhook.test', []);
        return $this->deliver($row, $event['id'], 'webhook.test', Json::encode($event), 1);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function event(string $type, array $data): array
    {
        return ['id' => Ids::new('evt'), 'type' => $type, 'created_at' => Clock::iso(), 'data' => Json::obj($data)];
    }

    /**
     * Queues an event for active subscriptions of an owner (or all admin subscriptions).
     *
     * @param array<string, mixed> $data
     */
    public function enqueue(string $ownerType, ?string $ownerId, string $type, array $data): void
    {
        $sql = "SELECT * FROM obx_webhooks WHERE owner_type = ? AND status = 'active'";
        $params = [$ownerType];
        if ($ownerId !== null) {
            $sql .= ' AND owner_id = ?';
            $params[] = $ownerId;
        }
        foreach ($this->app->db->all($sql, $params) as $hook) {
            if (!in_array($type, Json::decode((string) $hook['events'], []), true)) {
                continue;
            }
            $event = $this->event($type, $data);
            $this->app->db->insert('obx_webhook_outbox', [
                'id' => Ids::new('out'),
                'webhook_id' => $hook['id'],
                'event_id' => $event['id'],
                'event_type' => $type,
                'payload' => Json::encode($event),
                'attempts' => 0,
                'next_ts' => Clock::time(),
                'status' => 'pending',
                'created_ts' => Clock::time(),
            ]);
        }
    }

    /** Admin events (access_request.created, import_run.completed, ingest_job.completed). @param array<string, mixed> $data */
    public function admin(string $type, array $data): void
    {
        $this->enqueue('admin', null, $type, $data);
        $this->app->defer(fn () => $this->deliverDue(20, 20));
    }

    /** grant.updated for every client of a retailer. */
    public function grantUpdated(string $retailerId): void
    {
        $retailer = $this->app->db->one('SELECT * FROM obx_retailers WHERE id = ?', [$retailerId]);
        if ($retailer === null) {
            return;
        }
        $grant = $this->app->grants->grant($retailer);
        foreach ($this->app->db->column('SELECT id FROM obx_api_clients WHERE retailer_id = ?', [$retailerId]) as $cid) {
            $this->enqueue('client', (string) $cid, 'grant.updated', ['grant' => $grant]);
        }
    }

    /** changes.available for client subscriptions that have unseen entries in their grant. */
    public function notifyChanges(): void
    {
        $latest = $this->app->changes->latestSeq();
        $hooks = $this->app->db->all("SELECT * FROM obx_webhooks WHERE owner_type = 'client' AND status = 'active' AND notified_seq < ?", [$latest]);
        foreach ($hooks as $hook) {
            if (!in_array('changes.available', Json::decode((string) $hook['events'], []), true)) {
                $this->app->db->update('obx_webhooks', ['notified_seq' => $latest], ['id' => $hook['id']]);
                continue;
            }
            $client = $this->app->db->one('SELECT * FROM obx_api_clients WHERE id = ?', [$hook['owner_id']]);
            $retailer = $client !== null ? $this->app->db->one('SELECT * FROM obx_retailers WHERE id = ?', [$client['retailer_id']]) : null;
            if ($retailer === null || $retailer['status'] !== 'active' || $client['status'] !== 'active') {
                continue;
            }
            $brandIds = Grants::brandIds($retailer);
            $sql = 'SELECT COUNT(*) FROM obx_changes WHERE seq > ? AND seq <= ? AND ((type <> \'grant\'';
            $params = [(int) $hook['notified_seq'], $latest];
            if ($brandIds !== null) {
                $sql .= $brandIds === [] ? ' AND 1 = 0' : ' AND brand_id IN (' . Db::in($brandIds) . ')';
                $params = array_merge($params, $brandIds);
            }
            $sql .= ') OR (type = \'grant\' AND retailer_id = ?))';
            $params[] = $retailer['id'];
            if ((int) $this->app->db->value($sql, $params) > 0) {
                $this->enqueue('client', (string) $client['id'], 'changes.available', ['latest_seq' => $latest]);
            }
            $this->app->db->update('obx_webhooks', ['notified_seq' => $latest], ['id' => $hook['id']]);
        }
    }

    /** Sends due outbox events. Returns the number of attempts. */
    public function deliverDue(int $max = 50, int $budgetSeconds = 30): int
    {
        $start = microtime(true);
        $n = 0;
        $rows = $this->app->db->all("SELECT * FROM obx_webhook_outbox WHERE status = 'pending' AND next_ts <= ? ORDER BY next_ts LIMIT " . $max, [Clock::time()]);
        foreach ($rows as $o) {
            if (microtime(true) - $start > $budgetSeconds) {
                break;
            }
            $hook = $this->app->db->one('SELECT * FROM obx_webhooks WHERE id = ?', [$o['webhook_id']]);
            if ($hook === null || $hook['status'] !== 'active') {
                $this->app->db->delete('obx_webhook_outbox', ['id' => $o['id']]);
                continue;
            }
            $attempt = (int) $o['attempts'] + 1;
            $d = $this->deliver($hook, (string) $o['event_id'], (string) $o['event_type'], (string) $o['payload'], $attempt);
            $n++;
            if ($d['success']) {
                $this->app->db->delete('obx_webhook_outbox', ['id' => $o['id']]);
            } elseif (Clock::time() - (int) $o['created_ts'] > self::RETRY_WINDOW) {
                $this->app->db->update('obx_webhook_outbox', ['status' => 'failed', 'attempts' => $attempt], ['id' => $o['id']]);
            } else {
                $delay = min(6 * 3600, 30 * (2 ** min($attempt - 1, 12)));
                $this->app->db->update('obx_webhook_outbox', ['attempts' => $attempt, 'next_ts' => Clock::time() + $delay], ['id' => $o['id']]);
            }
        }
        return $n;
    }

    /**
     * One HTTP delivery attempt (no redirects), logged for the admin.
     *
     * @param array<string, mixed> $hook
     * @return array<string, mixed> WebhookDelivery
     */
    public function deliver(array $hook, string $eventId, string $type, string $body, int $attempt): array
    {
        $secret = (string) $this->app->crypto->decrypt((string) $hook['secret_enc']);
        $ts = (string) Clock::time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);
        $start = microtime(true);
        $status = null;
        $error = null;
        try {
            $r = $this->app->http->request('POST', (string) $hook['url'], [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'OBX-Webhook-Id' => $eventId,
                    'OBX-Webhook-Timestamp' => $ts,
                    'OBX-Webhook-Signature' => 'v1=' . $sig,
                ],
                'body' => $body,
                'timeout' => 10,
                'connect_timeout' => 5,
                'max_bytes' => 65536,
                'follow' => 0,
            ]);
            $status = $r['status'];
        } catch (\Throwable $e) {
            $error = $e instanceof ApiError ? $e->getMessage() : 'Connection failed.';
        }
        $ms = (int) round((microtime(true) - $start) * 1000);
        $success = $status !== null && $status >= 200 && $status < 300;
        $now = Clock::iso();
        $set = ['last_delivery_at' => $now, 'last_status' => $status];
        if ($success) {
            $set['failing_since_ts'] = null;
        } else {
            $since = $hook['failing_since_ts'] !== null ? (int) $hook['failing_since_ts'] : Clock::time();
            $set['failing_since_ts'] = $since;
            if (Clock::time() - $since > self::DISABLE_AFTER) {
                $set['status'] = 'disabled';
            }
            if ($error === null) {
                $error = 'The receiver answered HTTP ' . $status . '.';
            }
        }
        $this->app->db->update('obx_webhooks', $set, ['id' => $hook['id']]);
        $delivery = [
            'id' => Ids::new('dlv'),
            'webhook_id' => $hook['id'],
            'event_id' => $eventId,
            'event_type' => $type,
            'at' => $now,
            'at_ts' => Clock::time(),
            'status_code' => $status,
            'duration_ms' => $ms,
            'attempt' => $attempt,
            'success' => $success ? 1 : 0,
            'error' => $error,
        ];
        $this->app->db->insert('obx_webhook_deliveries', $delivery);
        return self::deliveryJson($delivery);
    }

    /** @param array<string, mixed> $d @return array<string, mixed> */
    public static function deliveryJson(array $d): array
    {
        return [
            'id' => $d['id'],
            'event_id' => $d['event_id'],
            'event_type' => $d['event_type'],
            'at' => $d['at'],
            'status_code' => $d['status_code'] !== null ? (int) $d['status_code'] : null,
            'duration_ms' => $d['duration_ms'] !== null ? (int) $d['duration_ms'] : null,
            'attempt' => (int) $d['attempt'],
            'success' => (bool) $d['success'],
            'error' => $d['error'],
        ];
    }

    public function cleanup(): void
    {
        $this->app->db->run('DELETE FROM obx_webhook_deliveries WHERE at_ts < ?', [Clock::time() - 7 * 86400]);
        $this->app->db->run("DELETE FROM obx_webhook_outbox WHERE status = 'failed' AND created_ts < ?", [Clock::time() - 7 * 86400]);
    }
}
