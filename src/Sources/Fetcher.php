<?php

declare(strict_types=1);

namespace Obx\Node\Sources;

use Obx\Node\App;
use Obx\Node\Http\ApiError;
use Obx\Node\Import\Formats;
use Obx\Node\Import\SafeZip;
use Obx\Node\Storage\S3Client;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

/**
 * Downloads a scheduled import source into a staging directory:
 * input/package.zip, input/products.csv or input/products.xlsx (+ images), or input/tree/<files> for folders.
 * Returns a fingerprint of the remote state so unchanged sources are skipped.
 */
final class Fetcher
{
    public ?string $seenFingerprint = null;
    public ?string $pinnedFingerprint = null;
    private const MAX_FILES = 20000;

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $config
     * @param array<string, mixed> $creds
     */
    public function __construct(private App $app, private array $row, private array $config, private array $creds)
    {
    }

    private function maxBytes(): int
    {
        return 524288000;
    }

    private bool $force = false;

    public function fetch(string $inputDir, bool $force = false): string
    {
        $this->force = $force;
        return match ($this->row['kind']) {
            'https_url' => $this->fetchHttps($inputDir),
            'sftp' => $this->fetchSftp($inputDir),
            's3' => $this->fetchS3($inputDir),
            default => throw new ApiError('invalid_request', 'Unknown source kind.'),
        };
    }

    /** @return list<array{name: string, bytes: int, modified_at: ?string}> */
    public function listing(): array
    {
        return match ($this->row['kind']) {
            'https_url' => $this->listHttps(),
            'sftp' => $this->listSftp(),
            's3' => $this->listS3(),
            default => [],
        };
    }

    /** products.xlsx and assets.xlsx take the place of the CSV files in a folder (import-format.md §5.1). */
    private static function isWorkbookName(string $name): bool
    {
        return in_array(strtolower(basename($name)), ['products.xlsx', 'assets.xlsx'], true);
    }

    // ================================================================ https

    /** @return array<string, string> */
    private function headers(): array
    {
        return (array) ($this->creds['headers'] ?? []);
    }

    private function fetchHttps(string $in): string
    {
        $tmp = $in . '/download';
        $r = $this->app->http->request('GET', (string) $this->config['url'], ['headers' => $this->headers(), 'to_file' => $tmp, 'follow' => 5, 'max_bytes' => $this->maxBytes()]);
        if ($r['status'] !== 200) {
            @unlink($tmp);
            throw new ApiError('service_unavailable', 'The web address answered HTTP ' . $r['status'] . '.');
        }
        $head = (string) file_get_contents($tmp, false, null, 0, 4);
        // An address whose path ends in .xlsx is a products workbook (import-format.md §5.1);
        // otherwise the first bytes tell a package zip from a CSV.
        $target = \Obx\Node\Import\Xlsx::isWorkbook(basename((string) parse_url((string) $this->config['url'], PHP_URL_PATH)))
            ? $in . '/products.xlsx'
            : ($head === "PK\x03\x04" ? $in . '/package.zip' : $in . '/products.csv');
        rename($tmp, $target);
        $hashes = [hash_file('sha256', $target)];
        $images = [];
        if (!empty($this->config['images_url'])) {
            @mkdir($in . '/images', 0770, true);
            $r2 = $this->app->http->request('GET', (string) $this->config['images_url'], ['headers' => $this->headers(), 'to_file' => $in . '/images/u0', 'follow' => 5, 'max_bytes' => $this->maxBytes()]);
            if ($r2['status'] !== 200) {
                throw new ApiError('service_unavailable', 'The images address answered HTTP ' . $r2['status'] . '.');
            }
            $images[] = ['name' => 'images.zip', 'path' => 'images/u0'];
            $hashes[] = hash_file('sha256', $in . '/images/u0');
        }
        file_put_contents($in . '/images.json', json_encode($images));
        return hash('sha256', implode(',', $hashes));
    }

