<?php

declare(strict_types=1);

namespace Obx\Node\Jobs;

use Obx\Node\App;
use Obx\Node\Http\ApiError;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Ids;
use Obx\Node\Support\Json;

/**
 * Background jobs (batches, imports, exports, download zips). Jobs are rows in obx_jobs; a
 * worker (cron, `php bin/obx worker`, or the request that queued them after its response)
 * claims and runs them.
 */
final class Jobs
{
    public function __construct(private App $app)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed> job row
     */
    public function create(string $kind, ?string $ownerType, ?string $ownerId, array $payload, ?string $idemKey = null, ?int $total = null): array
    {
        $row = [
            'id' => Ids::new('job'),
            'kind' => $kind,
            'status' => 'queued',
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'payload' => Json::encode($payload),
            'progress_done' => 0,
            'progress_total' => $total,
            'summary' => null,
            'result' => null,
            'error' => null,
            'idem_key' => $idemKey,
            'created_at' => Clock::iso(),
            'created_ts' => Clock::time(),
            'started_at' => null,
            'finished_at' => null,
            'lease_ts' => null,
            'attempts' => 0,
        ];
        $this->app->db->insert('obx_jobs', $row);
        $id = (string) $row['id'];
        $this->app->defer(fn () => $this->app->worker()->runJob($id));
        return $row;
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        return $this->app->db->one('SELECT * FROM obx_jobs WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function byIdempotencyKey(string $kind, string $ownerType, string $ownerId, string $key): ?array
    {
        return $this->app->db->one(
            'SELECT * FROM obx_jobs WHERE kind = ? AND owner_type = ? AND owner_id = ? AND idem_key = ? AND created_ts > ?',
            [$kind, $ownerType, $ownerId, $key, Clock::time() - 86400]
        );
    }

    public function progress(string $id, int $done, ?int $total = null): void
    {
        $set = ['progress_done' => $done, 'lease_ts' => Clock::time() + 600];
        if ($total !== null) {
            $set['progress_total'] = $total;
        }
        $this->app->db->update('obx_jobs', $set, ['id' => $id]);
    }

    /** @param array<string, mixed> $item */
    public function addResult(string $jobId, int $idx, array $item, bool $isError): void
    {
        $this->app->db->insert('obx_job_results', ['job_id' => $jobId, 'idx' => $idx, 'is_error' => $isError ? 1 : 0, 'item' => Json::encode($item)]);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> IngestJob */
    public function ingestJson(array $row): array
    {
        $run = null;
        if ($row['kind'] === 'import') {
            $r = $this->app->db->one('SELECT * FROM obx_import_runs WHERE job_id = ?', [$row['id']]);
            $run = $r !== null ? $this->app->importer->runJson($r) : null;
        }
        return [
            'id' => $row['id'],
            'kind' => $row['kind'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'finished_at' => $row['finished_at'],
            'progress' => ['done' => (int) $row['progress_done'], 'total' => $row['progress_total'] !== null ? (int) $row['progress_total'] : null],
            'summary' => $row['summary'] !== null ? Json::decode((string) $row['summary']) : null,
            'import_run' => $run,
            'results_url' => $this->app->baseUrl() . '/obx/v1/ingest/jobs/' . $row['id'] . '/results',
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, next: ?int}
     */
    public function results(string $jobId, bool $errorsOnly, int $offset, int $limit): array
    {
        $sql = 'SELECT item FROM obx_job_results WHERE job_id = ? AND idx >= ?' . ($errorsOnly ? ' AND is_error = 1' : '') . ' ORDER BY idx LIMIT ' . ($limit + 1);
        $rows = $this->app->db->all($sql, [$jobId, $offset]);
        $sqlIdx = 'SELECT idx FROM obx_job_results WHERE job_id = ? AND idx >= ?' . ($errorsOnly ? ' AND is_error = 1' : '') . ' ORDER BY idx LIMIT ' . ($limit + 1);
        $idx = $this->app->db->column($sqlIdx, [$jobId, $offset]);
        $more = count($rows) > $limit;
        $items = array_map(static fn ($r) => Json::decode((string) $r['item'], []), array_slice($rows, 0, $limit));
        return ['items' => $items, 'next' => $more ? (int) $idx[$limit] : null];
    }

    public function require(string $id): array
    {
        $j = $this->get($id);
        if ($j === null) {
            throw new ApiError('not_found', 'No such job.');
        }
        return $j;
    }
}
