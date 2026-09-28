<?php

declare(strict_types=1);

namespace Obx\Node\Controllers;

use Obx\Node\App;
use Obx\Node\Http\ApiError;
use Obx\Node\Http\Request;
use Obx\Node\Http\Response;
use Obx\Node\Support\Str;

abstract class Controller
{
    public function __construct(protected App $app)
    {
    }

    protected function limit(Request $req, int $default, int $max): int
    {
        $v = $req->q('limit');
        if ($v === null || $v === '') {
            return $default;
        }
        if (!ctype_digit($v) || (int) $v < 1) {
            throw new ApiError('invalid_request', 'limit must be a whole number from 1 to ' . $max . '.');
        }
        return min((int) $v, $max);
    }

    /** Offset from an opaque list cursor. */
    protected function offset(Request $req, string $purpose): int
    {
        $c = $req->q('cursor');
        if ($c === null || $c === '') {
            return 0;
        }
        $state = $this->app->crypto->unseal('list:' . $purpose, $c);
        if ($state === null || !isset($state['o']) || !is_int($state['o'])) {
            throw new ApiError('cursor_invalid', 'This cursor is malformed.');
        }
        return $state['o'];
    }

    protected function nextCursor(string $purpose, int $offset, int $count, int $limit, ?int $total = null): ?string
    {
        $next = $offset + $count;
        if ($count < $limit || ($total !== null && $next >= $total)) {
            return null;
        }
        return $this->app->crypto->seal('list:' . $purpose, ['o' => $next]);
    }

    /**
     * Serves a stored file with ETag / If-None-Match / Range support.
     */
    protected function serveFile(Request $req, string $path, string $contentType, string $sha256, string $disposition, string $filename, string $cacheControl = 'private, max-age=3600'): Response
    {
        $size = (int) filesize($path);
        $etag = '"' . $sha256 . '"';
        $res = new Response(200);
        $res->header('Content-Type', $contentType);
        $res->header('ETag', $etag);
        $res->header('OBX-Content-SHA256', $sha256);
        $res->header('Accept-Ranges', 'bytes');
        $res->header('Cache-Control', $cacheControl);
        $res->header('Content-Disposition', Str::contentDisposition($disposition, $filename));
        $inm = $req->header('if-none-match');
        if ($inm !== null && self::etagMatches($inm, $etag)) {
            $res->status = 304;
            return $res;
        }
        $range = $req->header('range');
        if ($range !== null && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ($m[1] !== '' || $m[2] !== '')) {
            $ifRange = $req->header('if-range');
            if ($ifRange === null || $ifRange === $etag) {
                if ($m[1] === '') {
                    $len = min((int) $m[2], $size);
                    $start = $size - $len;
                    $end = $size - 1;
                } else {
                    $start = (int) $m[1];
                    $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
                }
                if ($start >= $size || $start > $end || $size === 0) {
                    $r = new Response(416);
                    $r->header('Content-Range', 'bytes */' . $size);
                    return $r;
                }
                $res->status = 206;
                $res->header('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size);
                $res->header('Content-Length', (string) ($end - $start + 1));
                $res->file = $path;
                $res->fileOffset = $start;
                $res->fileLength = $end - $start + 1;
                return $res;
            }
        }
        $res->header('Content-Length', (string) $size);
        $res->file = $path;
        return $res;
    }

    public static function etagMatches(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $t) {
            $t = trim($t);
            if ($t === '*' || $t === $etag || $t === 'W/' . $etag) {
                return true;
            }
        }
        return false;
    }

    /** Status code from a Writer result (and strips internal keys). @param array<string, mixed> $result */
    protected function writeResponse(array $result): Response
    {
        $status = (int) ($result['_status'] ?? 200);
        unset($result['_status']);
        $res = Response::json($result, $status);
        if (!empty($result['etag'])) {
            $res->header('ETag', (string) $result['etag']);
        }
        return $res;
    }
}
