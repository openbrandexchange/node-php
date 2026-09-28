<?php

declare(strict_types=1);

namespace Obx\Node\Services;

use Obx\Node\App;
use Obx\Node\Http\ApiError;
use Obx\Node\Support\Clock;

/**
 * Data retention: how long download statistics, the audit log, IP addresses and decided access
 * requests are kept (the same settings in every OBX portal). A person's name leaves the
 * download statistics after at most 400 days, even when the statistics are kept longer. Brand admins change the periods under Settings (`retention`); the
 * background worker applies them once an hour.
 *
 * Fixed rules (not configurable): sessions end after 30 days of inactivity, rate-limit
 * counters (which hold IP addresses) live for at most one hour, webhook delivery logs 30 days,
 * finished jobs 14 days, change-feed tombstones 90 days (protocol.md §6.4).
 */
final class Retention
{
    public const DEFAULTS = [
        'download_events_days' => 730,
        'audit_log_days' => 365,
        'ip_days' => 30,
        'access_requests_days' => 365,
    ];

    /** Allowed ranges [min, max] in days. IP addresses: at most 30 days (security.md §9). */
    public const LIMITS = [
        'download_events_days' => [30, 3650],
        'audit_log_days' => [30, 3650],
        'ip_days' => [1, 30],
        'access_requests_days' => [30, 3650],
    ];

    /** Days after which a person's name is removed from the download statistics. */
    public const NAME_DAYS = 400;

    public function __construct(private App $app)
    {
    }

    /** @return array<string, int> */
    public function periods(): array
    {
        $stored = $this->app->settings->all()['retention'] ?? [];
        $out = [];
        foreach (self::DEFAULTS as $k => $d) {
            $v = $stored[$k] ?? $d;
            $out[$k] = is_int($v) ? $v : $d;
        }
        return $out;
    }

    /**
     * Validates a partial retention patch and returns the merged periods.
     *
     * @param array<string, mixed> $patch
     * @return array<string, int>
     */
    public function merge(array $patch): array
    {
        $out = $this->periods();
        foreach ($patch as $k => $v) {
            if (!isset(self::LIMITS[$k])) {
                throw ApiError::validation('/retention/' . $k, 'Unknown setting. Known: ' . implode(', ', array_keys(self::LIMITS)) . '.');
            }
            [$min, $max] = self::LIMITS[$k];
            if (!is_int($v) || $v < $min || $v > $max) {
                throw ApiError::validation('/retention/' . $k, 'A number of days from ' . $min . ' to ' . $max . '.');
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Deletes or anonymises what is past its period.
     *
     * @return array<string, int> rows affected per rule
     */
    public function sweep(): array
    {
        $p = $this->periods();
        $db = $this->app->db;
        $now = Clock::time();
        $done = [];
        $done['download_events'] = $db->run('DELETE FROM obx_download_events WHERE at_ts < ?', [$now - $p['download_events_days'] * 86400])->rowCount();
        $nameCut = $now - min($p['download_events_days'], self::NAME_DAYS) * 86400;
        $done['download_names'] = $db->run('UPDATE obx_download_events SET actor_name = NULL, actor_id = NULL WHERE actor_id IS NOT NULL AND at_ts < ?', [$nameCut])->rowCount();
        $done['audit_log'] = $db->run('DELETE FROM obx_audit_log WHERE at_ts < ?', [$now - $p['audit_log_days'] * 86400])->rowCount();
        $done['audit_ips'] = $db->run('UPDATE obx_audit_log SET ip = NULL WHERE ip IS NOT NULL AND at_ts < ?', [$now - $p['ip_days'] * 86400])->rowCount();
        // Decided access requests hold the requester's contact details; open ones stay until decided.
        $cut = Clock::iso((float) ($now - $p['access_requests_days'] * 86400));
        $done['access_requests'] = $db->run("DELETE FROM obx_access_requests WHERE status <> 'pending' AND decided_at IS NOT NULL AND decided_at < ?", [$cut])->rowCount();
        $done['rate_limits'] = $db->run('DELETE FROM obx_rate_limits WHERE window_ts < ?', [$now - 3600])->rowCount();
        return $done;
    }
}
