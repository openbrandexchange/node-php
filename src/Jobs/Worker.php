<?php

declare(strict_types=1);

namespace Obx\Node\Jobs;

use Obx\Node\App;
use Obx\Node\Http\ApiError;
use Obx\Node\Ingest\Batch;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Json;

/**
 * Runs queued jobs and periodic housekeeping. Invoked by `php bin/obx cron` (every minute),
 * `php bin/obx worker` (long-running, Docker), the /cron URL, and opportunistically after
 * admin/ingest requests on hosts without cron.
 */
final class Worker
{
    public function __construct(private App $app)
    {
    }

    /** Claims and runs one job if it is still queued. */
    public function runJob(string $id): void
    {
        $db = $this->app->db;
        $claimed = $db->tx(function () use ($db, $id) {
            $row = $db->one('SELECT * FROM obx_jobs WHERE id = ?', [$id]);
            if ($row === null || $row['status'] !== 'queued') {
                return null;
            }
            $db->update('obx_jobs', [
                'status' => 'running',
                'started_at' => Clock::iso(),
                'lease_ts' => Clock::time() + 600,
                'attempts' => (int) $row['attempts'] + 1,
            ], ['id' => $id]);
            return $row;
        });
        if ($claimed === null) {
            return;
        }
        @set_time_limit(0);
        @ignore_user_abort(true);
        try {
            $payload = Json::decode((string) $claimed['payload'], []);
            $summary = match ($claimed['kind']) {
                'batch' => (new Batch($this->app))->runJob($claimed, $payload),
                'import' => $this->app->importer->runJob($claimed, $payload),
                'export' => $this->app->exports->build($claimed, $payload),
                'download' => $this->app->downloads->build($claimed, $payload),
                default => throw new \RuntimeException('Unknown job kind ' . $claimed['kind']),
            };
            $db->update('obx_jobs', [
                'status' => 'succeeded',
                'finished_at' => Clock::iso(),
                'summary' => $summary !== null ? Json::encode($summary) : null,
                'lease_ts' => null,
            ], ['id' => $id]);
            $this->finished($claimed, 'succeeded');
        } catch (\Throwable $e) {
            $problem = $e instanceof ApiError ? $e->problem() : (new ApiError('internal_error', 'The job failed: ' . $e->getMessage()))->problem();
            if (!$e instanceof ApiError) {
                $this->app->log->error('Job ' . $id . ' failed: ' . $e->getMessage(), $e);
            }
            $db->update('obx_jobs', ['status' => 'failed', 'finished_at' => Clock::iso(), 'error' => Json::encode($problem), 'lease_ts' => null], ['id' => $id]);
            match ($claimed['kind']) {
                'import' => $this->app->importer->failRun($claimed, $problem),
                'export' => $this->app->exports->fail($claimed, $problem),
                'download' => $this->app->downloads->fail($claimed, $problem),
                default => null,
            };
            $this->finished($claimed, 'failed');
        }
        $this->app->webhooks->notifyChanges();
    }

    /** @param array<string, mixed> $job */
    private function finished(array $job, string $status): void
    {
        if ($job['kind'] === 'batch' || $job['kind'] === 'import') {
            if ($job['owner_type'] === 'ingest_token' || $job['kind'] === 'batch') {
                $this->app->webhooks->admin('ingest_job.completed', ['job_id' => $job['id'], 'status' => $status]);
            }
        }
    }

    /**
     * One pass of background work within a time budget (seconds).
     *
     * @return array<string, int> what was done
     */
    public function tick(int $budget = 50): array
    {
        $start = time();
        $done = ['jobs' => 0, 'webhooks' => 0, 'sources' => 0, 'expired' => 0];
        if (!$this->lock('tick', $budget + 30)) {
            return $done;
        }
        try {
            if ((int) ($this->app->settings->state()['worker_ts'] ?? 0) < Clock::time() - 30) {
                $this->app->settings->updateState(['worker_ts' => Clock::time()]);
            }
            $this->recoverStale();
            // Scheduled imports that are due become queued jobs first, so they run in this pass.
            $done['sources'] = $this->app->sources->runDue();
            // Queued jobs, oldest first.
            while (time() - $start < $budget && !self::$stopping) {
                $id = $this->app->db->value("SELECT id FROM obx_jobs WHERE status = 'queued' ORDER BY created_ts LIMIT 1");
                if ($id === null) {
                    break;
                }
                $this->runJob((string) $id);
                $done['jobs']++;
            }
            $done['expired'] = $this->app->writer->expireAssets();
            $this->app->webhooks->notifyChanges();
            $done['webhooks'] = $this->app->webhooks->deliverDue(100, max(1, $budget - (time() - $start)));
            $this->housekeeping();
        } finally {
            $this->unlock('tick');
        }
        return $done;
    }

