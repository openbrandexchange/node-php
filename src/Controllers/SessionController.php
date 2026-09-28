<?php

declare(strict_types=1);

namespace Obx\Node\Controllers;

use Obx\Node\Http\ApiError;
use Obx\Node\Http\Request;
use Obx\Node\Http\Response;
use Obx\Node\Security\Crypto;
use Obx\Node\Services\Users;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Ids;
use Obx\Node\Support\Lang;
use Obx\Node\Support\Str;

/**
 * Public branding, first-run setup, sessions, passwords and invitations.
 */
final class SessionController extends Controller
{
    /** @return array<string, mixed> */
    public function brandingData(): array
    {
        $s = $this->app->settings->all();
        $brands = [];
        foreach ($this->app->db->all('SELECT id, name FROM obx_brands WHERE visible = 1 ORDER BY name') as $b) {
            $brands[] = ['id' => $b['id'], 'name' => $b['name']];
        }
        return [
            'name' => $this->app->settings->displayName(),
            'logo_url' => (new DiscoveryController($this->app))->logoUrl(),
            'accent_color' => $s['branding']['accent_color'],
            'welcome_text' => $s['branding']['welcome_text'],
            'portal_name' => $s['branding']['portal_name'] ?? null,
            'languages' => $this->app->settings->languages(),
            'default_language' => $this->app->settings->defaultLanguage(),
            'access_requests_enabled' => (bool) $s['portal']['access_requests_enabled'],
            'setup_required' => !$this->app->settings->setupDone(),
            'brands' => $brands,
        ];
    }

    public function branding(Request $req): Response
    {
        return Response::json($this->brandingData());
    }

    public function setupState(Request $req): Response
    {
        return Response::json(['required' => !$this->app->settings->setupDone()]);
    }

    /** @return array<string, mixed> Session */
    public function sessionData(Request $req, ?string $csrf = null, ?Response $res = null): array
    {
        $s = $this->app->auth->session($req);
        $csrf ??= $this->app->auth->csrfToken($req, $res ?? new Response());
        $retailer = null;
        if ($s !== null && $s['retailer'] !== null) {
            $retailer = ['id' => $s['retailer']['id'], 'name' => $s['retailer']['name']];
        }
        return [
            'authenticated' => $s !== null,
            'csrf_token' => $csrf,
            'setup_required' => !$this->app->settings->setupDone(),
            'user' => $s !== null ? $this->app->users->json($s['user']) : null,
            'retailer' => $retailer,
        ];
    }

    public function session(Request $req): Response
    {
        $res = new Response(200);
        $data = $this->sessionData($req, null, $res);
        $res->body = \Obx\Node\Support\Json::encode($data);
        $res->header('Content-Type', 'application/json; charset=utf-8');
        return $res;
    }

