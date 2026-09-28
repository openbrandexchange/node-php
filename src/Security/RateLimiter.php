<?php

declare(strict_types=1);

namespace Obx\Node\Security;

use Obx\Node\Db\Db;
use Obx\Node\Http\ApiError;
use Obx\Node\Support\Clock;

/**
 * Fixed-window rate limits stored in the database (works on shared hosting without Redis).
 * Buckets are keyed by a hash, so IP addresses are never stored in clear.
 */
final class RateLimiter
{
    /** @var array<string, string> headers of the last check, for the response */
    public array $headers = [];

    public function __construct(private Db $db, private float $factor = 1.0)
    {
    }

    /**
     * Counts one hit; throws 429 rate_limited when the window is full.
     */
    public function hit(string $bucket, int $limit, int $windowSeconds): void
    {
        $limit = max(1, (int) round($limit * $this->factor));
        $now = Clock::time();
        $window = intdiv($now, $windowSeconds) * $windowSeconds;
        $key = hash('sha256', $bucket . '|' . $windowSeconds);
        $hits = 0;
        $this->db->tx(function () use ($key, $window, &$hits) {
            $row = $this->db->one('SELECT window_ts, hits FROM obx_rate_limits WHERE bucket = ?', [$key]);
            if ($row === null) {
                $this->db->insert('obx_rate_limits', ['bucket' => $key, 'window_ts' => $window, 'hits' => 1]);
                $hits = 1;
            } elseif ((int) $row['window_ts'] !== $window) {
                $this->db->update('obx_rate_limits', ['window_ts' => $window, 'hits' => 1], ['bucket' => $key]);
                $hits = 1;
            } else {
                $hits = (int) $row['hits'] + 1;
                $this->db->update('obx_rate_limits', ['hits' => $hits], ['bucket' => $key]);
            }
        });
        $reset = $window + $windowSeconds - $now;
        $this->headers = [
            'RateLimit-Limit' => (string) $limit,
            'RateLimit-Remaining' => (string) max(0, $limit - $hits),
            'RateLimit-Reset' => (string) max(1, $reset),
        ];
        if ($hits > $limit) {
            throw new ApiError('rate_limited', 'Too many requests. Try again in ' . max(1, $reset) . ' seconds.', [], null, $this->headers, max(1, $reset));
        }
    }

    /** Removes expired buckets (housekeeping). */
    public function cleanup(): void
    {
        $this->db->run('DELETE FROM obx_rate_limits WHERE window_ts < ?', [Clock::time() - 86400]);
    }
}