    /** @return list<array{name: string, bytes: int, modified_at: ?string}> */
    private function listHttps(): array
    {
        $out = [];
        foreach (array_filter([$this->config['url'] ?? null, $this->config['images_url'] ?? null]) as $url) {
            $r = $this->app->http->request('HEAD', (string) $url, ['headers' => $this->headers(), 'follow' => 5, 'timeout' => 30]);
            if ($r['status'] >= 400) {
                throw new ApiError('service_unavailable', 'The web address answered HTTP ' . $r['status'] . '.');
            }
            $out[] = [
                'name' => basename((string) parse_url((string) $url, PHP_URL_PATH)) ?: (string) $url,
                'bytes' => (int) ($r['headers']['content-length'] ?? 0),
                'modified_at' => isset($r['headers']['last-modified']) ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($r['headers']['last-modified'])) : null,
            ];
        }
        return $out;
    }

    // ================================================================ sftp

    private function sftp(): SFTP
    {
        $host = trim((string) $this->config['host'], '[]');
        $ip = $this->app->http->resolve($host);
        $sftp = new SFTP($ip, (int) ($this->config['port'] ?? 22), 15);
        $key = $sftp->getServerPublicHostKey();
        if (!is_string($key)) {
            throw new ApiError('service_unavailable', 'The SFTP server did not answer.');
        }
        $parts = explode(' ', $key);
        $blob = base64_decode($parts[1] ?? '', true);
        $fp = 'SHA256:' . rtrim(base64_encode(hash('sha256', (string) $blob, true)), '=');
        $this->seenFingerprint = $fp;
        $pinned = $this->config['host_key_fingerprint'] ?? null;
        if ($pinned !== null && $pinned !== '' && !hash_equals((string) $pinned, $fp)) {
            throw new ApiError('forbidden', 'The SFTP server key changed (expected ' . $pinned . ', got ' . $fp . '). If this is expected, clear the pinned key.');
        }
        if ($pinned === null || $pinned === '') {
            $this->pinnedFingerprint = $fp;
        }
        $user = (string) $this->config['username'];
        $ok = false;
        if (!empty($this->creds['private_key'])) {
            $k = PublicKeyLoader::loadPrivateKey((string) $this->creds['private_key'], $this->creds['password'] ?? false);
            $ok = $sftp->login($user, $k);
        } elseif (isset($this->creds['password'])) {
            $ok = $sftp->login($user, (string) $this->creds['password']);
        }
        if (!$ok) {
            throw new ApiError('forbidden', 'The SFTP login failed. Check the user name and password or key.');
        }
        return $sftp;
    }

    /** @return list<array{path: string, rel: string, size: int, mtime: int}> */
    private function sftpFiles(SFTP $sftp): array
    {
        $root = rtrim((string) $this->config['path'], '/');
        if ($root === '') {
            $root = '.';
        }
        if (preg_match('/\.(zip|csv|xlsx)$/i', $root) && $sftp->is_file($root)) {
            $st = $sftp->stat($root);
            return [['path' => $root, 'rel' => basename($root), 'size' => (int) ($st['size'] ?? 0), 'mtime' => (int) ($st['mtime'] ?? 0)]];
        }
        $out = [];
        $walk = function (string $dir, string $rel, int $depth) use (&$walk, &$out, $sftp): void {
            if ($depth > 6 || count($out) >= self::MAX_FILES) {
                return;
            }
            $list = $sftp->rawlist($dir);
            if (!is_array($list)) {
                throw new ApiError('not_found', 'The folder "' . $dir . '" does not exist on the SFTP server.');
            }
            ksort($list);
            foreach ($list as $name => $st) {
                $name = (string) $name;
                if ($name === '.' || $name === '..' || $name[0] === '.' || SafeZip::ignored($name)) {
                    continue;
                }
                $type = (int) ($st['type'] ?? 0);
                if ($type === 2) { // directory
                    $walk($dir . '/' . $name, $rel . $name . '/', $depth + 1);
                } elseif ($type === 1 && (str_ends_with(strtolower($name), '.csv') || self::isWorkbookName($name) || Formats::isImageName($name))) {
                    $out[] = ['path' => $dir . '/' . $name, 'rel' => $rel . $name, 'size' => (int) ($st['size'] ?? 0), 'mtime' => (int) ($st['mtime'] ?? 0)];
                }
            }
        };
        $walk($root, '', 0);
        return $out;
    }

    private function fetchSftp(string $in): string
    {
        $sftp = $this->sftp();
        $files = $this->sftpFiles($sftp);
        if ($files === []) {
            throw new ApiError('not_found', 'No products.csv or images were found in the SFTP folder.');
        }
        $fp = hash('sha256', json_encode(array_map(static fn ($f) => [$f['rel'], $f['size'], $f['mtime']], $files)));
        if (!$this->force && $fp === ($this->row['fingerprint'] ?? null)) {
            return $fp;
        }
        $total = 0;
        foreach ($files as $f) {
            $total += $f['size'];
            if ($total > $this->maxBytes()) {
                throw new ApiError('payload_too_large', 'The SFTP folder holds more than 500 MB.');
            }
            if (count($files) === 1 && str_ends_with(strtolower($f['rel']), '.zip')) {
                $target = $in . '/package.zip';
            } elseif (count($files) === 1 && str_ends_with(strtolower($f['rel']), '.csv')) {
                $target = $in . '/products.csv';
            } elseif (count($files) === 1 && preg_match('/\.xlsx$/i', (string) $this->config['path'])) {
                $target = $in . '/products.xlsx'; // the path names the workbook itself
            } else {
                $target = $in . '/tree/' . \Obx\Node\Zip\ZipWriter::sanitize($f['rel']);
            }
            @mkdir(dirname($target), 0770, true);
            if ($sftp->get($f['path'], $target) === false) {
                throw new ApiError('service_unavailable', 'Downloading ' . $f['rel'] . ' failed.');
            }
        }
        return $fp;
    }

    /** @return list<array{name: string, bytes: int, modified_at: ?string}> */
    private function listSftp(): array
    {
        $sftp = $this->sftp();
        return array_map(static fn ($f) => ['name' => $f['rel'], 'bytes' => $f['size'], 'modified_at' => $f['mtime'] > 0 ? gmdate('Y-m-d\TH:i:s\Z', $f['mtime']) : null], $this->sftpFiles($sftp));
    }

    // ================================================================ s3

    private function s3(): S3Client
    {
        return new S3Client(
            (string) $this->config['bucket'],
            (string) ($this->config['region'] ?? 'us-east-1'),
            (string) ($this->creds['access_key_id'] ?? ''),
            (string) ($this->creds['secret_access_key'] ?? ''),
            $this->config['endpoint'] ?? null,
            (bool) ($this->config['path_style'] ?? false),
            $this->app->http,
        );
    }

    /** @return list<array{key: string, size: int, etag: string, modified_at: ?string}> */
    private function s3Objects(S3Client $s3): array
    {
        $prefix = (string) ($this->config['prefix'] ?? '');
        $objects = $s3->list($prefix, self::MAX_FILES);
        return array_values(array_filter($objects, static function ($o) {
            $name = basename($o['key']);
            return !str_ends_with($o['key'], '/') && !SafeZip::ignored($o['key']) && (str_ends_with(strtolower($name), '.csv') || str_ends_with(strtolower($name), '.zip') || self::isWorkbookName($name) || Formats::isImageName($name));
        }));
    }

    private function fetchS3(string $in): string
    {
        $s3 = $this->s3();
        $objects = $this->s3Objects($s3);
        if ($objects === []) {
            throw new ApiError('not_found', 'No products.csv or images were found under this prefix.');
        }
        $fp = hash('sha256', json_encode(array_map(static fn ($o) => [$o['key'], $o['size'], $o['etag']], $objects)));
        if (!$this->force && $fp === ($this->row['fingerprint'] ?? null)) {
            return $fp;
        }
        $prefix = (string) ($this->config['prefix'] ?? '');
        $total = 0;
        foreach ($objects as $o) {
            $total += $o['size'];
            if ($total > $this->maxBytes()) {
                throw new ApiError('payload_too_large', 'The bucket prefix holds more than 500 MB.');
            }
            $rel = ltrim(substr($o['key'], strlen($prefix)), '/');
            if ($rel === '') {
                $rel = basename($o['key']);
            }
            if (count($objects) === 1 && str_ends_with(strtolower($rel), '.zip')) {
                $target = $in . '/package.zip';
            } elseif (str_ends_with(strtolower($rel), '.zip')) {
                continue;
            } else {
                $target = $in . '/tree/' . \Obx\Node\Zip\ZipWriter::sanitize($rel);
            }
            @mkdir(dirname($target), 0770, true);
            $s3->get($o['key'], $target, $this->maxBytes());
        }
        return $fp;
    }

    /** @return list<array{name: string, bytes: int, modified_at: ?string}> */
    private function listS3(): array
    {
        return array_map(static fn ($o) => ['name' => $o['key'], 'bytes' => $o['size'], 'modified_at' => $o['modified_at']], $this->s3Objects($this->s3()));
    }
}
