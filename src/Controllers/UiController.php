<?php

declare(strict_types=1);

namespace Obx\Node\Controllers;

use Obx\Node\Http\ApiError;
use Obx\Node\Http\Request;
use Obx\Node\Http\Response;
use Obx\Node\Support\Clock;

/**
 * Serves the vendored admin-ui build (single-page app with fallback), the public logo and the
 * cron URL for hosts that can only call a web address on a schedule.
 */
final class UiController extends Controller
{
    /**
     * The policy admin-ui documents in docs/node-integration.md and runs its own Playwright tests
     * under: no inline styles, nothing from other hosts except images (object storage, a CDN).
     */
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob: https:; "
        . "connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    /** The "web interface not installed" page has its own inline style and nothing else. */
    private const PLACEHOLDER_CSP = "default-src 'none'; style-src 'unsafe-inline'; base-uri 'self'; form-action 'none'; frame-ancestors 'none'";

    /** Licence files of the build have no extension. */
    private const PLAIN_TEXT = ['LICENSE', 'NOTICE'];

    private const TYPES = [
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'txt' => 'text/plain; charset=utf-8',
        'webmanifest' => 'application/manifest+json',
        'map' => 'application/json',
    ];

    private function uiDir(): string
    {
        return rtrim((string) $this->app->config->get('ui_dir'), '/');
    }

    public function fallback(Request $req): Response
    {
        $p = $req->path;
        if (str_starts_with($p, '/admin/api/') || $p === '/admin/api' || str_starts_with($p, '/obx/') || str_starts_with($p, '/.well-known/')) {
            throw new ApiError('not_found', 'There is no such endpoint.');
        }
        if ($req->method !== 'GET' && $req->method !== 'HEAD') {
            throw new ApiError('method_not_allowed', 'Only GET is supported here.');
        }
        $static = $this->staticFile($p);
        if ($static !== null) {
            return $static;
        }
        $accept = strtolower((string) $req->header('accept', ''));
        $looksLikeFile = (bool) preg_match('#/[^/]+\.[a-z0-9]{1,8}$#i', $p);
        // A missing build file is a 404, never index.html: an open tab from an older version must
        // see that its file is gone (it then reloads once) instead of parsing HTML as code.
        $isBuildFile = (bool) preg_match('#^/(assets|locales)/#', $p);
        if ($isBuildFile || ($looksLikeFile && !str_contains($accept, 'text/html'))) {
            return Response::text('Not found', 'text/plain; charset=utf-8', 404);
        }
        return $this->index($req);
    }

    private function staticFile(string $path): ?Response
    {
        $dir = $this->uiDir();
        $rel = ltrim(rawurldecode($path), '/');
        if ($rel === '' || $rel === 'index.html' || str_contains($rel, "\0") || str_contains($rel, '..')) {
            return null;
        }
        $file = realpath($dir . '/' . $rel);
        $root = realpath($dir);
        if ($file === false || $root === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
            return null;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $res = new Response(200);
        $type = in_array($rel, self::PLAIN_TEXT, true) ? 'text/plain; charset=utf-8' : (self::TYPES[$ext] ?? 'application/octet-stream');
        $res->header('Content-Type', $type);
        $res->header('Cache-Control', str_starts_with($rel, 'assets/') ? 'public, max-age=31536000, immutable' : 'public, max-age=3600');
        $res->header('Content-Length', (string) filesize($file));
        $res->file = $file;
        return $res;
    }

    public function index(Request $req): Response
    {
        $file = $this->uiDir() . '/index.html';
        $installed = is_file($file);
        $html = $installed ? (string) file_get_contents($file) : self::placeholder();
        $base = $req->basePath . '/';
        $html = (string) preg_replace('#<base\s+href="[^"]*"\s*/?>#i', '<base href="' . htmlspecialchars($base, ENT_QUOTES) . '" />', $html, 1);
        $res = Response::text($html, 'text/html; charset=utf-8');
        $res->header('Content-Security-Policy', $installed ? self::CSP : self::PLACEHOLDER_CSP);
        $res->header('X-Frame-Options', 'DENY');
        $res->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        $res->header('Cache-Control', 'no-cache');
        return $res;
    }

    public static function placeholder(): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><base href="/" />'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Open Brand Exchange</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem;line-height:1.5;color:#1d1d1b}code{background:#f1f1ee;padding:.1rem .3rem;border-radius:4px}</style>'
            . '</head><body><h1>Your portal is running</h1><p>The web interface is not installed in this copy. '
            . 'Release packages include it; in a development checkout run <code>scripts/vendor-ui.sh</code>.</p>'
            . '<p>The API works already: <a href=".well-known/obx">.well-known/obx</a>.</p></body></html>';
    }

    public function logo(Request $req): Response
    {
        $file = (string) $req->params['file'];
        if (!preg_match('/^([a-f0-9]{64})\.(png|jpg|webp)$/', $file, $m)) {
            throw new ApiError('not_found', 'No such logo.');
        }
        // Only the current portal logo is public; other blobs are never served here.
        if (($this->app->settings->all()['branding']['logo_sha'] ?? null) !== $m[1]) {
            throw new ApiError('not_found', 'No such logo.');
        }
        $blob = $this->app->blobs->get($m[1]);
        $path = $this->app->blobs->path($m[1]);
        if ($blob === null || $path === null) {
            throw new ApiError('not_found', 'No such logo.');
        }
        // No CORS header: an <img> needs none, and CORS is only on the discovery document.
        return $this->serveFile($req, $path, (string) $blob['content_type'], $m[1], 'inline', 'logo.' . $m[2], 'public, max-age=31536000, immutable');
    }

    public function cron(Request $req): Response
    {
        $key = (string) ($req->q('key') ?? '');
        if ($key === '' || !hash_equals($this->app->crypto->derive('cron', 32), $key)) {
            throw new ApiError('forbidden', 'Wrong or missing cron key. It is shown in the admin under Settings.');
        }
        @set_time_limit(120);
        @ignore_user_abort(true);
        $done = $this->app->worker()->tick(50);
        $this->app->settings->updateState(['cron_ts' => Clock::time()]);
        return Response::json(['ok' => true, 'done' => $done]);
    }
}
