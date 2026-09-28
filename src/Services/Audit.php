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
 * Audit log: who changed what, when, with which outcome.
 *
 * Every state-changing admin API request is recorded after it ran (brand admins and retailer
 * users alike), plus reads that export personal data and every login attempt. Not recorded:
 * a retailer's own working state (selection, download jobs, EAN lookups; downloads are in the
 * statistics) and forgot-password requests. Entries hold the route pattern, the first id in
 * the path and the names (never the values) of the fields that were sent: no request bodies,
 * passwords, tokens or secrets. The client address is kept for at most `retention.ip_days`
 * (security.md §9), the entry for `retention.audit_log_days` (Retention).
 *
 * The JSON shape (GET /admin/api/audit-log) is the one every OBX portal uses: id, at,
 * actor {type, id, name, role, email}, action (e.g. retailers.update), method, route,
 * target_type, target_id, status, succeeded, request_id, ip, details.
 */
final class Audit
{
    private const NOT_AUDITED = '#^/admin/api/(portal/(selection|download-jobs|gtin-lookup)|password/forgot|session/login)#';

    public function __construct(private App $app)
    {
    }

    /** Records a finished admin API request when it is an audited action. Never throws. */
    public function afterRequest(Request $req, Response $res): void
    {
        try {
            $route = $req->attrs['route'] ?? null;
            if (!is_string($route) || !str_starts_with($route, '/admin/api/') || preg_match(self::NOT_AUDITED, $route)) {
                return;
            }
            if (in_array($req->method, ['GET', 'HEAD'], true) ? !str_ends_with($route, '/export') : $req->method === 'OPTIONS') {
                return;
            }
            $session = $req->attrs['_session'] ?? null;
            $user = $req->attrs['_login_user'] ?? ($session['user'] ?? null);
            [$action, $targetType] = self::action($req->method, $route);
            $details = [];
            if (isset($req->attrs['_json_keys']) && $req->attrs['_json_keys'] !== []) {
                $details['fields'] = $req->attrs['_json_keys'];
            }
            $details += self::error($res);
            $this->record([
                'user' => is_array($user) ? $user : null,
                'action' => $action,
                'method' => $req->method,
                'route' => $route,
                'target_type' => $targetType,
                'target_id' => self::target($req->params),
                'status' => $res->status,
                'details' => $details,
                'ip' => $req->ip,
            ]);
        } catch (\Throwable $e) {
            $this->app->log->error('Writing the audit log failed: ' . $e->getMessage(), $e);
        }
    }

    /**
     * A login attempt with its outcome (the failed ones carry no e-mail address, only the
     * account id when the address belongs to an account).
     *
     * @param array<string, mixed>|null $user
     */
    public function login(Request $req, ?array $user, int $status, ?string $reason = null): void
    {
        try {
            $this->record([
                'user' => $status < 400 ? $user : null,
                'action' => 'session.login',
                'method' => 'POST',
                'route' => '/admin/api/session/login',
                'target_type' => 'user',
                'target_id' => $user !== null ? (string) $user['id'] : null,
                'status' => $status,
                'details' => $reason !== null ? ['reason' => $reason] : [],
                'ip' => $req->ip,
            ]);
        } catch (\Throwable $e) {
            $this->app->log->error('Writing the audit log failed: ' . $e->getMessage(), $e);
        }
    }

    /** An action done on the command line (actor "cli"). */
    public function cli(string $action, ?string $targetType = null, ?string $targetId = null): void
    {
        $this->record(['user' => null, 'actor_type' => 'cli', 'action' => $action, 'method' => 'CLI', 'route' => 'php bin/obx ' . $action,
            'target_type' => $targetType, 'target_id' => $targetId, 'status' => 200, 'details' => [], 'ip' => null]);
    }

    /** @param array<string, mixed> $e */
    public function record(array $e): void
    {
        $user = $e['user'] ?? null;
        $details = (array) ($e['details'] ?? []);
        $this->app->db->insert('obx_audit_log', [
            'at' => Clock::iso(),
            'at_ts' => Clock::time(),
            'actor_type' => $e['actor_type'] ?? (is_array($user) ? 'user' : 'anonymous'),
            'actor_id' => is_array($user) ? (string) $user['id'] : null,
            'actor_name' => is_array($user) && isset($user['name']) ? mb_substr((string) $user['name'], 0, 200, 'UTF-8') : null,
            'actor_email' => is_array($user) && isset($user['email']) ? (string) $user['email'] : null,
            'actor_role' => is_array($user) && isset($user['role']) ? (string) $user['role'] : null,
            'action' => (string) $e['action'],
            'method' => (string) $e['method'],
            'route' => (string) $e['route'],
            'target_type' => $e['target_type'] ?? null,
            'target_id' => isset($e['target_id']) ? mb_substr((string) $e['target_id'], 0, 200, 'UTF-8') : null,
            'status' => (int) ($e['status'] ?? 200),
            'detail' => $details !== [] ? Json::encode($details) : null,
            'ip' => $e['ip'] ?? null,
            'request_id' => $this->app->log->requestId,
        ]);
    }

