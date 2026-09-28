<?php

declare(strict_types=1);

namespace Obx\Node\Http;

/**
 * An HTTP request. Built from PHP globals in production and directly in tests.
 */
final class Request
{
    /** @var array<string, string> lower-case header names */
    public array $headers;
    /** @var array<string, string> path parameters filled by the router */
    public array $params = [];
    /** @var array<string, mixed> request-scoped attributes (auth context etc.) */
    public array $attrs = [];
    private ?string $bodyCache = null;

    /**
     * @param array<string, mixed> $query parsed query (repeated keys become lists)
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed> $post multipart/urlencoded form fields
     * @param array<string, list<array{name: string, tmp: string, size: int, type: string}>> $files
     * @param string|null $bodyFile path of a file holding the raw body (large bodies)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        array $headers = [],
        public readonly array $cookies = [],
        private ?string $body = null,
        public readonly array $post = [],
        public readonly array $files = [],
        public readonly string $ip = '127.0.0.1',
        public readonly string $scheme = 'http',
        public readonly string $host = 'localhost',
        public readonly string $basePath = '',
        private ?string $bodyFile = null,
        public readonly string $rawQuery = '',
    ) {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower((string) $k)] = (string) $v;
        }
        $this->headers = $h;
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $rawPath = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $rawQuery = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($base === '.' || $base === '/') {
            $base = '';
        }
        $path = $rawPath;
        if ($base !== '' && !str_starts_with($path . '/', $base . '/')) {
            // Reached through the root .htaccess (…/public/index.php serving "/…"): the public
            // folder is not part of the URL.
            $base = str_ends_with($base, '/public') ? substr($base, 0, -7) : '';
            if ($base !== '' && !str_starts_with($path . '/', $base . '/')) {
                $base = '';
            }
        }
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        } elseif ($base !== '' && $path === $base) {
            $path = '/';
        }
        if (str_starts_with($path, '/index.php/')) {
            $path = substr($path, 10);
        } elseif ($path === '/index.php') {
            $path = '/';
        }
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[str_replace('_', '-', strtolower(substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }
        if (!isset($headers['authorization'])) {
            if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['PHP_AUTH_USER'])) {
                $headers['authorization'] = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . ($_SERVER['PHP_AUTH_PW'] ?? ''));
            }
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $proto = strtolower((string) ($headers['x-forwarded-proto'] ?? ''));
        if ($proto === 'https') {
            $https = true;
        }
        $host = (string) ($headers['x-forwarded-host'] ?? $headers['host'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $host = strtolower(trim(explode(',', $host)[0]));
        if (!preg_match('/^[a-z0-9.\-]+(:\d{1,5})?$|^\[[0-9a-f:.]+\](:\d{1,5})?$/', $host)) {
            $host = 'localhost';
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $files = [];
        foreach ($_FILES as $field => $info) {
            if (is_array($info['name'])) {
                foreach ($info['name'] as $i => $name) {
                    if (($info['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        if (($info['error'][$i] ?? 0) === UPLOAD_ERR_INI_SIZE || ($info['error'][$i] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
                            throw new ApiError('payload_too_large', 'The uploaded file is larger than this server accepts (upload_max_filesize).');
                        }
                        continue;
                    }
                    $files[(string) $field][] = ['name' => (string) $name, 'tmp' => (string) $info['tmp_name'][$i], 'size' => (int) $info['size'][$i], 'type' => (string) $info['type'][$i]];
                }
            } else {
                if (($info['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    if (($info['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($info['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
                        throw new ApiError('payload_too_large', 'The uploaded file is larger than this server accepts (upload_max_filesize).');
                    }
                    continue;
                }
                $files[(string) $field][] = ['name' => (string) $info['name'], 'tmp' => (string) $info['tmp_name'], 'size' => (int) $info['size'], 'type' => (string) $info['type']];
            }
        }
        $isMultipart = str_contains(strtolower($headers['content-type'] ?? ''), 'multipart/form-data');
        return new self(
            method: $method,
            path: $path === '' ? '/' : $path,
            query: self::parseQuery($rawQuery),
            headers: $headers,
            cookies: array_map('strval', array_filter($_COOKIE, 'is_scalar')),
            body: null,
            post: $isMultipart || $method === 'POST' ? $_POST : [],
            files: $files,
            ip: $ip,
            scheme: $https ? 'https' : 'http',
            host: $host,
            basePath: $base,
            bodyFile: $isMultipart ? null : 'php://input',
            rawQuery: $rawQuery,
        );
    }

    /**
     * Parses a query string keeping repeated keys ("a=1&a=2" => ["a" => ["1", "2"]]) and PHP-style "a[]".
     *
     * @return array<string, mixed>
     */
    public static function parseQuery(string $qs): array
    {
        $out = [];
        if ($qs === '') {
            return $out;
        }
        foreach (explode('&', $qs) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $k = urldecode($parts[0]);
            $v = urldecode($parts[1] ?? '');
            if (str_ends_with($k, '[]')) {
                $k = substr($k, 0, -2);
                $out[$k] = isset($out[$k]) ? array_merge((array) $out[$k], [$v]) : [$v];
                continue;
            }
            if (array_key_exists($k, $out)) {
                $out[$k] = array_merge((array) $out[$k], [$v]);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** Single query value (first when repeated). */
    public function q(string $key, ?string $default = null): ?string
    {
        $v = $this->query[$key] ?? null;
        if (is_array($v)) {
            $v = $v[0] ?? null;
        }
        return $v === null ? $default : (string) $v;
    }

    /** @return list<string> query values as a list (repeated keys, or comma separated). */
    public function qList(string $key): array
    {
        $v = $this->query[$key] ?? null;
        if ($v === null || $v === '') {
            return [];
        }
        $values = is_array($v) ? $v : [$v];
        $out = [];
        foreach ($values as $item) {
            foreach (explode(',', (string) $item) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $out[] = $part;
                }
            }
        }
        return array_values(array_unique($out));
    }

    public function contentType(): string
    {
        $ct = strtolower(trim(explode(';', $this->header('content-type', '') ?? '')[0]));
        return $ct;
    }

    public function contentLength(): ?int
    {
        $v = $this->header('content-length');
        return $v !== null && ctype_digit($v) ? (int) $v : null;
    }

    /** Raw body as a string, refusing bodies above $max bytes (413). */
    public function body(int $max = 20 * 1024 * 1024): string
    {
        if ($this->bodyCache !== null) {
            return $this->bodyCache;
        }
        if ($this->body !== null) {
            if (strlen($this->body) > $max) {
                throw new ApiError('payload_too_large', 'The request body is larger than ' . $max . ' bytes.');
            }
            return $this->bodyCache = $this->body;
        }
        if ($this->bodyFile === null) {
            return $this->bodyCache = '';
        }
        $len = $this->contentLength();
        if ($len !== null && $len > $max) {
            throw new ApiError('payload_too_large', 'The request body is larger than ' . $max . ' bytes.');
        }
        $fh = fopen($this->bodyFile, 'rb');
        if ($fh === false) {
            return $this->bodyCache = '';
        }
        $data = stream_get_contents($fh, $max + 1);
        fclose($fh);
        if ($data === false) {
            $data = '';
        }
        if (strlen($data) > $max) {
            throw new ApiError('payload_too_large', 'The request body is larger than ' . $max . ' bytes.');
        }
        return $this->bodyCache = $data;
    }

    /**
     * Streams the body into a temporary file while hashing it; enforces $max before buffering.
     *
     * @return array{path: string, bytes: int, sha256: string}
     */
    public function bodyToFile(string $tmpDir, int $max): array
    {
        $len = $this->contentLength();
        if ($len !== null && $len > $max) {
            throw new ApiError('payload_too_large', 'The upload is larger than ' . $max . ' bytes.');
        }
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0770, true);
        }
        $path = tempnam($tmpDir, 'up');
        $out = fopen($path, 'wb');
        $ctx = hash_init('sha256');
        $bytes = 0;
        if ($this->body !== null || $this->bodyCache !== null) {
            $data = $this->body ?? $this->bodyCache ?? '';
            if (strlen($data) > $max) {
                fclose($out);
                @unlink($path);
                throw new ApiError('payload_too_large', 'The upload is larger than ' . $max . ' bytes.');
            }
            fwrite($out, $data);
            hash_update($ctx, $data);
            $bytes = strlen($data);
        } elseif ($this->bodyFile !== null) {
            $in = fopen($this->bodyFile, 'rb');
            while ($in !== false && !feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $bytes += strlen($chunk);
                if ($bytes > $max) {
                    fclose($in);
                    fclose($out);
                    @unlink($path);
                    throw new ApiError('payload_too_large', 'The upload is larger than ' . $max . ' bytes.');
                }
                fwrite($out, $chunk);
                hash_update($ctx, $chunk);
            }
            if ($in !== false) {
                fclose($in);
            }
        }
        fclose($out);
        return ['path' => $path, 'bytes' => $bytes, 'sha256' => hash_final($ctx)];
    }

    /** Decoded JSON body (400 invalid_request when malformed). */
    public function json(int $max = 20 * 1024 * 1024): mixed
    {
        return \Obx\Node\Support\Json::decodeBody($this->body($max));
    }

    /** JSON object body; 400 when it is not an object. @return array<string, mixed> */
    public function jsonObject(bool $allowEmpty = false): array
    {
        if ($allowEmpty && trim($this->body()) === '') {
            return [];
        }
        $v = $this->json();
        if (!\Obx\Node\Support\Json::isObject($v)) {
            throw new ApiError('invalid_request', 'The request body must be a JSON object.');
        }
        return $v;
    }

    public function bearer(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth === null || !preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) {
            return null;
        }
        return $m[1];
    }

    public function isHttps(): bool
    {
        return $this->scheme === 'https';
    }

    public function origin(): string
    {
        return $this->scheme . '://' . $this->host;
    }
}
