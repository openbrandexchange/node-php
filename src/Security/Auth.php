<?php

declare(strict_types=1);

namespace Obx\Node\Security;

use Obx\Node\App;
use Obx\Node\Http\ApiError;
use Obx\Node\Http\Request;
use Obx\Node\Http\Response;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Json;

/**
 * Authentication for the three faces: admin/portal sessions (cookie + CSRF), protocol API
 * clients (OAuth2 bearer tokens) and ingest tokens.
 */
final class Auth
{
    public const SESSION_COOKIE = 'obx_session';
    public const CSRF_COOKIE = 'obx_csrf';
    public const SESSION_TTL = 30 * 86400;
    public const TOKEN_TTL = 3600;

    public function __construct(private App $app)
    {
    }

    // ================================================================ sessions

    /**
     * @return array{session: array<string, mixed>, user: array<string, mixed>, retailer: ?array<string, mixed>}|null
     */
    public function session(Request $req): ?array
    {
        if (array_key_exists('_session', $req->attrs)) {
            return $req->attrs['_session'];
        }
        $req->attrs['_session'] = null;
        $token = $req->cookies[self::SESSION_COOKIE] ?? null;
        if (!is_string($token) || strlen($token) < 20) {
            return null;
        }
        $db = $this->app->db;
        $hash = Crypto::hashToken($token);
        $s = $db->one('SELECT * FROM obx_sessions WHERE id_hash = ?', [$hash]);
        $now = Clock::time();
        if ($s === null || (int) $s['expires_ts'] < $now) {
            return null;
        }
        $user = $db->one('SELECT * FROM obx_users WHERE id = ?', [$s['user_id']]);
        if ($user === null || $user['status'] !== 'active') {
            $db->delete('obx_sessions', ['id_hash' => $hash]);
            return null;
        }
        $retailer = null;
        if ($user['role'] === 'retailer') {
            $retailer = $user['retailer_id'] !== null ? $db->one('SELECT * FROM obx_retailers WHERE id = ?', [$user['retailer_id']]) : null;
            if ($retailer === null || $retailer['status'] !== 'active') {
                $db->delete('obx_sessions', ['id_hash' => $hash]);
                return null;
            }
        }
        if ($now - (int) $s['seen_ts'] > 300) {
            $db->update('obx_sessions', ['seen_ts' => $now, 'expires_ts' => $now + self::SESSION_TTL], ['id_hash' => $hash]);
            if ($retailer !== null) {
                $db->update('obx_retailers', ['last_activity_at' => Clock::iso()], ['id' => $retailer['id']]);
            }
        }
        return $req->attrs['_session'] = ['session' => $s, 'user' => $user, 'retailer' => $retailer];
    }

    /**
     * Requires a logged-in user; $role "admin" or "retailer" (null = any). Also checks CSRF on
     * state-changing methods.
     *
     * @return array{session: array<string, mixed>, user: array<string, mixed>, retailer: ?array<string, mixed>}
     */
    public function user(Request $req, ?string $role = null): array
    {
        $this->csrf($req);
        $s = $this->session($req);
        if ($s === null) {
            throw new ApiError('unauthorized', 'Please log in.');
        }
        if ($role !== null && $s['user']['role'] !== $role) {
            throw new ApiError('forbidden', $role === 'admin' ? 'Only the brand\'s administrators can do this.' : 'This is for retailer accounts.');
        }
        return $s;
    }