    /**
     * A stable action name from the route pattern, the same in every OBX portal:
     * "PATCH /admin/api/retailers/{id}" → retailers.update,
     * "POST /admin/api/access-requests/{id}/approve" → access-requests.approve,
     * "GET /admin/api/retailers/{id}/export" → retailers.export.
     *
     * @return array{0: string, 1: ?string} action, target type
     */
    public static function action(string $method, string $route): array
    {
        $segs = explode('/', trim(substr($route, strlen('/admin/api')), '/'));
        $plain = array_values(array_filter($segs, static fn (string $s) => !str_starts_with($s, '{')));
        $targetType = null;
        if ($plain !== []) {
            $targetType = $plain[0] === 'catalog' && isset($plain[1]) ? $plain[1] : $plain[0];
            $targetType = str_ends_with($targetType, 's') ? substr($targetType, 0, -1) : $targetType;
        }
        $special = ['session/login' => 'session.login', 'session/logout' => 'session.logout', 'setup' => 'setup.complete',
            'password/reset' => 'password.reset', 'password/forgot' => 'password.forgot'];
        if (isset($special[implode('/', $plain)])) {
            return [$special[implode('/', $plain)], 'user'];
        }
        $n = count($segs);
        if ($n >= 2 && !str_starts_with($segs[$n - 1], '{') && str_starts_with($segs[$n - 2], '{')) {
            return [implode('.', array_merge(array_slice($plain, 0, -1), [$segs[$n - 1]])), $targetType];
        }
        $verb = ['POST' => 'create', 'PUT' => 'update', 'PATCH' => 'update', 'DELETE' => 'delete', 'GET' => 'read', 'HEAD' => 'read'][$method] ?? strtolower($method);
        return [implode('.', array_merge($plain, [$verb])), $targetType];
    }

    /** The first id in the path; one-time tokens are never recorded. @param array<string, string> $params */
    private static function target(array $params): ?string
    {
        foreach ($params as $k => $v) {
            if ($k !== 'token' && $v !== '') {
                return $v;
            }
        }
        return null;
    }

    /** @return array<string, string> */
    private static function error(Response $res): array
    {
        if ($res->status < 400 || $res->file !== null || $res->stream !== null) {
            return [];
        }
        $p = Json::decode($res->body, []);
        $code = is_array($p) ? ($p['obx_error'] ?? $p['error'] ?? null) : null;
        return is_string($code) ? ['reason' => $code] : [];
    }

    /**
     * Newest first. Filters: action, actor_id, target_id (exact), outcome=succeeded|failed.
     *
     * @param array<string, ?string> $filters
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function list(array $filters, int $limit, ?string $cursor): array
    {
        $where = [];
        $params = [];
        foreach (['action', 'actor_id', 'target_id'] as $f) {
            if (($filters[$f] ?? '') !== '' && $filters[$f] !== null) {
                $where[] = $f . ' = ?';
                $params[] = (string) $filters[$f];
            }
        }
        $outcome = $filters['outcome'] ?? null;
        if ($outcome === 'failed') {
            $where[] = 'status >= 400';
        } elseif ($outcome === 'succeeded') {
            $where[] = 'status < 400';
        } elseif ($outcome !== null && $outcome !== '') {
            throw ApiError::validation('/outcome', 'succeeded or failed.');
        }
        if ($cursor !== null && $cursor !== '') {
            $state = $this->app->crypto->unseal('list:audit', $cursor);
            if (!is_array($state) || !isset($state['id']) || !is_int($state['id'])) {
                throw new ApiError('cursor_invalid', 'This cursor is not valid.');
            }
            $where[] = 'id < ?';
            $params[] = $state['id'];
        }
        $sql = 'SELECT * FROM obx_audit_log' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . ($limit + 1);
        $rows = $this->app->db->all($sql, $params);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return [
            'items' => array_map(static fn (array $r): array => self::json($r), $rows),
            'next_cursor' => $more && $rows !== [] ? $this->app->crypto->seal('list:audit', ['id' => (int) end($rows)['id']]) : null,
        ];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    public static function json(array $r): array
    {
        return [
            'id' => 'aud_' . $r['id'],
            'at' => $r['at'],
            'actor' => ['type' => $r['actor_type'], 'id' => $r['actor_id'], 'name' => $r['actor_name'], 'role' => $r['actor_role'], 'email' => $r['actor_email']],
            'action' => $r['action'],
            'method' => $r['method'],
            'route' => $r['route'],
            'target_type' => $r['target_type'],
            'target_id' => $r['target_id'],
            'status' => (int) $r['status'],
            'succeeded' => (int) $r['status'] < 400,
            'request_id' => $r['request_id'],
            'ip' => $r['ip'],
            'details' => $r['detail'] !== null ? Json::decode((string) $r['detail']) : null,
        ];
    }
}
