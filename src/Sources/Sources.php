<?php

declare(strict_types=1);

namespace Obx\Node\Sources;

use Obx\Node\App;
use Obx\Node\Http\ApiError;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Ids;
use Obx\Node\Support\Json;

/**
 * Scheduled pull imports ("automatic upload" from a web address, an SFTP folder or an
 * S3-compatible bucket). Credentials are encrypted at rest and never returned.
 */
final class Sources
{
    public const KINDS = ['https_url', 'sftp', 's3'];

    public function __construct(private App $app)
    {
    }

    /** @param array<string, mixed> $row @return array<string, mixed> ImportSource */
    public function json(array $row): array
    {
        $creds = $this->credentials($row);
        $set = [
            'password' => ($creds['password'] ?? null) !== null,
            'private_key' => ($creds['private_key'] ?? null) !== null,
            'access_key_id' => ($creds['access_key_id'] ?? null) !== null,
            'secret_access_key' => ($creds['secret_access_key'] ?? null) !== null,
            'headers' => array_keys((array) ($creds['headers'] ?? [])),
        ];
        $last = $row['last_run_id'] !== null ? $this->app->db->one('SELECT * FROM obx_import_runs WHERE id = ?', [$row['last_run_id']]) : null;
        $config = Json::decode((string) $row['config'], []);
        $config['kind'] = $row['kind'];
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'kind' => $row['kind'],
            'enabled' => (bool) $row['enabled'],
            'schedule' => Json::decode((string) $row['schedule'], ['frequency' => 'manual']),
            'mode' => $row['mode'],
            'allow_mass_unpublish' => (bool) $row['allow_mass_unpublish'],
            'config' => $config,
            'credentials_set' => $set,
            'last_run' => $last !== null ? $this->app->importer->runJson($last) : null,
            'next_run_at' => $row['next_run_ts'] !== null ? Clock::iso((float) $row['next_run_ts']) : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function credentials(array $row): array
    {
        $plain = $this->app->crypto->decrypt($row['credentials_enc'] ?? null);
        $c = $plain !== null ? Json::decode($plain, []) : [];
        return is_array($c) ? $c : [];
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        $row = $this->app->db->one('SELECT * FROM obx_import_sources WHERE id = ?', [$id]);
        if ($row === null) {
            throw new ApiError('not_found', 'No such scheduled import.');
        }
        return $row;
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function create(array $in): array
    {
        $kind = $in['kind'] ?? ($in['config']['kind'] ?? null);
        if (!in_array($kind, self::KINDS, true)) {
            throw ApiError::validation('/kind', 'https_url, sftp or s3.');
        }
        $now = Clock::iso();
        $row = [
            'id' => Ids::new('src'),
            'name' => 'Import',
            'kind' => $kind,
            'enabled' => 1,
            'schedule' => Json::encode(['frequency' => 'daily', 'time' => '02:00', 'weekday' => null, 'timezone' => 'UTC']),
            'mode' => 'upsert',
            'allow_mass_unpublish' => 0,
            'config' => '{}',
            'credentials_enc' => null,
            'last_run_id' => null,
            'next_run_ts' => null,
            'fingerprint' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $row = array_replace($row, $this->columns($in, $row, true));
        $row['next_run_ts'] = $this->nextRun($row, Clock::time());
        $this->app->db->insert('obx_import_sources', $row);
        return $this->json($this->get((string) $row['id']));
    }

    /** @param array<string, mixed> $patch @return array<string, mixed> */
    public function update(string $id, array $patch): array
    {
        $row = $this->get($id);
        if (isset($patch['kind']) && $patch['kind'] !== $row['kind']) {
            throw ApiError::validation('/kind', 'The kind cannot change; create a new scheduled import.');
        }
        $cols = $this->columns($patch, $row, false);
        $cols['updated_at'] = Clock::iso();
        $merged = array_replace($row, $cols);
        $cols['next_run_ts'] = $this->nextRun($merged, Clock::time());
        $this->app->db->update('obx_import_sources', $cols, ['id' => $id]);
        return $this->json($this->get($id));
    }

    public function delete(string $id): void
    {
        $this->get($id);
        $this->app->db->delete('obx_import_sources', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $in
     * @param array<string, mixed> $row current row
     * @return array<string, mixed> columns
     */
    private function columns(array $in, array $row, bool $create): array
    {
        $out = [];
        $kind = (string) $row['kind'];
        if (array_key_exists('name', $in) || $create) {
            $name = $in['name'] ?? null;
            if (!is_string($name) || trim($name) === '' || mb_strlen($name, 'UTF-8') > 200) {
                throw ApiError::validation('/name', 'Give the import a name.');
            }
            $out['name'] = trim($name);
        }
        if (array_key_exists('enabled', $in)) {
            $out['enabled'] = $in['enabled'] ? 1 : 0;
        }
        if (array_key_exists('mode', $in)) {
            if (!in_array($in['mode'], ['upsert', 'mirror'], true)) {
                throw ApiError::validation('/mode', 'upsert or mirror.');
            }
            $out['mode'] = $in['mode'];
        }
        if (array_key_exists('allow_mass_unpublish', $in)) {
            $out['allow_mass_unpublish'] = $in['allow_mass_unpublish'] ? 1 : 0;
        }
        if (array_key_exists('schedule', $in)) {
            $s = $in['schedule'];
            if (!is_array($s)) {
                throw ApiError::validation('/schedule', 'Must be an object.');
            }
            $cur = Json::decode((string) $row['schedule'], []);
            $s = array_replace((array) $cur, $s);
            if (!in_array($s['frequency'] ?? null, ['manual', 'hourly', 'daily', 'weekly'], true)) {
                throw ApiError::validation('/schedule/frequency', 'manual, hourly, daily or weekly.');
            }
            $time = $s['time'] ?? null;
            if ($time !== null && (!is_string($time) || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time))) {
                throw ApiError::validation('/schedule/time', 'HH:MM, e.g. 02:30.');
            }
            $weekday = $s['weekday'] ?? null;
            if ($weekday !== null && (!is_int($weekday) || $weekday < 1 || $weekday > 7)) {
                throw ApiError::validation('/schedule/weekday', '1 (Monday) to 7 (Sunday).');
            }
            $tz = $s['timezone'] ?? 'UTC';
            if (!is_string($tz) || !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                throw ApiError::validation('/schedule/timezone', 'An IANA time zone such as Europe/Amsterdam.');
            }
            $out['schedule'] = Json::encode(['frequency' => $s['frequency'], 'time' => $time, 'weekday' => $weekday, 'timezone' => $tz]);
        }
        if (array_key_exists('config', $in) || $create) {
            $cfg = $in['config'] ?? null;
            if (!is_array($cfg)) {
                throw ApiError::validation('/config', 'Must be an object.');
            }
            $out['config'] = Json::encode($this->config($kind, $cfg));
        }
        if (array_key_exists('credentials', $in)) {
            $c = $in['credentials'];
            $current = $this->credentials($row);
            if ($c === null) {
                $current = [];
            } elseif (is_array($c)) {
                foreach (['password', 'private_key', 'access_key_id', 'secret_access_key', 'headers'] as $k) {
                    if (!array_key_exists($k, $c)) {
                        continue;
                    }
                    if ($c[$k] === null) {
                        unset($current[$k]);
                        continue;
                    }
                    if ($k === 'headers') {
                        if (!is_array($c[$k]) || count($c[$k]) > 10) {
                            throw ApiError::validation('/credentials/headers', 'Up to 10 headers as {"Name": "value"}.');
                        }
                        foreach ($c[$k] as $hn => $hv) {
                            if (!preg_match('/^[A-Za-z0-9-]{1,100}$/', (string) $hn) || !is_string($hv) || strlen($hv) > 4000 || preg_match('/[\r\n]/', $hv)) {
                                throw ApiError::validation('/credentials/headers', 'Invalid header "' . $hn . '".');
                            }
                        }
                        $current['headers'] = $c[$k];
                    } else {
                        if (!is_string($c[$k]) || strlen($c[$k]) > 20000) {
                            throw ApiError::validation('/credentials/' . $k, 'Must be text.');
                        }
                        $current[$k] = $c[$k];
                    }
                }
            } else {
                throw ApiError::validation('/credentials', 'Must be an object.');
            }
            $out['credentials_enc'] = $current === [] ? null : $this->app->crypto->encrypt(Json::encode($current));
        }
        return $out;
    }

    /** @param array<string, mixed> $c @return array<string, mixed> */
    private function config(string $kind, array $c): array
    {
        unset($c['kind']);
        if ($kind === 'https_url') {
            $url = $c['url'] ?? null;
            if (!is_string($url) || !preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw ApiError::validation('/config/url', 'The https:// address of your products.csv or zip.');
            }
            $this->app->http->check($url);
            $images = $c['images_url'] ?? null;
            if ($images !== null) {
                if (!is_string($images) || filter_var($images, FILTER_VALIDATE_URL) === false) {
                    throw ApiError::validation('/config/images_url', 'An https:// address or empty.');
                }
                $this->app->http->check($images);
            }
            return ['url' => $url, 'images_url' => $images];
        }
        if ($kind === 'sftp') {
            $host = $c['host'] ?? null;
            if (!is_string($host) || !preg_match('/^[A-Za-z0-9.\-:\[\]]{1,255}$/', $host)) {
                throw ApiError::validation('/config/host', 'The server name, e.g. sftp.example.com.');
            }
            $this->app->http->resolve(trim($host, '[]'));
            $port = $c['port'] ?? 22;
            if (!is_int($port) || $port < 1 || $port > 65535) {
                throw ApiError::validation('/config/port', 'A port number, usually 22.');
            }
            foreach (['username', 'path'] as $k) {
                if (!is_string($c[$k] ?? null) || trim($c[$k]) === '') {
                    throw ApiError::validation('/config/' . $k, 'Required.');
                }
            }
            $fp = $c['host_key_fingerprint'] ?? null;
            if ($fp !== null && (!is_string($fp) || !str_starts_with($fp, 'SHA256:'))) {
                throw ApiError::validation('/config/host_key_fingerprint', 'SHA256:… or empty to trust on first use.');
            }
            return ['host' => $host, 'port' => $port, 'username' => trim($c['username']), 'path' => trim($c['path']), 'host_key_fingerprint' => $fp];
        }
        $endpoint = $c['endpoint'] ?? null;
        if ($endpoint !== null) {
            if (!is_string($endpoint) || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
                throw ApiError::validation('/config/endpoint', 'The S3 endpoint URL, or empty for AWS.');
            }
            $this->app->http->check($endpoint);
        }
        foreach (['region', 'bucket'] as $k) {
            if (!is_string($c[$k] ?? null) || trim($c[$k]) === '') {
                throw ApiError::validation('/config/' . $k, 'Required.');
            }
        }
        return [
            'endpoint' => $endpoint,
            'region' => trim($c['region']),
            'bucket' => trim($c['bucket']),
            'prefix' => is_string($c['prefix'] ?? null) ? ltrim($c['prefix'], '/') : '',
            'path_style' => (bool) ($c['path_style'] ?? false),
        ];
    }

    /** @param array<string, mixed> $row */
    public function nextRun(array $row, int $after): ?int
    {
        if (!(int) $row['enabled']) {
            return null;
        }
        $s = Json::decode((string) $row['schedule'], []);
        $freq = $s['frequency'] ?? 'manual';
        if ($freq === 'manual') {
            return null;
        }
        if ($freq === 'hourly') {
            return (intdiv($after, 3600) + 1) * 3600;
        }
        $tz = new \DateTimeZone((string) ($s['timezone'] ?? 'UTC'));
        [$h, $m] = array_map('intval', explode(':', (string) ($s['time'] ?? '02:00')));
        $d = (new \DateTimeImmutable('@' . $after))->setTimezone($tz)->setTime($h, $m);
        if ($freq === 'daily') {
            while ($d->getTimestamp() <= $after) {
                $d = $d->modify('+1 day')->setTime($h, $m);
            }
            return $d->getTimestamp();
        }
        $weekday = (int) ($s['weekday'] ?? 1);
        for ($i = 0; $i < 8; $i++) {
            if ((int) $d->format('N') === $weekday && $d->getTimestamp() > $after) {
                return $d->getTimestamp();
            }
            $d = $d->modify('+1 day')->setTime($h, $m);
        }
        return $d->getTimestamp();
    }

    /** Queues a run. @return array<string, mixed> ImportRun */
    public function run(string $id, bool $dryRun, bool $force, string $trigger): array
    {
        $row = $this->get($id);
        $busy = $this->app->db->one("SELECT id FROM obx_import_runs WHERE source_id = ? AND status IN ('queued', 'running')", [$id]);
        if ($busy !== null) {
            throw new ApiError('conflict', 'This import is already running.');
        }
        $runId = Ids::new('imp');
        $options = ['mode' => $row['mode'], 'allow_mass_unpublish' => (bool) $row['allow_mass_unpublish'], 'dry_run' => $dryRun, 'default_language' => null];
        $job = $this->app->importer->queue($runId, $options + ['source' => ['id' => $id, 'force' => $force]], null, $trigger, $id, [], 'source', $id);
        $run = $this->app->db->one('SELECT * FROM obx_import_runs WHERE id = ?', [$runId]);
        return $this->app->importer->runJson($run);
    }

    /** Runs scheduled sources that are due. Returns how many were queued. */
    public function runDue(): int
    {
        $n = 0;
        $now = Clock::time();
        foreach ($this->app->db->all('SELECT * FROM obx_import_sources WHERE enabled = 1 AND next_run_ts IS NOT NULL AND next_run_ts <= ?', [$now]) as $row) {
            $this->app->db->update('obx_import_sources', ['next_run_ts' => $this->nextRun($row, $now)], ['id' => $row['id']]);
            try {
                $this->run((string) $row['id'], false, false, 'schedule');
                $n++;
            } catch (ApiError) {
                // Already running: the next slot will try again.
            }
        }
        return $n;
    }

    /**
     * Downloads the source into the staging input directory. Returns false when the remote
     * files did not change since the last successful run (and $force is false).
     */
    public function fetch(string $id, string $inputDir, bool $force): bool
    {
        $row = $this->get($id);
        $fetcher = new Fetcher($this->app, $row, Json::decode((string) $row['config'], []), $this->credentials($row));
        $fingerprint = $fetcher->fetch($inputDir, $force);
        if (!$force && $fingerprint === $row['fingerprint']) {
            return false;
        }
        $this->app->db->update('obx_import_sources', ['fingerprint' => $fingerprint], ['id' => $id]);
        if ($fetcher->pinnedFingerprint !== null) {
            $cfg = Json::decode((string) $row['config'], []);
            $cfg['host_key_fingerprint'] = $fetcher->pinnedFingerprint;
            $this->app->db->update('obx_import_sources', ['config' => Json::encode($cfg)], ['id' => $id]);
        }
        return true;
    }

    /** @return array<string, mixed> test result */
    public function test(string $id): array
    {
        $row = $this->get($id);
        $fetcher = new Fetcher($this->app, $row, Json::decode((string) $row['config'], []), $this->credentials($row));
        try {
            $files = $fetcher->listing();
            return ['ok' => true, 'message' => count($files) . ' file(s) found.', 'host_key_fingerprint' => $fetcher->seenFingerprint, 'files' => array_slice($files, 0, 100)];
        } catch (ApiError $e) {
            if ($e->obxError === 'unsafe_url') {
                throw $e;
            }
            return ['ok' => false, 'message' => $e->getMessage(), 'host_key_fingerprint' => $fetcher->seenFingerprint, 'files' => []];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => \Obx\Node\Support\Log::scrub($e->getMessage()), 'host_key_fingerprint' => $fetcher->seenFingerprint, 'files' => []];
        }
    }
}
