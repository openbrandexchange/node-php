<?php

declare(strict_types=1);

namespace Obx\Node\Import;

use Obx\Node\App;
use Obx\Node\Http\ApiError;
use Obx\Node\Http\Request;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Ids;
use Obx\Node\Support\Json;

/**
 * Import runs: staging of uploaded files, job lifecycle, ImportRun documents and errors.
 * The parsing and applying is done by ImportEngine.
 */
final class Importer
{
    public const MAX_IMPORT_BYTES = 524288000;

    public function __construct(private App $app)
    {
    }

    public function stagingDir(string $runId): string
    {
        return $this->app->config->dataDir() . '/imports/' . $runId;
    }

    /** @return array{mode: string, allow_mass_unpublish: bool, dry_run: bool, default_language: ?string} */
    public function parseOptions(mixed $raw): array
    {
        $o = [];
        if (is_string($raw) && trim($raw) !== '') {
            $o = json_decode($raw, true);
            if (!is_array($o)) {
                throw new ApiError('invalid_request', 'options must be a JSON object.');
            }
        } elseif (is_array($raw)) {
            $o = $raw;
        }
        $mode = $o['mode'] ?? 'upsert';
        if (!in_array($mode, ['upsert', 'mirror'], true)) {
            throw ApiError::validation('/options/mode', 'upsert or mirror.');
        }
        $lang = $o['default_language'] ?? null;
        if ($lang !== null && (!is_string($lang) || !preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $lang))) {
            throw ApiError::validation('/options/default_language', 'A language code such as nl or en.');
        }
        return [
            'mode' => $mode,
            'allow_mass_unpublish' => (bool) ($o['allow_mass_unpublish'] ?? false),
            'dry_run' => (bool) ($o['dry_run'] ?? false),
            'default_language' => $lang,
        ];
    }

    /**
     * Stages the multipart fields of an import request and queues the job.
     *
     * @param array{mode: string, allow_mass_unpublish: bool, dry_run: bool, default_language: ?string} $options
     * @param list<string>|null $brands token brand restriction
     * @return array<string, mixed> job row
     */
    public function queueFromRequest(Request $req, array $options, ?array $brands, string $trigger, string $ownerType, string $ownerId, ?string $idemKey): array
    {
        $runId = Ids::new('imp');
        $dir = $this->stagingDir($runId) . '/input';
        @mkdir($dir . '/images', 0770, true);
        $files = [];
        $total = 0;
        $take = function (array $f, string $target) use (&$files, &$total): void {
            $total += (int) $f['size'];
            if ($total > self::MAX_IMPORT_BYTES) {
                throw new ApiError('payload_too_large', 'An import can be at most 500 MB. Split it or use a scheduled import.');
            }
            if (!(is_uploaded_file($f['tmp']) && move_uploaded_file($f['tmp'], $target))) {
                if (!@rename($f['tmp'], $target)) {
                    copy($f['tmp'], $target);
                }
            }
            $files[] = ['name' => $f['name'], 'bytes' => (int) $f['size'], 'sha256' => (string) hash_file('sha256', $target)];
        };
        $text = function (string $field, string $target, string $label) use ($req, &$files): bool {
            $v = $req->post[$field] ?? null;
            if (!is_string($v) || trim($v) === '') {
                return false;
            }
            file_put_contents($target, $v);
            $files[] = ['name' => $label, 'bytes' => strlen($v), 'sha256' => hash('sha256', $v)];
            return true;
        };
        $any = false;
        if (isset($req->files['package'][0])) {
            $take($req->files['package'][0], $dir . '/package.zip');
            $any = true;
        }
        if (isset($req->files['csv'][0])) {
            $take($req->files['csv'][0], $dir . '/products.csv');
            $any = true;
        } elseif ($text('csv', $dir . '/products.csv', 'products.csv')) {
            $any = true;
        }
        if (isset($req->files['assets_csv'][0])) {
            $take($req->files['assets_csv'][0], $dir . '/assets.csv');
            $any = true;
        } elseif ($text('assets_csv', $dir . '/assets.csv', 'assets.csv')) {
            $any = true;
        }
        $images = [];
        foreach ($req->files['images'] ?? [] as $i => $f) {
            $name = basename(str_replace('\\', '/', (string) $f['name']));
            $target = $dir . '/images/u' . $i;
            $take($f, $target);
            $images[] = ['name' => $name, 'path' => 'images/u' . $i];
            $any = true;
        }
        file_put_contents($dir . '/images.json', Json::encode($images));
        $manifest = $req->post['images_manifest'] ?? null;
        if ($manifest === null && isset($req->files['images_manifest'][0])) {
            $manifest = (string) file_get_contents($req->files['images_manifest'][0]['tmp']);
        }
        if (is_string($manifest) && trim($manifest) !== '') {
            $m = json_decode($manifest, true);
            if (!is_array($m) || !array_is_list($m)) {
                throw new ApiError('invalid_request', 'images_manifest must be a JSON list of {file, sha256}.');
            }
            if (count($m) > 100000) {
                throw new ApiError('limit_exceeded', 'At most 100000 manifest entries.');
            }
            file_put_contents($dir . '/manifest.json', Json::encode($m));
            $any = $any || $m !== [];
        }
        if (!$any) {
            $this->removeDir($this->stagingDir($runId));
            throw new ApiError('invalid_request', 'Send a package zip, a products CSV and/or images.');
        }
        return $this->queue($runId, $options, $brands, $trigger, null, $files, $ownerType, $ownerId, $idemKey);
    }

    /**
     * Creates the import run and its job for an already staged directory.
     *
     * @param array<string, mixed> $options
     * @param list<string>|null $brands
     * @param list<array<string, mixed>> $files
     * @return array<string, mixed> job row
     */
    public function queue(string $runId, array $options, ?array $brands, string $trigger, ?string $sourceId, array $files, ?string $ownerType, ?string $ownerId, ?string $idemKey = null): array
    {
        $now = Clock::iso();
        $run = [
            'id' => $runId,
            'job_id' => null,
            'source_id' => $sourceId,
            'trigger_kind' => $trigger,
            'status' => 'queued',
            'mode' => $options['mode'],
            'dry_run' => $options['dry_run'] ? 1 : 0,
            'files' => Json::encode($files),
            'counts' => Json::encode(self::zeroCounts()),
            'error' => null,
            'created_at' => $now,
            'created_ts' => Clock::time(),
            'started_at' => null,
            'finished_at' => null,
        ];
        $this->app->db->insert('obx_import_runs', $run);
        $job = $this->app->jobs->create('import', $ownerType, $ownerId, [
            'run_id' => $runId,
            'options' => $options,
            'brands' => $brands,
        ], $idemKey);
        $this->app->db->update('obx_import_runs', ['job_id' => $job['id']], ['id' => $runId]);
        return $job;
    }

    /** @return array<string, int> */
    public static function zeroCounts(): array
    {
        return ['rows' => 0, 'files' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'unpublished' => 0, 'errors' => 0, 'warnings' => 0];
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $payload
     * @return array<string, int> IngestJob summary
     */
    public function runJob(array $job, array $payload): array
    {
        $runId = (string) $payload['run_id'];
        $this->app->db->update('obx_import_runs', ['status' => 'running', 'started_at' => Clock::iso()], ['id' => $runId]);
        $engine = new ImportEngine($this->app, (array) $payload['options'], $payload['brands'] ?? null, (string) $job['id']);
        try {
            $source = $payload['options']['source'] ?? null;
            if (is_array($source)) {
                $input = $this->stagingDir($runId) . '/input';
                @mkdir($input, 0770, true);
                $changed = $this->app->sources->fetch((string) $source['id'], $input, (bool) ($source['force'] ?? false));
                $files = [];
                // Staged uploads have internal names (images/u0); show the names they came with.
                $display = [];
                foreach ((array) Json::decode((string) @file_get_contents($input . '/images.json'), []) as $img) {
                    if (is_array($img) && isset($img['path'], $img['name'])) {
                        $display[(string) $img['path']] = (string) $img['name'];
                    }
                }
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($input, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile() && !str_ends_with($f->getFilename(), '.json')) {
                        $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($f->getPathname(), strlen($input) + 1));
                        $files[] = ['name' => $display[$rel] ?? $rel, 'bytes' => (int) $f->getSize()];
                    }
                }
                $this->app->db->update('obx_import_runs', ['files' => Json::encode(array_slice($files, 0, 1000))], ['id' => $runId]);
                if (!$changed) {
                    $this->app->db->update('obx_import_runs', ['status' => 'succeeded', 'finished_at' => Clock::iso()], ['id' => $runId]);
                    $this->completed($runId);
                    $this->removeDir($this->stagingDir($runId));
                    return ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0, 'errors' => 0];
                }
            }
            $engine->run($this->stagingDir($runId));
        } catch (ApiError $e) {
            $this->storeErrors($runId, $engine->errors);
            $this->app->db->update('obx_import_runs', [
                'status' => 'failed',
                'finished_at' => Clock::iso(),
                'counts' => Json::encode($engine->counts()),
                'error' => Json::encode($e->problem()),
            ], ['id' => $runId]);
            $this->completed($runId);
            $this->removeDir($this->stagingDir($runId));
            throw $e;
        }
        $this->storeErrors($runId, $engine->errors);
        $counts = $engine->counts();
        $this->app->db->update('obx_import_runs', [
            'status' => 'succeeded',
            'finished_at' => Clock::iso(),
            'counts' => Json::encode($counts),
        ], ['id' => $runId]);
        $this->completed($runId);
        $this->removeDir($this->stagingDir($runId));
        return [
            'created' => $counts['created'],
            'updated' => $counts['updated'],
            'unchanged' => $counts['unchanged'],
            'deleted' => $counts['unpublished'],
            'errors' => $counts['errors'],
        ];
    }

    private function completed(string $runId): void
    {
        $run = $this->app->db->one('SELECT * FROM obx_import_runs WHERE id = ?', [$runId]);
        if ($run === null) {
            return;
        }
        if ($run['source_id'] !== null) {
            $this->app->db->update('obx_import_sources', ['last_run_id' => $runId], ['id' => $run['source_id']]);
        }
        $this->app->webhooks->admin('import_run.completed', [
            'import_run_id' => $runId,
            'status' => $run['status'],
            'counts' => Json::decode((string) $run['counts'], self::zeroCounts()),
        ]);
    }

    /** @param array<string, mixed> $job @param array<string, mixed> $problem */
    public function failRun(array $job, array $problem): void
    {
        $run = $this->app->db->one('SELECT * FROM obx_import_runs WHERE job_id = ?', [$job['id']]);
        if ($run !== null && in_array($run['status'], ['queued', 'running'], true)) {
            $this->app->db->update('obx_import_runs', ['status' => 'failed', 'finished_at' => Clock::iso(), 'error' => Json::encode($problem)], ['id' => $run['id']]);
            $this->removeDir($this->stagingDir((string) $run['id']));
        }
    }

    /** @param list<array<string, mixed>> $errors */
    private function storeErrors(string $runId, array $errors): void
    {
        foreach ($errors as $i => $e) {
            $this->app->db->insert('obx_import_errors', ['run_id' => $runId, 'idx' => $i, 'item' => Json::encode($e)]);
        }
    }

    /** @param array<string, mixed> $r @return array<string, mixed> ImportRun */
    public function runJson(array $r): array
    {
        $preview = array_map(
            static fn ($row) => Json::decode((string) $row['item'], []),
            $this->app->db->all('SELECT item FROM obx_import_errors WHERE run_id = ? ORDER BY idx LIMIT 50', [$r['id']])
        );
        return [
            'id' => $r['id'],
            'source_id' => $r['source_id'],
            'trigger' => $r['trigger_kind'],
            'status' => $r['status'],
            'mode' => $r['mode'],
            'dry_run' => (bool) $r['dry_run'],
            'created_at' => $r['created_at'],
            'started_at' => $r['started_at'],
            'finished_at' => $r['finished_at'],
            'files' => Json::decode((string) $r['files'], []),
            'counts' => Json::decode((string) $r['counts'], self::zeroCounts()),
            'error' => $r['error'] !== null ? Json::decode((string) $r['error']) : null,
            'errors_preview' => $preview,
        ];
    }

    /** @return array{items: list<array<string, mixed>>, next: ?int} */
    public function errors(string $runId, int $offset, int $limit, bool $errorsOnly = false): array
    {
        $rows = $this->app->db->all('SELECT idx, item FROM obx_import_errors WHERE run_id = ? AND idx >= ? ORDER BY idx', [$runId, $offset]);
        $items = [];
        $next = null;
        foreach ($rows as $r) {
            $item = Json::decode((string) $r['item'], []);
            if ($errorsOnly && ($item['severity'] ?? '') !== 'error') {
                continue;
            }
            if (count($items) === $limit) {
                $next = (int) $r['idx'];
                break;
            }
            $items[] = $item;
        }
        return ['items' => $items, 'next' => $next];
    }

    public function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