    /** CSRF check for POST/PUT/PATCH/DELETE (double submit bound to the session). */
    public function csrf(Request $req): void
    {
        if (!in_array($req->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        $given = (string) ($req->header('x-csrf-token') ?? '');
        $s = $this->session($req);
        $expected = $s !== null ? (string) $s['session']['csrf'] : (string) ($req->cookies[self::CSRF_COOKIE] ?? '');
        if ($given === '' || $expected === '' || !hash_equals($expected, $given)) {
            throw new ApiError('csrf_failed', 'The security token is missing or outdated. Reload the page and try again.');
        }
    }

    /** CSRF token for GET /session; sets the anonymous cookie when needed. */
    public function csrfToken(Request $req, Response $res): string
    {
        $s = $this->session($req);
        if ($s !== null) {
            return (string) $s['session']['csrf'];
        }
        $cookie = $req->cookies[self::CSRF_COOKIE] ?? null;
        if (is_string($cookie) && strlen($cookie) >= 20) {
            return $cookie;
        }
        $token = Crypto::token('', 24);
        $res->cookie(self::CSRF_COOKIE, $token, self::SESSION_TTL, $this->app->secureCookies($req));
        return $token;
    }

    /** Starts a session (rotating ids); returns the new CSRF token. @param array<string, mixed> $user */
    public function login(Request $req, Response $res, array $user): string
    {
        $old = $req->cookies[self::SESSION_COOKIE] ?? null;
        if (is_string($old) && $old !== '') {
            $this->app->db->delete('obx_sessions', ['id_hash' => Crypto::hashToken($old)]);
        }
        $token = Crypto::token('', 32);
        $csrf = Crypto::token('', 24);
        $now = Clock::time();
        $this->app->db->insert('obx_sessions', [
            'id_hash' => Crypto::hashToken($token),
            'user_id' => $user['id'],
            'csrf' => $csrf,
            'created_ts' => $now,
            'seen_ts' => $now,
            'expires_ts' => $now + self::SESSION_TTL,
        ]);
        $this->app->db->update('obx_users', ['last_login_at' => Clock::iso()], ['id' => $user['id']]);
        $secure = $this->app->secureCookies($req);
        $res->cookie(self::SESSION_COOKIE, $token, self::SESSION_TTL, $secure);
        $res->cookie(self::CSRF_COOKIE, $csrf, self::SESSION_TTL, $secure);
        $req->attrs['_session'] = [
            'session' => ['csrf' => $csrf, 'user_id' => $user['id']],
            'user' => $this->app->db->one('SELECT * FROM obx_users WHERE id = ?', [$user['id']]),
            'retailer' => $user['retailer_id'] !== null ? $this->app->db->one('SELECT * FROM obx_retailers WHERE id = ?', [$user['retailer_id']]) : null,
        ];
        return $csrf;
    }

    public function logout(Request $req, Response $res): void
    {
        $token = $req->cookies[self::SESSION_COOKIE] ?? null;
        if (is_string($token) && $token !== '') {
            $this->app->db->delete('obx_sessions', ['id_hash' => Crypto::hashToken($token)]);
        }
        $secure = $this->app->secureCookies($req);
        $res->cookie(self::SESSION_COOKIE, '', 0, $secure);
        $res->cookie(self::CSRF_COOKIE, '', 0, $secure);
        $req->attrs['_session'] = null;
    }

    /** Ends every session of a user (except $keepHash). */
    public function endSessions(string $userId, ?string $keepHash = null): void
    {
        if ($keepHash === null) {
            $this->app->db->delete('obx_sessions', ['user_id' => $userId]);
        } else {
            $this->app->db->run('DELETE FROM obx_sessions WHERE user_id = ? AND id_hash <> ?', [$userId, $keepHash]);
        }
    }

    public function endRetailerSessions(string $retailerId): void
    {
        $this->app->db->run('DELETE FROM obx_sessions WHERE user_id IN (SELECT id FROM obx_users WHERE retailer_id = ?)', [$retailerId]);
    }

    // ================================================================ protocol clients

    /**
     * Authenticates a protocol call. Returns client, retailer and token scopes.
     *
     * @return array{client: array<string, mixed>, retailer: array<string, mixed>, scopes: list<string>}
     */
    public function apiClient(Request $req, ?string $scope = 'catalog:read', bool $rateLimit = true): array
    {
        $token = $req->bearer();
        if ($token === null) {
            throw new ApiError('unauthorized', 'Send an access token: Authorization: Bearer <token>.', [], null, ['WWW-Authenticate' => 'Bearer realm="obx"']);
        }
        $db = $this->app->db;
        $row = $db->one('SELECT * FROM obx_access_tokens WHERE token_hash = ?', [Crypto::hashToken($token)]);
        if ($row === null || (int) $row['expires_ts'] < Clock::time()) {
            throw new ApiError('invalid_token', 'The access token is unknown or expired. Request a new one.', [], null, ['WWW-Authenticate' => 'Bearer realm="obx", error="invalid_token"']);
        }
        $client = $db->one('SELECT * FROM obx_api_clients WHERE id = ?', [$row['client_id']]);
        $retailer = $client !== null ? $db->one('SELECT * FROM obx_retailers WHERE id = ?', [$client['retailer_id']]) : null;
        if ($client === null || $retailer === null || $client['status'] !== 'active' || $retailer['status'] !== 'active') {
            throw new ApiError('access_revoked', 'Access for this client was revoked by the brand. Stop syncing and contact the brand.');
        }
        $scopes = Json::decode((string) $row['scopes'], []);
        // Scopes can only shrink after issue (e.g. originals switched off).
        $scopes = array_values(array_intersect($scopes, $this->app->grants->scopes($retailer)));
        if ($rateLimit) {
            $this->app->limiter->hit('api:' . $client['id'], 600, 60);
        }
        if ($scope !== null && !in_array($scope, $scopes, true)) {
            throw new ApiError('insufficient_scope', 'This token lacks the scope ' . $scope . '.');
        }
        if ($client['last_used_at'] === null || Clock::parse((string) $client['last_used_at']) < Clock::time() - 300) {
            $db->update('obx_api_clients', ['last_used_at' => Clock::iso()], ['id' => $client['id']]);
            $db->update('obx_retailers', ['last_activity_at' => Clock::iso()], ['id' => $retailer['id']]);
        }
        return ['client' => $client, 'retailer' => $retailer, 'scopes' => $scopes];
    }

    // ================================================================ ingest tokens

    /** @return array<string, mixed> token row with decoded scopes/brands */
    public function ingest(Request $req, ?string $scope): array
    {
        $token = $req->bearer();
        if ($token === null) {
            throw new ApiError('unauthorized', 'Send an ingest token: Authorization: Bearer obxi_….', [], null, ['WWW-Authenticate' => 'Bearer realm="obx-ingest"']);
        }
        $row = $this->app->db->one('SELECT * FROM obx_ingest_tokens WHERE secret_hash = ?', [Crypto::hashToken($token)]);
        if ($row === null) {
            throw new ApiError('invalid_token', 'This ingest token is unknown. Create one in the admin under Automation.');
        }
        if ($row['status'] !== 'active' || ($row['expires_ts'] !== null && (int) $row['expires_ts'] <= Clock::time())) {
            throw new ApiError('access_revoked', 'This ingest token was revoked or has expired.');
        }
        $row['scopes'] = Json::decode((string) $row['scopes'], []);
        $row['brand_external_ids'] = Json::decode((string) $row['brand_external_ids'], null);
        $this->app->limiter->hit('ingest:' . $row['id'], 3000, 60);
        if ($scope !== null && !in_array($scope, $row['scopes'], true)) {
            throw new ApiError('insufficient_scope', 'This token lacks the scope ' . $scope . '.');
        }
        if ($row['last_used_at'] === null || Clock::parse((string) $row['last_used_at']) < Clock::time() - 300) {
            $this->app->db->update('obx_ingest_tokens', ['last_used_at' => Clock::iso()], ['id' => $row['id']]);
        }
        return $row;
    }
}
