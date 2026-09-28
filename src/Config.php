<?php

declare(strict_types=1);

namespace Obx\Node;

/**
 * Node configuration. Sources, in order of precedence: environment variables (OBX_*),
 * config/config.php (written by the web installer), built-in defaults.
 */
final class Config
{
    public const VERSION = '0.1.0';
    public const SOFTWARE = 'obx-node-php';
    public const SPEC_VERSION = '0.1.0-draft';
    public const OBX_VERSION = '0.1';

    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    /**
     * @param array<string, mixed> $overrides values that win over everything (tests)
     */
    public static function load(string $root, array $overrides = []): self
    {
        $file = [];
        $configFile = getenv('OBX_CONFIG_FILE') ?: $root . '/config/config.php';
        if (is_file($configFile)) {
            $loaded = require $configFile;
            if (is_array($loaded)) {
                $file = $loaded;
            }
        }
        $env = self::fromEnv();
        $values = array_replace(self::defaults($root), $file, $env, $overrides);
        $values['root'] = $root;
        $values['installed'] = is_file($configFile) || self::envConfigured() || !empty($overrides['installed']);
        return new self($values);
    }

    /** @return array<string, mixed> */
    public static function defaults(string $root): array
    {
        return [
            'public_url' => null,
            'secret_key' => null,
            'setup_code' => null,
            'allow_private_urls' => false,
            'db_dsn' => 'sqlite:' . $root . '/data/obx.sqlite',
            'db_user' => null,
            'db_password' => null,
            'data_dir' => $root . '/data',
            'blob_store' => 'fs',
            's3_endpoint' => null,
            's3_region' => 'auto',
            's3_bucket' => null,
            's3_prefix' => 'blobs/',
            's3_access_key_id' => null,
            's3_secret_access_key' => null,
            's3_path_style' => false,
            'mail_transport' => 'mail',
            'mail_from' => null,
            'smtp_host' => null,
            'smtp_port' => 587,
            'smtp_user' => null,
            'smtp_password' => null,
            'smtp_encryption' => 'tls',
            'max_upload_bytes' => 100 * 1024 * 1024,
            'part_bytes' => 8 * 1024 * 1024,
            'export_part_bytes' => 2 * 1024 * 1024 * 1024 - 1,
            'jobs_inline' => false,
            'renditions' => 'auto',
            'rate_limit_factor' => 1.0,
            'trust_proxy' => true,
            'log_file' => null,
            'ui_dir' => $root . '/public/ui',
            'locales_dir' => null,
        ];
    }

    private static function envConfigured(): bool
    {
        return getenv('OBX_DB_DSN') !== false || getenv('OBX_SECRET_KEY') !== false || getenv('OBX_PUBLIC_URL') !== false;
    }

    /** @return array<string, mixed> */
    private static function fromEnv(): array
    {
        $map = [
            'OBX_PUBLIC_URL' => 'public_url',
            'OBX_SECRET_KEY' => 'secret_key',
            'OBX_SETUP_CODE' => 'setup_code',
            'OBX_DB_DSN' => 'db_dsn',
            'OBX_DB_USER' => 'db_user',
            'OBX_DB_PASSWORD' => 'db_password',
            'OBX_DATA_DIR' => 'data_dir',
            'OBX_BLOB_STORE' => 'blob_store',
            'OBX_S3_ENDPOINT' => 's3_endpoint',
            'OBX_S3_REGION' => 's3_region',
            'OBX_S3_BUCKET' => 's3_bucket',
            'OBX_S3_PREFIX' => 's3_prefix',
            'OBX_S3_ACCESS_KEY_ID' => 's3_access_key_id',
            'OBX_S3_SECRET_ACCESS_KEY' => 's3_secret_access_key',
            'OBX_MAIL_TRANSPORT' => 'mail_transport',
            'OBX_MAIL_FROM' => 'mail_from',
            'OBX_SMTP_HOST' => 'smtp_host',
            'OBX_SMTP_PORT' => 'smtp_port',
            'OBX_SMTP_USER' => 'smtp_user',
            'OBX_SMTP_PASSWORD' => 'smtp_password',
            'OBX_SMTP_ENCRYPTION' => 'smtp_encryption',
            'OBX_MAX_UPLOAD_BYTES' => 'max_upload_bytes',
            'OBX_RENDITIONS' => 'renditions',
            'OBX_LOG_FILE' => 'log_file',
            'OBX_UI_DIR' => 'ui_dir',
        ];
        $out = [];
        foreach ($map as $env => $key) {
            $v = getenv($env);
            if ($v !== false && $v !== '') {
                $out[$key] = $v;
            }
        }
        $flag = getenv('OBX_ALLOW_PRIVATE_URLS');
        if ($flag !== false) {
            $out['allow_private_urls'] = in_array(strtolower($flag), ['1', 'true', 'yes'], true);
        }
        $flag = getenv('OBX_S3_PATH_STYLE');
        if ($flag !== false) {
            $out['s3_path_style'] = in_array(strtolower($flag), ['1', 'true', 'yes'], true);
        }
        foreach (['max_upload_bytes', 'smtp_port'] as $int) {
            if (isset($out[$int])) {
                $out[$int] = (int) $out[$int];
            }
        }
        return $out;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->values[$key] ?? null;
        return is_scalar($v) && (string) $v !== '' ? (string) $v : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->values[$key] ?? null;
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key): bool
    {
        $v = $this->values[$key] ?? false;
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }

    public function root(): string
    {
        return (string) $this->values['root'];
    }

    public function dataDir(): string
    {
        return rtrim((string) $this->values['data_dir'], '/');
    }

    public function installed(): bool
    {
        return (bool) $this->values['installed'];
    }

    /** @param array<string, mixed> $values */
    public function with(array $values): self
    {
        return new self(array_replace($this->values, $values));
    }
}
