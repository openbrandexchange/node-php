<?php

declare(strict_types=1);

namespace Obx\Node\Security;

/**
 * Secrets, hashing, encryption at rest and signatures. Keys are derived from OBX_SECRET_KEY
 * (or a key file generated in the data directory on first start).
 */
final class Crypto
{
    private string $master;
    private string $secret;

    public function __construct(string $secretKey)
    {
        if (strlen($secretKey) < 16) {
            throw new \RuntimeException('OBX_SECRET_KEY must be at least 32 characters.');
        }
        $this->secret = $secretKey;
        $this->master = hash('sha256', $secretKey, true);
    }

    /** The secret key itself, for `php bin/obx backup` only. */
    public function keyForBackup(): string
    {
        return $this->secret;
    }

    /**
     * Returns the configured secret key or creates/reads data/secret.key (0600).
     */
    public static function resolveKey(?string $configured, string $dataDir): string
    {
        if ($configured !== null && $configured !== '') {
            return $configured;
        }
        $file = $dataDir . '/secret.key';
        if (is_file($file)) {
            $k = trim((string) file_get_contents($file));
            if ($k !== '') {
                return $k;
            }
        }
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0770, true);
        }
        $key = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $key . "\n");
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }
        return trim((string) file_get_contents($file));
    }

    private function subkey(string $purpose): string
    {
        return hash_hmac('sha256', 'obx:' . $purpose, $this->master, true);
    }

    /** Random token: prefix + base62-ish url-safe characters (>= 160 bits). */
    public static function token(string $prefix = '', int $bytes = 24): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', 'Az'), '=');
    }

    /** SHA-256 of a high-entropy token (enough for random secrets). */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function equals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 11]);
    }

    public static function verifyPassword(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            // Spend comparable time so a missing account is not detectable by timing.
            static $dummy = null;
            $dummy ??= self::hashPassword('obx-timing-equaliser');
            password_verify($password, $dummy);
            return false;
        }
        return password_verify($password, $hash);
    }

    /** AES-256-GCM encryption for secrets that must be usable later (webhook secrets, credentials). */
    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $this->subkey('enc'), OPENSSL_RAW_DATA, $iv, $tag, 'obx', 16);
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return 'v1.' . base64_encode($iv . $tag . $ct);
    }

    public function decrypt(?string $sealed): ?string
    {
        if ($sealed === null || !str_starts_with($sealed, 'v1.')) {
            return null;
        }
        $raw = base64_decode(substr($sealed, 3), true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->subkey('enc'), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'obx');
        return $plain === false ? null : $plain;
    }

    /** HMAC signature (url-safe base64, 128 bits) for signed URLs and cursors. */
    public function sign(string $purpose, string $message): string
    {
        $mac = hash_hmac('sha256', $message, $this->subkey($purpose), true);
        return rtrim(strtr(base64_encode(substr($mac, 0, 16)), '+/', '-_'), '=');
    }

    public function verify(string $purpose, string $message, string $signature): bool
    {
        return hash_equals($this->sign($purpose, $message), $signature);
    }

    /** Signs a JSON-able payload into an opaque token "<b64 payload>.<sig>". */
    public function seal(string $purpose, array $payload): string
    {
        $data = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        return $data . '.' . $this->sign($purpose, $data);
    }

    /** @return array<string, mixed>|null */
    public function unseal(string $purpose, string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || !$this->verify($purpose, $parts[0], $parts[1])) {
            return null;
        }
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $v = $json === false ? null : json_decode($json, true);
        return is_array($v) ? $v : null;
    }

    /** Stable node-specific derived value (e.g. the cron key). */
    public function derive(string $purpose, int $chars = 32): string
    {
        return substr(rtrim(strtr(base64_encode($this->subkey($purpose)), '+/', 'Az'), '='), 0, $chars);
    }
}
