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
        $values['config_file'] = $configFile;
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
            'trusted_proxies' => null,
            'log_file' => null,
            'log_format' => 'text',
            'access_log' => false,
            'metrics_token' => null,
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
            'OBX_LOG_FORMAT' => 'log_format',
            'OBX_METRICS_TOKEN' => 'metrics_token',
            'OBX_TRUSTED_PROXIES' => 'trusted_proxies',
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
        $flag = getenv('OBX_ACCESS_LOG');
        if ($flag !== false) {
            $out['access_log'] = in_array(strtolower($flag), ['1', 'true', 'yes'], true);
        }
        $flag = getenv('OBX_S3_PATH_STYLE');
        if ($flag !== false) {
            $out['s3_path_style'] = in_array(strtolower($flag), ['1', 'true', 'yes'], true);
        }
        $factor = getenv('OBX_RATE_LIMIT_FACTOR');
        if ($factor !== false && $factor !== '') {
            // Kept as given when it is not a positive number, so validate() can name the mistake.
            $out['rate_limit_factor'] = is_numeric($factor) ? (float) $factor : $factor;
        }
        foreach (['max_upload_bytes', 'smtp_port'] as $int) {
            if (isset($out[$int]) && is_numeric($out[$int])) {
                $out[$int] = (int) $out[$int];
            }
        }
        return $out;
    }

    /**
     * Checks the configuration and returns every problem as a sentence that names the setting
     * (environment variable and config.php key). An empty list means the node can start.
     *
     * @return list<string>
     */
    public function validate(): array
    {
        $errors = [];
        $name = static fn (string $key): string => strtoupper('OBX_' . $key) . ' (' . $key . ')';
        $url = $this->values['public_url'] ?? null;
        if ($url !== null && $url !== '') {
            $p = is_string($url) ? parse_url($url) : false;
            if (!is_array($p) || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host'])
                || isset($p['query']) || isset($p['fragment']) || isset($p['user'])) {
                $errors[] = $name('public_url') . ' must be a web address such as https://brands.example.com (no query string).';
            }
        }
        $key = $this->values['secret_key'] ?? null;
        if ($key !== null && $key !== '' && (!is_string($key) || strlen($key) < 32)) {
            $errors[] = $name('secret_key') . ' must be at least 32 characters (for example: openssl rand -base64 48).';
        }
        $dsn = (string) ($this->values['db_dsn'] ?? '');
        if (!preg_match('/^(sqlite|mysql):/', $dsn)) {
            $errors[] = $name('db_dsn') . ' must start with sqlite: or mysql: (for example mysql:host=localhost;dbname=obx;charset=utf8mb4).';
        } elseif (str_starts_with($dsn, 'sqlite:') && !extension_loaded('pdo_sqlite')) {
            $errors[] = $name('db_dsn') . ' uses SQLite, but the PHP extension pdo_sqlite is not installed.';
        } elseif (str_starts_with($dsn, 'mysql:') && !extension_loaded('pdo_mysql')) {
            $errors[] = $name('db_dsn') . ' uses MySQL, but the PHP extension pdo_mysql is not installed.';
        }
        $enum = function (string $key, array $allowed) use (&$errors, $name): void {
            $v = $this->values[$key] ?? null;
            if ($v !== null && !in_array($v, $allowed, true)) {
                $errors[] = $name($key) . ' must be one of ' . implode(', ', $allowed) . ' (got "' . (is_scalar($v) ? (string) $v : gettype($v)) . '").';
            }
        };
        $enum('blob_store', ['fs', 's3']);
        $enum('mail_transport', ['mail', 'smtp', 'none', 'memory']);
        $enum('smtp_encryption', ['tls', 'ssl', 'none']);
        $enum('renditions', ['auto', 'server', 'client']);
        $enum('log_format', ['text', 'json']);
        if (($this->values['blob_store'] ?? 'fs') === 's3') {
            foreach (['s3_bucket', 's3_access_key_id', 's3_secret_access_key'] as $k) {
                if (!is_string($this->values[$k] ?? null) || $this->values[$k] === '') {
                    $errors[] = $name($k) . ' is required when ' . $name('blob_store') . ' is s3.';
                }
            }
            if (!function_exists('curl_init')) {
                $errors[] = $name('blob_store') . ' is s3, but the PHP extension curl is not installed.';
            }
        }
        if (($this->values['mail_transport'] ?? 'mail') === 'smtp' && !is_string($this->values['smtp_host'] ?? null)) {
            $errors[] = $name('smtp_host') . ' is required when ' . $name('mail_transport') . ' is smtp.';
        }
        $port = $this->values['smtp_port'] ?? 587;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            $errors[] = $name('smtp_port') . ' must be a port number between 1 and 65535.';
        }
        $from = $this->values['mail_from'] ?? null;
        if ($from !== null && $from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = $name('mail_from') . ' must be an e-mail address.';
        }
        if (($this->values['renditions'] ?? 'auto') === 'server' && !\Obx\Node\Images\Renderer::available()) {
            $errors[] = $name('renditions') . ' is server, but PHP has no GD with JPEG support. Use auto or client, or install GD.';
        }
        $max = $this->values['max_upload_bytes'] ?? 0;
        if (!is_int($max) || $max < 1048576) {
            $errors[] = $name('max_upload_bytes') . ' must be a number of bytes, at least 1048576 (1 MB).';
        }
        $factor = $this->values['rate_limit_factor'] ?? 1.0;
        if ((!is_float($factor) && !is_int($factor)) || (float) $factor <= 0) {
            $errors[] = $name('rate_limit_factor') . ' must be a positive number such as 1 or 0.5.';
        }
        $proxies = $this->values['trusted_proxies'] ?? null;
        if (is_string($proxies) && $proxies !== '') {
            foreach (array_map('trim', explode(',', $proxies)) as $p) {
                if ($p !== '*' && $p !== 'private' && !\Obx\Node\Http\Request::validCidr($p)) {
                    $errors[] = $name('trusted_proxies') . ' must be a comma-separated list of addresses or ranges (10.0.0.0/8), "private" or "*"; "' . $p . '" is none of these.';
                }
            }
        }
        $token = $this->values['metrics_token'] ?? null;
        if (is_string($token) && $token !== '' && strlen($token) < 16) {
            $errors[] = $name('metrics_token') . ' must be at least 16 characters.';
        }
        return $errors;
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

    public function configFile(): string
    {
        return (string) $this->values['config_file'];
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
