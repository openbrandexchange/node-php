<?php

declare(strict_types=1);

namespace Obx\Node\Controllers;

use Obx\Node\Config;
use Obx\Node\Db\Schema;
use Obx\Node\Http\ApiError;
use Obx\Node\Http\Request;
use Obx\Node\Http\Response;
use Obx\Node\Support\Clock;

/**
 * Operations endpoints (not part of the OBX API):
 *   GET /healthz  liveness: PHP answers (no database access), always 200
 *   GET /readyz   readiness: database reachable at the current schema version, data folder
 *                 writable, blob store reachable; 200 or 503 with the failing check
 *   GET /metrics  Prometheus text format, only when OBX_METRICS_TOKEN is set
 *                 (Authorization: Bearer <token>); 404 otherwise
 */
final class OpsController extends Controller
{
    public function healthz(Request $req): Response
    {
        return Response::json(['status' => 'ok', 'software' => Config::SOFTWARE, 'version' => Config::VERSION]);
    }

    public function readyz(Request $req): Response
    {
        $checks = [];
        $ok = true;
        try {
            $v = Schema::currentVersionOf($this->app->db);
            $good = $v === Schema::latestVersion();
            $checks['database'] = $good ? 'ok' : 'schema version ' . $v . ', expected ' . Schema::latestVersion();
            $ok = $good;
        } catch (\Throwable $e) {
            $checks['database'] = 'unreachable';
            $ok = false;
        }
        $dir = $this->app->config->dataDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $writable = is_dir($dir) && is_writable($dir);
        $checks['data_dir'] = $writable ? 'ok' : 'not writable';
        $ok = $ok && $writable;
        try {
            $this->app->blobs->ping();
            $checks['blob_store'] = 'ok';
        } catch (\Throwable $e) {
            $checks['blob_store'] = 'unreachable';
            $ok = false;
        }
        $checks['setup'] = $this->app->settings->setupDone() ? 'done' : 'pending';
        $res = Response::json(['status' => $ok ? 'ready' : 'not_ready', 'checks' => $checks], $ok ? 200 : 503);
        return $res;
    }

    public function metrics(Request $req): Response
    {
        $token = $this->app->config->get('metrics_token');
        if (!is_string($token) || $token === '') {
            throw new ApiError('not_found', 'There is no such endpoint.');
        }
        $given = $req->bearer() ?? '';
        if (!hash_equals($token, $given)) {
            throw new ApiError('unauthorized', 'The metrics token is missing or wrong.', [], null, ['WWW-Authenticate' => 'Bearer realm="metrics"']);
        }
        $db = $this->app->db;
        $lines = [];
        $gauge = static function (string $name, string $help, array $samples) use (&$lines): void {
            $lines[] = '# HELP ' . $name . ' ' . $help;
            $lines[] = '# TYPE ' . $name . ' gauge';
            foreach ($samples as [$labels, $value]) {
                $l = [];
                foreach ($labels as $k => $v) {
                    $l[] = $k . '="' . addcslashes((string) $v, "\\\"\n") . '"';
                }
                $lines[] = $name . ($l !== [] ? '{' . implode(',', $l) . '}' : '') . ' ' . (is_float($value) ? sprintf('%.3f', $value) : (string) $value);
            }
        };
        $gauge('obx_build_info', 'Software and schema version.', [[['software' => Config::SOFTWARE, 'version' => Config::VERSION, 'schema' => (string) Schema::latestVersion()], 1]]);
        $objects = [];
        foreach (['brand' => 'obx_brands', 'product' => 'obx_products', 'variant' => 'obx_variants', 'asset' => 'obx_assets'] as $type => $table) {
            foreach ($db->all('SELECT visible, COUNT(*) AS n FROM ' . $table . ' GROUP BY visible') as $r) {
                $objects[] = [['type' => $type, 'visible' => (int) $r['visible'] === 1 ? 'true' : 'false'], (int) $r['n']];
            }
        }
        $gauge('obx_catalog_objects', 'Catalog objects by type and visibility.', $objects);
        $gauge('obx_retailers', 'Retailers by status.', array_map(static fn ($r) => [['status' => $r['status']], (int) $r['n']], $db->all('SELECT status, COUNT(*) AS n FROM obx_retailers GROUP BY status')));
        $gauge('obx_accounts', 'Accounts by role and status.', array_map(static fn ($r) => [['role' => $r['role'], 'status' => $r['status']], (int) $r['n']], $db->all('SELECT role, status, COUNT(*) AS n FROM obx_users GROUP BY role, status')));
        $gauge('obx_api_clients', 'Active retailer API keys.', [[[], (int) $db->value("SELECT COUNT(*) FROM obx_api_clients WHERE status = 'active'")]]);
        $gauge('obx_access_requests_pending', 'Access requests waiting for a decision.', [[[], (int) $db->value("SELECT COUNT(*) FROM obx_access_requests WHERE status = 'pending'")]]);
        $gauge('obx_jobs', 'Background jobs by kind and status (last 14 days).', array_map(static fn ($r) => [['kind' => $r['kind'], 'status' => $r['status']], (int) $r['n']], $db->all('SELECT kind, status, COUNT(*) AS n FROM obx_jobs GROUP BY kind, status')));
        $oldest = $db->value("SELECT MIN(created_ts) FROM obx_jobs WHERE status = 'queued'");
        $gauge('obx_jobs_oldest_queued_seconds', 'Age of the oldest queued job (0 when none).', [[[], $oldest !== null ? max(0, Clock::time() - (int) $oldest) : 0]]);
        $gauge('obx_webhook_outbox', 'Webhook deliveries by status.', array_map(static fn ($r) => [['status' => $r['status']], (int) $r['n']], $db->all('SELECT status, COUNT(*) AS n FROM obx_webhook_outbox GROUP BY status')));
        $gauge('obx_feed_last_seq', 'Newest change-feed sequence number.', [[[], (int) ($db->value('SELECT MAX(seq) FROM obx_changes') ?? 0)]]);
        $failedRuns = (int) $db->value("SELECT COUNT(*) FROM obx_import_runs WHERE status = 'failed' AND created_ts > ?", [Clock::time() - 86400]);
        $gauge('obx_import_runs_failed_24h', 'Failed import runs in the last 24 hours.', [[[], $failedRuns]]);
        $state = $this->app->settings->state();
        $last = max((int) ($state['cron_ts'] ?? 0), (int) ($state['tick_ts'] ?? 0), (int) ($state['worker_ts'] ?? 0));
        $gauge('obx_background_last_run_seconds', 'Seconds since background work last ran (-1 when never).', [[[], $last > 0 ? max(0, Clock::time() - $last) : -1]]);
        $gauge('obx_blob_bytes', 'Stored files (originals and versions).', [[[], (int) ($db->value('SELECT SUM(bytes) FROM obx_blobs') ?? 0)]]);
        $res = Response::text(implode("\n", $lines) . "\n", 'text/plain; version=0.0.4; charset=utf-8');
        return $res;
    }
}
