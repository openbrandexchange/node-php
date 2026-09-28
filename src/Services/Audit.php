<?php

declare(strict_types=1);

namespace Obx\Node\Services;

use Obx\Node\App;
use Obx\Node\Http\ApiError;
use Obx\Node\Http\Request;
use Obx\Node\Http\Response;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Json;

/**
 * Audit log of admin actions: who changed what, when, from which address.
 *
 * Every state-changing admin request (settings, catalog edits, imports, retailers, access
 * decisions, accounts, keys, webhooks) is recorded after it ran, with the route, the ids in the
 * path and the names (never the values) of the fields that were sent. Logins (also failed
 * ones), logouts, first-run setup and password resets are recorded as well, and a retailer's
 * self-service API keys. Secrets never reach the log. Addresses are removed after
 * `retention.ip_days` and entries after `retention.audit_log_days` (Retention).
 */
final class Audit
{
    /** Routes recorded for any caller (security events). */
    private const ALWAYS = [
        'POST /admin/api/setup',
        'POST /admin/api/session/login',
        'POST /admin/api/session/logout',
        'POST /admin/api/password/forgot',
        'POST /admin/api/password/reset',
        'POST /admin/api/invites/{token}/accept',
        'PATCH /admin/api/me',
        'POST /admin/api/portal/api-clients',
        'POST /admin/api/portal/api-clients/{id}/rotate',
        'DELETE /admin/api/portal/api-clients/{id}',
    ];

    /** Retailer portal actions that are not admin actions (selections, lookups, downloads). */
    private const NEVER_PREFIX = '/admin/api/portal/';

    public function __construct(private App $app)
    {
    }

    /** Records the request if it is an audited action. Never throws. */
    public function afterRequest(Request $req, Response $res): void
    {
        try {
            $route = $req->attrs['route'] ?? null;
            if (!is_string($route) || !in_array($req->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) || !str_starts_with($req->path, '/admin/api/')) {
                return;
            }
            $key = $req->method . ' ' . $route;
            $always = in_array($key, self::ALWAYS, true);
            $session = $req->attrs['_session'] ?? null;
            $user = $req->attrs['_login_user'] ?? ($session['user'] ?? null);
            if (!$always) {
                if (str_starts_with($route, self::NEVER_PREFIX) || !is_array($user) || ($user['role'] ?? '') !== 'admin') {
                    return;
                }
                if ($res->status === 401 || $res->status === 403) {
                    return; // refused before anything happened (not logged in, CSRF): not an action
                }
            }
            $detail = [];
            if ($req->params !== []) {
                $detail['params'] = array_map(static fn ($v) => str_starts_with((string) $v, 'obx') || strlen((string) $v) > 60 ? '…' : $v, $req->params);
                unset($detail['params']['token']);
                if ($detail['params'] === []) {
                    unset($detail['params']);
                }
            }
            if (isset($req->attrs['_json_keys']) && $req->attrs['_json_keys'] !== []) {
                $detail['fields'] = $req->attrs['_json_keys'];
            }
            if ($res->status >= 400) {
                $p = Json::decode($res->body, []);
                if (is_array($p) && isset($p['obx_error'])) {
                    $detail['error'] = $p['obx_error'];
                } elseif (is_array($p) && isset($p['error'])) {
                    $detail['error'] = $p['error'];
                }
            }
            $actorName = is_array($user) ? (string) ($user['email'] ?? '') : ($req->attrs['_login_email'] ?? null);
            $this->record(
                is_array($user) ? ($user['role'] === 'admin' ? 'admin' : 'retailer_user') : 'anonymous',
                is_array($user) ? (string) $user['id'] : null,
                $actorName !== '' ? $actorName : null,
                $key,
                self::targetType($route),
                self::targetId($req->params),
                $res->status,
                $detail,
                $req->ip,
                $this->app->log->requestId,
            );
        } catch (\Throwable $e) {
            $this->app->log->error('Writing the audit log failed: ' . $e->getMessage(), $e);
        }
    }

    /** @param array<string, mixed> $detail */
    public function record(string $actorType, ?string $actorId, ?string $actorName, string $action, ?string $targetType, ?string $targetId, ?int $status, array $detail = [], ?string $ip = null, ?string $requestId = null): void
    {
        $this->app->db->insert('obx_audit_log', [
            'at' => Clock::iso(),
            'at_ts' => Clock::time(),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_name' => $actorName !== null ? mb_substr($actorName, 0, 200, 'UTF-8') : null,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'status' => $status,
            'detail' => $detail !== [] ? Json::encode($detail) : null,
            'ip' => $ip,
            'request_id' => $requestId,
        ]);
    }

    private static function targetType(string $route): ?string
    {
        $parts = explode('/', trim(substr($route, strlen('/admin/api/')), '/'));
        if ($parts[0] === 'portal' && isset($parts[1])) {
            return 'portal.' . $parts[1];
        }
        if ($parts[0] === 'catalog' && isset($parts[1])) {
            return rtrim($parts[1], 's');
        }
        return $parts[0] !== '' ? $parts[0] : null;
    }

    /** @param array<string, string> $params */
    private static function targetId(array $params): ?string
    {
        foreach (['id', 'external_id', 'sha256'] as $k) {
            if (isset($params[$k])) {
                return mb_substr($params[$k], 0, 200, 'UTF-8');
            }
        }
        return null;
    }

    /**
     * Lists entries, newest first. Filters: actor_id, action (substring), since (ISO date).
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function list(array $filters, int $limit, ?string $cursor): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['actor_id'])) {
            $where[] = 'actor_id = ?';
            $params[] = (string) $filters['actor_id'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'action LIKE ?';
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['action']) . '%';
        }
        if (!empty($filters['since'])) {
            $ts = strtotime((string) $filters['since']);
            if ($ts === false) {
                throw ApiError::validation('/since', 'A date such as 2026-09-01.');
            }
            $where[] = 'at_ts >= ?';
            $params[] = $ts;
        }
        if ($cursor !== null && $cursor !== '') {
            if (!ctype_digit($cursor)) {
                throw new ApiError('cursor_invalid', 'This cursor is not valid.');
            }
            $where[] = 'id < ?';
            $params[] = (int) $cursor;
        }
        $sql = 'SELECT * FROM obx_audit_log' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . ($limit + 1);
        $rows = $this->app->db->all($sql, $params);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [
            'items' => array_map(static fn (array $r): array => [
                'id' => (string) $r['id'],
                'at' => $r['at'],
                'actor' => ['type' => $r['actor_type'], 'id' => $r['actor_id'], 'name' => $r['actor_name']],
                'action' => $r['action'],
                'target' => $r['target_type'] !== null ? ['type' => $r['target_type'], 'id' => $r['target_id']] : null,
                'status' => $r['status'] !== null ? (int) $r['status'] : null,
                'detail' => $r['detail'] !== null ? Json::decode((string) $r['detail']) : null,
                'ip' => $r['ip'],
                'request_id' => $r['request_id'],
            ], $rows),
            'next_cursor' => $more && $rows !== [] ? (string) end($rows)['id'] : null,
        ];
    }
}
