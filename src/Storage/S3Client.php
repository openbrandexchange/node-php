<?php

declare(strict_types=1);

namespace Obx\Node\Storage;

use Obx\Node\Security\SafeHttp;

/**
 * Minimal S3-compatible client (AWS Signature V4): list, get, put, head, delete.
 * Works with AWS S3, Cloudflare R2, MinIO, Backblaze B2 (S3 API), Wasabi.
 */
final class S3Client
{
    public function __construct(
        private string $bucket,
        private string $region,
        private string $accessKey,
        private string $secretKey,
        private ?string $endpoint = null,
        private bool $pathStyle = false,
        private ?SafeHttp $http = null,
    ) {
        $this->region = $region !== '' ? $region : 'us-east-1';
    }

    private function base(): array
    {
        if ($this->endpoint !== null && $this->endpoint !== '') {
            $ep = rtrim($this->endpoint, '/');
            $p = parse_url($ep);
            $scheme = $p['scheme'] ?? 'https';
            $host = $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
            if ($this->pathStyle) {
                return [$scheme, $host, '/' . $this->bucket];
            }
            return [$scheme, $this->bucket . '.' . $host, ''];
        }
        $host = 's3.' . $this->region . '.amazonaws.com';
        if ($this->pathStyle) {
            return ['https', $host, '/' . $this->bucket];
        }
        return ['https', $this->bucket . '.' . $host, ''];
    }

    private static function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param array<string, mixed> $opts
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function request(string $method, string $key, array $query = [], array $headers = [], ?string $bodyFile = null, array $opts = []): array
    {
        [$scheme, $host, $prefix] = $this->base();
        $path = $prefix . '/' . ($key === '' ? '' : self::encodeKey($key));
        if ($key === '' && $prefix !== '') {
            $path = $prefix . '/';
        }
        ksort($query);
        $qs = [];
        foreach ($query as $k => $v) {
            $qs[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        $canonicalQuery = implode('&', $qs);
        $payloadHash = $bodyFile !== null ? hash_file('sha256', $bodyFile) : hash('sha256', '');
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $headers = array_change_key_case($headers, CASE_LOWER);
        $headers['host'] = $host;
        $headers['x-amz-date'] = $now;
        $headers['x-amz-content-sha256'] = $payloadHash;
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim((string) $v) . "\n";
        }
        $signed = implode(';', array_keys($headers));
        $canonical = $method . "\n" . $path . "\n" . $canonicalQuery . "\n" . $canonicalHeaders . "\n" . $signed . "\n" . $payloadHash;
        $scope = $date . '/' . $this->region . '/s3/aws4_request';
        $toSign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonical);
        $k = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $k = hash_hmac('sha256', $this->region, $k, true);
        $k = hash_hmac('sha256', 's3', $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);
        $sig = hash_hmac('sha256', $toSign, $k);
        $headers['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $sig;
        $url = $scheme . '://' . $host . $path . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');
        $sendHeaders = [];
        foreach ($headers as $hk => $hv) {
            if ($hk !== 'host') {
                $sendHeaders[$hk] = $hv;
            }
        }
        if ($this->http !== null) {
            $o = ['headers' => $sendHeaders, 'schemes' => ['https'], 'timeout' => 600] + $opts;
            if ($bodyFile !== null) {
                $o['body'] = (string) file_get_contents($bodyFile);
            }
            $r = $this->http->request($method, $url, $o);
            return ['status' => $r['status'], 'headers' => $r['headers'], 'body' => $r['body']];
        }
        return $this->plain($method, $url, $sendHeaders, $bodyFile, $opts);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $opts
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function plain(string $method, string $url, array $headers, ?string $bodyFile, array $opts): array
    {
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $respHeaders = [];
        $fh = isset($opts['to_file']) ? fopen((string) $opts['to_file'], 'wb') : null;
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $fh): int {
                if ($fh !== null) {
                    fwrite($fh, $chunk);
                } else {
                    $body .= $chunk;
                }
                return strlen($chunk);
            },
        ]);
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }
        $in = null;
        if ($bodyFile !== null) {
            $in = fopen($bodyFile, 'rb');
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $in);
            curl_setopt($ch, CURLOPT_INFILESIZE, filesize($bodyFile));
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($fh !== null) {
            fclose($fh);
        }
        if ($in !== null) {
            fclose($in);
        }
        if ($ok === false) {
            throw new \RuntimeException('S3 request failed: ' . SafeHttp::scrub($err));
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $body];
    }

    /** @return list<array{key: string, size: int, etag: string, modified_at: ?string}> */
    public function list(string $prefix, int $max = 100000): array
    {
        $out = [];
        $token = null;
        do {
            $q = ['list-type' => '2', 'prefix' => $prefix, 'max-keys' => '1000'];
            if ($token !== null) {
                $q['continuation-token'] = $token;
            }
            $r = $this->request('GET', '', $q);
            if ($r['status'] !== 200) {
                throw new \RuntimeException('Listing the bucket failed (HTTP ' . $r['status'] . ').');
            }
            $xml = @simplexml_load_string($r['body']);
            if ($xml === false) {
                throw new \RuntimeException('The bucket listing could not be read.');
            }
            foreach ($xml->Contents as $c) {
                $out[] = [
                    'key' => (string) $c->Key,
                    'size' => (int) $c->Size,
                    'etag' => trim((string) $c->ETag, '"'),
                    'modified_at' => (string) $c->LastModified ?: null,
                ];
                if (count($out) >= $max) {
                    return $out;
                }
            }
            $token = ((string) $xml->IsTruncated) === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '');
        return $out;
    }

    public function get(string $key, string $toFile, int $maxBytes = PHP_INT_MAX): void
    {
        $r = $this->request('GET', $key, [], [], null, ['to_file' => $toFile, 'max_bytes' => $maxBytes]);
        if ($r['status'] !== 200) {
            throw new \RuntimeException('Downloading ' . $key . ' failed (HTTP ' . $r['status'] . ').');
        }
    }

    public function put(string $key, string $file, string $contentType): void
    {
        $r = $this->request('PUT', $key, [], ['content-type' => $contentType], $file);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new \RuntimeException('Uploading to the bucket failed (HTTP ' . $r['status'] . ').');
        }
    }

    public function exists(string $key): bool
    {
        return $this->request('HEAD', $key)['status'] === 200;
    }

    public function delete(string $key): void
    {
        $this->request('DELETE', $key);
    }
}