    public function setup(Request $req): Response
    {
        $this->app->limiter->hit('setup:' . $this->app->clientIp($req), 10, 900);
        $this->app->auth->csrf($req);
        if ($this->app->settings->setupDone()) {
            throw new ApiError('setup_completed', 'This portal is already set up. Log in instead.');
        }
        $in = $req->jsonObject();
        $code = $this->app->ensureSetupCode();
        $given = is_string($in['setup_code'] ?? null) ? strtoupper(trim($in['setup_code'])) : '';
        if ($given === '' || !hash_equals(strtoupper($code), $given)) {
            throw new ApiError('forbidden', 'The setup code is wrong. It is printed in the server log and in data/SETUP-CODE.txt.', [['pointer' => '/setup_code', 'detail' => 'Wrong setup code.', 'obx_error' => 'forbidden']]);
        }
        $company = $in['company'] ?? null;
        $admin = $in['admin'] ?? null;
        if (!is_array($company) || !is_string($company['name'] ?? null) || trim($company['name']) === '' || mb_strlen($company['name'], 'UTF-8') > 200) {
            throw ApiError::validation('/company/name', 'Enter your company name.');
        }
        $default = $company['default_language'] ?? null;
        if (!is_string($default) || !preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $default)) {
            throw ApiError::validation('/company/default_language', 'Choose the main language of your product texts.');
        }
        $langs = [$default];
        foreach ((array) ($company['languages'] ?? []) as $l) {
            if (!is_string($l) || !preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $l)) {
                throw ApiError::validation('/company/languages', 'Language codes such as nl or en.');
            }
            $langs[] = $l;
        }
        $langs = array_values(array_unique($langs));
        $accent = $company['accent_color'] ?? null;
        if ($accent !== null && (!is_string($accent) || !preg_match('/^#[0-9a-fA-F]{6}$/', $accent))) {
            throw ApiError::validation('/company/accent_color', 'A colour like #2f5d62.');
        }
        $website = $company['website'] ?? null;
        if ($website !== null && $website !== '' && (!is_string($website) || filter_var($website, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $website))) {
            throw ApiError::validation('/company/website', 'A web address starting with https://.');
        }
        $contact = null;
        if (($company['contact_email'] ?? null) !== null && $company['contact_email'] !== '') {
            $contact = Users::normalizeEmail($company['contact_email'], '/company/contact_email');
        }
        if (!is_array($admin) || !is_string($admin['name'] ?? null) || trim($admin['name']) === '') {
            throw ApiError::validation('/admin/name', 'Enter your name.');
        }
        $email = Users::normalizeEmail($admin['email'] ?? null, '/admin/email');
        $password = Users::validatePassword($admin['password'] ?? null, '/admin/password');
        $brandName = is_string($company['first_brand_name'] ?? null) && trim($company['first_brand_name']) !== '' ? trim($company['first_brand_name']) : trim($company['name']);
        $brandExt = Str::slug($brandName) ?: 'brand';

        $res = new Response(201);
        $this->app->db->tx(function () use ($req, $company, $website, $contact, $accent, $langs, $default, $admin, $email, $password, $brandName, $brandExt) {
            if ($this->app->settings->setupDone()) {
                throw new ApiError('setup_completed', 'This portal is already set up.');
            }
            $settings = $this->app->settings->all();
            $settings['company']['name'] = trim($company['name']);
            $settings['company']['website'] = $website !== '' ? $website : null;
            $settings['company']['contact_email'] = $contact;
            $settings['branding']['display_name'] = trim($company['name']);
            $settings['branding']['accent_color'] = $accent;
            $settings['portal']['languages'] = $langs;
            $settings['portal']['default_language'] = $default;
            $this->app->settings->save($settings);
            $this->app->writer->upsert('brand', $brandExt, ['name' => $brandName, 'status' => 'published']);
            $this->app->db->insert('obx_users', [
                'id' => Ids::new('usr'),
                'email' => $email,
                'name' => trim((string) $admin['name']),
                'role' => 'admin',
                'retailer_id' => null,
                'status' => 'active',
                'language' => Lang::ui($admin['language'] ?? $default),
                'password_hash' => Crypto::hashPassword($password),
                'created_at' => Clock::iso(),
            ]);
            $this->app->settings->updateState(['setup_done' => true, 'setup_code_enc' => null, 'setup_at' => Clock::iso(), 'public_url' => $this->app->baseUrl($req)]);
            $this->app->settings->nodeId();
        });
        @unlink($this->app->config->dataDir() . '/SETUP-CODE.txt');
        $user = $this->app->db->one('SELECT * FROM obx_users WHERE email = ?', [$email]);
        $csrf = $this->app->auth->login($req, $res, $user);
        return $this->sessionResponse($req, $res, $csrf);
    }

    private function sessionResponse(Request $req, Response $res, string $csrf): Response
    {
        $res->body = \Obx\Node\Support\Json::encode($this->sessionData($req, $csrf, $res));
        $res->header('Content-Type', 'application/json; charset=utf-8');
        return $res;
    }

    public function login(Request $req): Response
    {
        $this->app->limiter->hit('login-ip:' . $this->app->clientIp($req), 10 * 3, 900);
        $this->app->auth->csrf($req);
        $in = $req->jsonObject();
        $email = is_string($in['email'] ?? null) ? strtolower(trim($in['email'])) : '';
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';
        $this->app->limiter->hit('login-email:' . $email, 10, 900);
        $user = $email !== '' ? $this->app->db->one('SELECT * FROM obx_users WHERE email = ?', [$email]) : null;
        if (!Crypto::verifyPassword($password, $user['password_hash'] ?? null)) {
            throw new ApiError('invalid_credentials', 'The e-mail address or password is not correct.');
        }
        if ($user['status'] === 'blocked') {
            throw new ApiError('account_blocked', 'This account is blocked. Contact the brand.');
        }
        if ($user['status'] !== 'active') {
            throw new ApiError('invalid_credentials', 'The e-mail address or password is not correct.');
        }
        if ($user['role'] === 'retailer') {
            $r = $this->app->db->one('SELECT status FROM obx_retailers WHERE id = ?', [$user['retailer_id']]);
            if ($r === null || $r['status'] !== 'active') {
                throw new ApiError('account_blocked', 'Access for your company is blocked. Contact the brand.');
            }
        }
        if (password_needs_rehash((string) $user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            $this->app->db->update('obx_users', ['password_hash' => Crypto::hashPassword($password)], ['id' => $user['id']]);
        }
        $res = new Response(200);
        $csrf = $this->app->auth->login($req, $res, $user);
        return $this->sessionResponse($req, $res, $csrf);
    }

    public function logout(Request $req): Response
    {
        $this->app->auth->csrf($req);
        $res = Response::empty(204);
        $this->app->auth->logout($req, $res);
        return $res;
    }

    public function forgot(Request $req): Response
    {
        $this->app->limiter->hit('forgot:' . $this->app->clientIp($req), 5, 900);
        $this->app->auth->csrf($req);
        $in = $req->jsonObject();
        $email = is_string($in['email'] ?? null) ? strtolower(trim($in['email'])) : '';
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->app->users->forgot($email);
        }
        return Response::empty(202);
    }

    public function reset(Request $req): Response
    {
        $this->app->limiter->hit('reset:' . $this->app->clientIp($req), 10, 900);
        $this->app->auth->csrf($req);
        $in = $req->jsonObject();
        $password = Users::validatePassword($in['password'] ?? null);
        $user = $this->app->users->reset((string) ($in['token'] ?? ''), $password);
        $res = new Response(200);
        $csrf = $this->app->auth->login($req, $res, $user);
        return $this->sessionResponse($req, $res, $csrf);
    }

    public function invite(Request $req): Response
    {
        $this->app->limiter->hit('invite:' . $this->app->clientIp($req), 30, 900);
        $u = $this->app->users->byInvite((string) $req->params['token']);
        $retailer = $u['retailer_id'] !== null ? $this->app->db->one('SELECT name FROM obx_retailers WHERE id = ?', [$u['retailer_id']]) : null;
        return Response::json([
            'email' => $u['email'],
            'name' => $u['name'],
            'role' => $u['role'],
            'retailer_name' => $retailer['name'] ?? null,
            'expires_at' => $u['invite_expires_at'],
        ]);
    }

    public function acceptInvite(Request $req): Response
    {
        $this->app->limiter->hit('invite:' . $this->app->clientIp($req), 30, 900);
        $this->app->auth->csrf($req);
        $in = $req->jsonObject();
        $password = Users::validatePassword($in['password'] ?? null);
        $lang = isset($in['language']) && is_string($in['language']) ? $in['language'] : null;
        $user = $this->app->users->acceptInvite((string) $req->params['token'], $password, is_string($in['name'] ?? null) ? $in['name'] : null, $lang);
        $res = new Response(200);
        $csrf = $this->app->auth->login($req, $res, $user);
        return $this->sessionResponse($req, $res, $csrf);
    }

    public function me(Request $req): Response
    {
        $s = $this->app->auth->user($req);
        $in = $req->jsonObject();
        $set = [];
        if (array_key_exists('name', $in)) {
            if (!is_string($in['name']) || trim($in['name']) === '' || mb_strlen($in['name'], 'UTF-8') > 200) {
                throw ApiError::validation('/name', 'Enter your name.');
            }
            $set['name'] = trim($in['name']);
        }
        if (array_key_exists('language', $in)) {
            if (!is_string($in['language']) || !preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $in['language'])) {
                throw ApiError::validation('/language', 'A language code such as nl.');
            }
            $set['language'] = Lang::ui($in['language']);
        }
        if (array_key_exists('new_password', $in)) {
            if (!Crypto::verifyPassword((string) ($in['current_password'] ?? ''), $s['user']['password_hash'])) {
                throw new ApiError('invalid_credentials', 'Your current password is not correct.', [['pointer' => '/current_password', 'detail' => 'Not correct.', 'obx_error' => 'invalid_credentials']]);
            }
            $set['password_hash'] = Crypto::hashPassword(Users::validatePassword($in['new_password'], '/new_password'));
        }
        if ($set !== []) {
            $this->app->db->update('obx_users', $set, ['id' => $s['user']['id']]);
        }
        if (isset($set['password_hash'])) {
            $cookie = (string) ($req->cookies['obx_session'] ?? '');
            $this->app->auth->endSessions((string) $s['user']['id'], Crypto::hashToken($cookie));
        }
        $req->attrs = [];
        return Response::json($this->sessionData($req));
    }
}
