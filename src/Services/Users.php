<?php

declare(strict_types=1);

namespace Obx\Node\Services;

use Obx\Node\App;
use Obx\Node\Http\ApiError;
use Obx\Node\Security\Crypto;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Ids;
use Obx\Node\Support\Lang;

/**
 * Accounts: brand admins and retailer users, invitations and password resets.
 */
final class Users
{
    public const INVITE_DAYS = 7;
    public const RESET_SECONDS = 3600;

    /** @var array<string, true>|null */
    private static ?array $common = null;

    public function __construct(private App $app)
    {
    }

    public static function normalizeEmail(mixed $email, string $pointer = '/email'): string
    {
        if (!is_string($email) || strlen($email) > 254 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            throw ApiError::validation($pointer, 'Enter a valid e-mail address.');
        }
        return strtolower(trim($email));
    }

    public static function validatePassword(mixed $password, string $pointer = '/password'): string
    {
        if (!is_string($password)) {
            throw ApiError::validation($pointer, 'Choose a password.');
        }
        $len = mb_strlen($password, 'UTF-8');
        if ($len < 10 || strlen($password) > 72) {
            throw ApiError::validation($pointer, 'Use 10 to 72 characters.');
        }
        if (self::$common === null) {
            self::$common = [];
            $file = dirname(__DIR__, 2) . '/resources/common-passwords.txt';
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if ($line[0] !== '#') {
                    self::$common[strtolower($line)] = true;
                }
            }
        }
        if (isset(self::$common[strtolower($password)]) || count(array_unique(mb_str_split($password))) < 4) {
            throw ApiError::validation($pointer, 'This password is too easy to guess. Choose another one.');
        }
        return $password;
    }

    /** @param array<string, mixed> $u @return array<string, mixed> */
    public function json(array $u): array
    {
        return [
            'id' => $u['id'],
            'email' => $u['email'],
            'name' => $u['name'],
            'role' => $u['role'],
            'retailer_id' => $u['retailer_id'],
            'status' => $u['status'],
            'language' => $u['language'],
            'last_login_at' => $u['last_login_at'],
            'invite_expires_at' => $u['status'] === 'invited' ? $u['invite_expires_at'] : null,
            'created_at' => $u['created_at'],
        ];
    }

    /**
     * Creates an invited account and e-mails the one-time link.
     *
     * @return array<string, mixed> InviteResult
     */
    public function invite(string $email, ?string $name, string $role, ?string $retailerId, ?string $language): array
    {
        $db = $this->app->db;
        if ($db->one('SELECT id FROM obx_users WHERE email = ?', [$email]) !== null) {
            throw new ApiError('conflict', 'This e-mail address already has an account on this portal.');
        }
        $lang = Lang::ui($language ?? $this->app->settings->defaultLanguage());
        $id = Ids::new('usr');
        $db->insert('obx_users', [
            'id' => $id,
            'email' => $email,
            'name' => $name !== null && trim($name) !== '' ? trim($name) : explode('@', $email)[0],
            'role' => $role,
            'retailer_id' => $role === 'retailer' ? $retailerId : null,
            'status' => 'invited',
            'language' => $lang,
            'password_hash' => null,
            'invite_hash' => null,
            'invite_expires_at' => null,
            'created_at' => Clock::iso(),
        ]);
        return $this->sendInvite($id);
    }

    /** New invitation token (old one stops working) and e-mail. @return array<string, mixed> */
    public function sendInvite(string $userId): array
    {
        $db = $this->app->db;
        $token = Crypto::token('', 24);
        $expires = Clock::iso(Clock::now() + self::INVITE_DAYS * 86400);
        $db->update('obx_users', ['invite_hash' => Crypto::hashToken($token), 'invite_expires_at' => $expires], ['id' => $userId]);
        $u = $db->one('SELECT * FROM obx_users WHERE id = ?', [$userId]);
        $url = $this->app->publicUrl() . '/invite/' . $token;
        $lang = (string) $u['language'];
        $mail = $this->app->emails->render('invite', $lang, ['expires' => \Obx\Node\Mail\Emails::date((int) Clock::parse($expires), $lang)], $url, [], ['expiry', 'ignore'], (string) $u['name']);
        $sent = $this->app->mailer->send((string) $u['email'], $mail['subject'], $mail['text'], $mail['html']);
        return ['user' => $this->json($u), 'invite_url' => $url, 'expires_at' => $expires, 'email_sent' => $sent];
    }

    /** @return array<string, mixed> user row of a valid invitation */
    public function byInvite(string $token): array
    {
        $u = strlen($token) >= 20 ? $this->app->db->one('SELECT * FROM obx_users WHERE invite_hash = ?', [Crypto::hashToken($token)]) : null;
        if ($u === null || $u['status'] !== 'invited' || Clock::parse((string) $u['invite_expires_at']) < Clock::now()) {
            throw new ApiError('invite_invalid', 'This invitation link is unknown, already used or expired. Ask for a new one.');
        }
        return $u;
    }

    /** @return array<string, mixed> the activated user */
    public function acceptInvite(string $token, string $password, ?string $name, ?string $language): array
    {
        return $this->app->db->tx(function () use ($token, $password, $name, $language) {
            $u = $this->byInvite($token);
            $set = [
                'password_hash' => Crypto::hashPassword($password),
                'status' => 'active',
                'invite_hash' => null,
                'invite_expires_at' => null,
            ];
            if ($name !== null && trim($name) !== '') {
                $set['name'] = trim($name);
            }
            if ($language !== null) {
                $set['language'] = Lang::ui($language);
            }
            // Consume the token atomically: only the request that still sees the hash wins.
            $n = $this->app->db->update('obx_users', $set, ['id' => $u['id'], 'invite_hash' => $u['invite_hash']]);
            if ($n !== 1) {
                throw new ApiError('invite_invalid', 'This invitation link was already used.');
            }
            return $this->app->db->one('SELECT * FROM obx_users WHERE id = ?', [$u['id']]);
        });
    }

    public function forgot(string $email): void
    {
        $u = $this->app->db->one("SELECT * FROM obx_users WHERE email = ? AND status = 'active'", [$email]);
        if ($u === null) {
            return;
        }
        $token = Crypto::token('', 24);
        $this->app->db->update('obx_users', ['reset_hash' => Crypto::hashToken($token), 'reset_expires_ts' => Clock::time() + self::RESET_SECONDS], ['id' => $u['id']]);
        $url = $this->app->publicUrl() . '/reset-password/' . $token;
        $mail = $this->app->emails->render('passwordReset', (string) $u['language'], [], $url, [], ['expiry', 'ignore'], (string) $u['name']);
        $this->app->mailer->send((string) $u['email'], $mail['subject'], $mail['text'], $mail['html']);
    }

    /** @return array<string, mixed> user */
    public function reset(string $token, string $password): array
    {
        return $this->app->db->tx(function () use ($token, $password) {
            $u = strlen($token) >= 20 ? $this->app->db->one('SELECT * FROM obx_users WHERE reset_hash = ?', [Crypto::hashToken($token)]) : null;
            if ($u === null || (int) $u['reset_expires_ts'] < Clock::time() || $u['status'] !== 'active') {
                throw new ApiError('invite_invalid', 'This reset link is unknown, already used or expired. Ask for a new one.');
            }
            $this->app->db->update('obx_users', ['password_hash' => Crypto::hashPassword($password), 'reset_hash' => null, 'reset_expires_ts' => null], ['id' => $u['id']]);
            $this->app->auth->endSessions((string) $u['id']);
            return $this->app->db->one('SELECT * FROM obx_users WHERE id = ?', [$u['id']]);
        });
    }
}
