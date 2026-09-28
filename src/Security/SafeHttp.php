<?php

declare(strict_types=1);

namespace Obx\Node\Security;

use Obx\Node\Http\ApiError;

/**
 * Outbound HTTP with SSRF protection: https only (http with the development switch), the host
 * is resolved and every address checked against loopback/private/link-local/CGNAT/multicast
 * ranges, the checked address is pinned for the connection (no DNS rebinding) and redirects are
 * followed manually with the same checks.
 */
final class SafeHttp
{
    public function __construct(private bool $allowPrivate = false)
    {
    }

    public function allowPrivate(): bool
    {
        return $this->allowPrivate;
    }

    /**
     * Validates a URL for outbound use. Returns [host, port, pinned ip].
     *
     * @return array{0: string, 1: int, 2: string}
     */
    public function check(string $url, array $schemes = ['https']): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new ApiError('unsafe_url', 'The address is not a valid URL.');
        }
        $allowed = $schemes;
        if ($this->allowPrivate && in_array('https', $schemes, true)) {
            $allowed[] = 'http';
        }
        if (!in_array($scheme, $allowed, true)) {
            throw new ApiError('unsafe_url', 'Only ' . implode(' or ', $schemes) . ' addresses are allowed.');
        }
        $default = match ($scheme) {
            'http' => 80,
            'sftp' => 22,
            default => 443,
        };
        $port = (int) ($parts['port'] ?? $default);
        $ip = $this->resolve(trim($host, '[]'));
        return [$host, $port, $ip];
    }

    /** Resolves $host and returns one safe address (all addresses must be safe). */
    public function resolve(string $host): string
    {
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
            $v6 = @dns_get_record($host, DNS_AAAA);
            if (is_array($v6)) {
                foreach ($v6 as $r) {
                    if (!empty($r['ipv6'])) {
                        $ips[] = $r['ipv6'];
                    }
                }
            }
        }
        if ($ips === []) {
            throw new ApiError('unsafe_url', 'The host name could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (!$this->allowPrivate && self::isPrivate($ip)) {
                throw new ApiError('unsafe_url', 'The address points to a private, loopback or link-local network.');
            }
        }
        return $ips[0];
    }

    public static function isPrivate(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip);
            $ranges = [
                ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
                ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15],
                ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
            ];
            foreach ($ranges as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($n & $mask) === (ip2long($net) & $mask)) {
                    return true;
                }
            }
            return false;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return true;
        }
        if ($bin === str_repeat("\0", 16) || $bin === str_repeat("\0", 15) . "\1") {
            return true;
        }
        // IPv4-mapped (::ffff:a.b.c.d)
        if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return self::isPrivate((string) inet_ntop(substr($bin, 12)));
        }
        $first = ord($bin[0]);
        $second = ord($bin[1]);
        if (($first & 0xFE) === 0xFC) {
            return true; // fc00::/7 unique local
        }
        if ($first === 0xFE && ($second & 0xC0) === 0x80) {
            return true; // fe80::/10 link-local
        }
        if ($first === 0xFF) {
            return true; // multicast
        }
        if ($first === 0x20 && $second === 0x01 && ord($bin[2]) === 0x0d && ord($bin[3]) === 0xb8) {
            return true; // documentation
        }
        return false;
    }

    /**
     * Performs a request. Options: headers (array), body (string), to_file (path), max_bytes,
     * timeout, connect_timeout, follow (max redirects), schemes.
     *
     * @param array<string, mixed> $opts
     * @return array{status: int, headers: array<string, string>, body: string, url: string, bytes: int}
     */
    public function request(string $method, string $url, array $opts = []): array
    {
        if (!function_exists('curl_init')) {
            throw new ApiError('service_unavailable', 'The PHP curl extension is required for outbound requests.');
        }
        $follow = (int) ($opts['follow'] ?? 0);
        $schemes = $opts['schemes'] ?? ['https'];
        for ($hop = 0; ; $hop++) {
            [$host, $port, $ip] = $this->check($url, $schemes);
            $res = $this->once($method, $url, $host, $port, $ip, $opts);
            if ($res['status'] >= 300 && $res['status'] < 400 && isset($res['headers']['location']) && $follow > 0) {
                if ($hop >= min($follow, 5)) {
                    throw new ApiError('unsafe_url', 'Too many redirects.');
                }
                $url = self::absolute($url, $res['headers']['location']);
                if (isset($opts['to_file'])) {
                    @file_put_contents((string) $opts['to_file'], '');
                }
                if ($res['status'] === 303) {
                    $method = 'GET';
                    unset($opts['body']);
                }
                continue;
            }
            return $res;
        }
    }

    /**
     * @param array<string, mixed> $opts
     * @return array{status: int, headers: array<string, string>, body: string, url: string, bytes: int}
     */
    private function once(string $method, string $url, string $host, int $port, string $ip, array $opts): array
    {
        $ch = curl_init($url);
        $headers = [];
        foreach ((array) ($opts['headers'] ?? []) as $k => $v) {
            $headers[] = is_int($k) ? (string) $v : $k . ': ' . $v;
        }
        $pinHost = trim($host, '[]');
        $pinIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $respHeaders = [];
        $max = (int) ($opts['max_bytes'] ?? 50 * 1024 * 1024);
        $received = 0;
        $body = '';
        $fh = null;
        if (isset($opts['to_file'])) {
            $fh = fopen((string) $opts['to_file'], 'wb');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RESOLVE => [$pinHost . ':' . $port . ':' . $pinIp],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | ($this->allowPrivate ? CURLPROTO_HTTP : 0),
            CURLOPT_CONNECTTIMEOUT => (int) ($opts['connect_timeout'] ?? 15),
            CURLOPT_TIMEOUT => (int) ($opts['timeout'] ?? 600),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'obx-node-php/' . \Obx\Node\Config::VERSION,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                } elseif (preg_match('#^HTTP/#', $line)) {
                    $respHeaders = [];
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$received, $max, $fh): int {
                $received += strlen($chunk);
                if ($received > $max) {
                    return 0;
                }
                if ($fh !== null) {
                    fwrite($fh, $chunk);
                } else {
                    $body .= $chunk;
                }
                return strlen($chunk);
            },
        ]);
        if (isset($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $opts['body']);
        }
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($fh !== null) {
            fclose($fh);
        }
        if ($received > $max) {
            throw new ApiError('payload_too_large', 'The remote file is larger than the limit.');
        }
        if ($ok === false) {
            throw new \RuntimeException('Connection failed: ' . self::scrub($err));
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $body, 'url' => $url, 'bytes' => $received];
    }

    public static function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($location, '//')) {
            return $p['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = rtrim(dirname($p['path'] ?? '/'), '/');
        return $origin . $dir . '/' . $location;
    }

    /** Removes query strings from messages (they may hold signatures). */
    public static function scrub(string $message): string
    {
        return (string) preg_replace('/\?\S*/', '?…', $message);
    }
}
