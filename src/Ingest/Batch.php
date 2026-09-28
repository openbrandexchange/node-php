<?php

declare(strict_types=1);

namespace Obx\Node\Ingest;

use Obx\Node\App;
use Obx\Node\Catalog\Writer;
use Obx\Node\Http\ApiError;
use Obx\Node\Support\Json;

/**
 * POST /obx/v1/ingest/batch: operations in order, each atomic on its own.
 */
final class Batch
{
    public const MAX_OPERATIONS = 1000;

    public function __construct(private App $app)
    {
    }

    /** Parses a JSON array or NDJSON body. @return list<mixed> */
    public static function parse(string $body, string $contentType): array
    {
        $trim = ltrim($body);
        if ($trim === '') {
            throw new ApiError('invalid_request', 'The batch is empty.');
        }
        if ($contentType !== 'application/x-ndjson' && str_starts_with($trim, '[')) {
            $ops = Json::decodeBody($body);
            if (!is_array($ops) || !array_is_list($ops)) {
                throw new ApiError('invalid_request', 'The body must be a JSON array of operations.');
            }
            return $ops;
        }
        $ops = [];
        foreach (preg_split('/\r?\n/', $body) as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $op = json_decode($line, true);
            if (!is_array($op)) {
                throw new ApiError('invalid_request', 'Line ' . ($n + 1) . ' is not valid JSON.');
            }
            $ops[] = $op;
        }
        return $ops;
    }

    /**
     * Executes operations; returns [results, summary].
     *
     * @param list<mixed> $ops
     * @param array<string, mixed> $ctx base context (brand restriction, scopes)
     * @param callable(int): void|null $progress
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function execute(array $ops, array $ctx, ?callable $progress = null, ?callable $onResult = null): array
    {
        $results = [];
        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0, 'errors' => 0];
        foreach ($ops as $i => $op) {
            $r = $this->one($i, $op, $ctx);
            $summary[$r['result'] === 'error' ? 'errors' : $r['result']]++;
            $results[] = $r;
            if ($onResult !== null) {
                $onResult($i, $r);
            }
            if ($progress !== null && $i % 25 === 0) {
                $progress($i + 1);
            }
        }
        return [$results, $summary];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function one(int $i, mixed $op, array $ctx): array
    {
        $kind = is_array($op) ? ($op['kind'] ?? null) : null;
        $name = is_array($op) ? ($op['op'] ?? null) : null;
        $ext = is_array($op) && is_string($op['external_id'] ?? null) ? $op['external_id'] : '';
        $c = $ctx + ['index' => $i, 'op' => is_string($name) ? $name : 'upsert', 'if_match' => is_array($op) ? ($op['if_match'] ?? null) : null];
        try {
            if (!in_array($kind, ['brand', 'product', 'variant', 'asset'], true) || !in_array($name, ['upsert', 'patch', 'delete', 'publish', 'unpublish'], true) || $ext === '') {
                throw new ApiError('invalid_request', 'Each operation needs op (upsert|patch|delete|publish|unpublish), kind (brand|product|variant|asset) and external_id.');
            }
            $scopes = $ctx['scopes'] ?? ['ingest:read', 'ingest:write', 'ingest:delete'];
            $need = $name === 'delete' ? 'ingest:delete' : 'ingest:write';
            if (!in_array($need, $scopes, true)) {
                throw new ApiError('insufficient_scope', 'This token lacks the scope ' . $need . '.');
            }
            $w = $this->app->writer;
            $r = match ($name) {
                'upsert' => $w->upsert($kind, $ext, $op['data'] ?? null, $c),
                'patch' => $w->patch($kind, $ext, $op['data'] ?? null, $c),
                'delete' => $w->remove($kind, $ext, $c),
                'publish' => $w->setStatus($kind, $ext, true, $c),
                'unpublish' => $w->setStatus($kind, $ext, false, $c),
            };
            unset($r['_status']);
            return $r;
        } catch (ApiError $e) {
            return Writer::errorResult($c, is_string($kind) ? $kind : 'product', $ext !== '' ? $ext : '-', $e);
        }
    }

    /**
     * Job runner for asynchronous batches.
     *
     * @param array<string, mixed> $job
     * @param array<string, mixed> $payload
     * @return array<string, int>
     */
    public function runJob(array $job, array $payload): array
    {
        $ops = (array) ($payload['ops'] ?? []);
        $ctx = (array) ($payload['ctx'] ?? []);
        $jobId = (string) $job['id'];
        $this->app->jobs->progress($jobId, 0, count($ops));
        [, $summary] = $this->execute(
            $ops,
            $ctx,
            fn (int $done) => $this->app->jobs->progress($jobId, $done),
            fn (int $i, array $r) => $this->app->jobs->addResult($jobId, $i, $r, $r['result'] === 'error'),
        );
        $this->app->jobs->progress($jobId, count($ops));
        return $summary;
    }
}