    /** Hourly cleanups: feed retention, expired files, sessions, tokens, stale blobs. */
    private function housekeeping(): void
    {
        $state = $this->app->settings->state();
        if ((int) ($state['housekeeping_ts'] ?? 0) > Clock::time() - 3600) {
            return;
        }
        $this->app->settings->updateState(['housekeeping_ts' => Clock::time()]);
        $db = $this->app->db;
        $now = Clock::time();
        $this->app->feed->prune();
        $db->run('DELETE FROM obx_sessions WHERE expires_ts < ?', [$now]);
        $db->run('DELETE FROM obx_access_tokens WHERE expires_ts < ?', [$now - 86400]);
        $db->run('DELETE FROM obx_idempotency WHERE created_ts < ?', [$now - 86400]);
        $this->app->limiter->cleanup();
        $this->app->webhooks->cleanup();
        $this->app->exports->expire();
        $this->app->downloads->expire();
        (new \Obx\Node\Ingest\Uploads($this->app))->expire();
        $old = $db->column("SELECT id FROM obx_jobs WHERE finished_at IS NOT NULL AND created_ts < ?", [$now - 14 * 86400]);
        foreach ($old as $jid) {
            $db->delete('obx_job_results', ['job_id' => $jid]);
            $db->delete('obx_jobs', ['id' => $jid]);
        }
        $this->app->blobs->collectGarbage($now - 86400);
        $this->app->retention->sweep();
        foreach (glob($this->app->config->dataDir() . '/tmp/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < $now - 86400) {
                @unlink($f);
            }
        }
    }

    private function recoverStale(): void
    {
        $rows = $this->app->db->all("SELECT id, attempts FROM obx_jobs WHERE status = 'running' AND lease_ts IS NOT NULL AND lease_ts < ?", [Clock::time()]);
        foreach ($rows as $r) {
            if ((int) $r['attempts'] >= 3) {
                $this->app->db->update('obx_jobs', ['status' => 'failed', 'finished_at' => Clock::iso(), 'error' => Json::encode((new ApiError('internal_error', 'The job stopped unexpectedly three times.'))->problem())], ['id' => $r['id']]);
            } else {
                $this->app->db->update('obx_jobs', ['status' => 'queued', 'lease_ts' => null], ['id' => $r['id']]);
            }
        }
    }

    public function lock(string $name, int $ttl): bool
    {
        $db = $this->app->db;
        $holder = bin2hex(random_bytes(6));
        return $db->tx(function () use ($db, $name, $ttl, $holder) {
            $row = $db->one('SELECT * FROM obx_locks WHERE name = ?', [$name]);
            $now = Clock::time();
            if ($row !== null && (int) $row['until_ts'] > $now) {
                return false;
            }
            if ($row === null) {
                $db->insert('obx_locks', ['name' => $name, 'until_ts' => $now + $ttl, 'holder' => $holder]);
            } else {
                $db->update('obx_locks', ['until_ts' => $now + $ttl, 'holder' => $holder], ['name' => $name]);
            }
            return true;
        });
    }

    public function unlock(string $name): void
    {
        $this->app->db->delete('obx_locks', ['name' => $name]);
    }

    private static bool $stopping = false;

    /**
     * Long-running loop for Docker / a process manager. With the pcntl extension, SIGTERM and
     * SIGINT stop it gracefully: the running job finishes, then the loop exits. Without pcntl
     * (or on SIGKILL) an interrupted job keeps its lease and is picked up again by the next
     * worker after at most 10 minutes (recoverStale); every job kind is safe to run again.
     *
     * @param int|null $maxSeconds stop after this long (null = run until stopped)
     */
    public function loop(?int $maxSeconds = null): void
    {
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $stop = static function (): void {
                self::$stopping = true;
            };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }
        $until = $maxSeconds !== null ? time() + $maxSeconds : null;
        while (!self::$stopping && ($until === null || time() < $until)) {
            $done = $this->tick(50);
            if (array_sum($done) === 0) {
                for ($i = 0; $i < 20 && !self::$stopping; $i++) {
                    usleep(100000);
                }
            }
        }
        $this->app->log->notice('Worker stopped.');
    }

    public static function stopping(): bool
    {
        return self::$stopping;
    }
}
