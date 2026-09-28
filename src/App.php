<?php

declare(strict_types=1);

namespace Obx\Node;

use Obx\Node\Catalog\ChangeLog;
use Obx\Node\Catalog\Feed;
use Obx\Node\Catalog\Reader;
use Obx\Node\Catalog\Writer;
use Obx\Node\Controllers\AdminController;
use Obx\Node\Controllers\CatalogAdminController;
use Obx\Node\Controllers\DiscoveryController;
use Obx\Node\Controllers\IngestController;
use Obx\Node\Controllers\PortalController;
use Obx\Node\Controllers\ProtocolController;
use Obx\Node\Controllers\SessionController;
use Obx\Node\Controllers\UiController;
use Obx\Node\Db\Db;
use Obx\Node\Db\Schema;
use Obx\Node\Http\ApiError;
use Obx\Node\Http\Request;
use Obx\Node\Http\Response;
use Obx\Node\Http\Router;
use Obx\Node\Images\Renderer;
use Obx\Node\Images\Renditions;
use Obx\Node\Import\Importer;
use Obx\Node\Jobs\Jobs;
use Obx\Node\Jobs\Worker;
use Obx\Node\Mail\Emails;
use Obx\Node\Mail\Mailer;
use Obx\Node\Security\Auth;
use Obx\Node\Security\Crypto;
use Obx\Node\Security\RateLimiter;
use Obx\Node\Security\SafeHttp;
use Obx\Node\Services\AccessRequests;
use Obx\Node\Services\Blobs;
use Obx\Node\Services\Downloads;
use Obx\Node\Services\Exports;
use Obx\Node\Services\Grants;
use Obx\Node\Services\Settings;
use Obx\Node\Services\Stats;
use Obx\Node\Services\Users;
use Obx\Node\Services\Webhooks;
use Obx\Node\Sources\Sources;
use Obx\Node\Storage\FsBlobStore;
use Obx\Node\Storage\S3BlobStore;
use Obx\Node\Storage\S3Client;
use Obx\Node\Support\Log;

/**
 * The node: service container, router and request pipeline.
 */
final class App
{
    public readonly Db $db;
    public readonly Crypto $crypto;
    public readonly Settings $settings;
    public readonly Blobs $blobs;
    public readonly Renditions $renditions;
    public readonly ChangeLog $changes;
    public readonly Writer $writer;
    public readonly Reader $reader;
    public readonly Grants $grants;
    public readonly Feed $feed;
    public readonly RateLimiter $limiter;
    public readonly SafeHttp $http;
    public readonly Mailer $mailer;
    public readonly Emails $emails;
    public readonly Auth $auth;
    public readonly Jobs $jobs;
    public readonly Importer $importer;
    public readonly Webhooks $webhooks;
    public readonly Stats $stats;
    public readonly Exports $exports;
    public readonly Downloads $downloads;
    public readonly AccessRequests $accessRequests;
    public readonly Users $users;
    public readonly Sources $sources;
    public readonly Log $log;
    private Router $router;
    /** @var list<callable(): void> */
    private array $deferred = [];
    private bool $booted = false;
    public ?Request $request = null;

    public function __construct(public readonly Config $config)
    {
        $dataDir = $config->dataDir();
        $this->log = new Log($config->get('log_file'));
        $this->crypto = new Crypto(Crypto::resolveKey($config->get('secret_key'), $dataDir));
        $this->db = new Db((string) $config->get('db_dsn'), $config->get('db_user'), $config->get('db_password'));
        Schema::migrate($this->db);
        $this->settings = new Settings($this->db);
        $this->http = new SafeHttp($config->bool('allow_private_urls'));
        $store = $config->get('blob_store') === 's3'
            ? new S3BlobStore(
                new S3Client(
                    (string) $config->get('s3_bucket'),
                    (string) $config->get('s3_region'),
                    (string) $config->get('s3_access_key_id'),
                    (string) $config->get('s3_secret_access_key'),
                    $config->get('s3_endpoint'),
                    $config->bool('s3_path_style'),
                ),
                (string) $config->get('s3_prefix'),
                $dataDir . '/cache/blobs',
            )
            : new FsBlobStore($dataDir . '/blobs');
        $this->blobs = new Blobs($this->db, $store, $dataDir . '/tmp');
        $renditionsMode = (string) $config->get('renditions', 'auto');
        $serverRender = $renditionsMode === 'client' ? false : ($renditionsMode === 'server' || Renderer::available());
        $this->renditions = new Renditions($this->db, $this->blobs, $serverRender);
        $this->changes = new ChangeLog($this->db);
        $this->writer = new Writer($this->db, $this->settings, $this->blobs, $this->renditions, $this->changes);
        $this->reader = new Reader($this->db, $this->crypto);
        $this->grants = new Grants($this->db, $this->settings, $this->changes);
        $this->feed = new Feed($this->db, $this->crypto, $this->reader, $this->grants, $this->settings);
        $this->limiter = new RateLimiter($this->db, (float) $config->get('rate_limit_factor', 1.0));
        $from = (string) ($config->get('mail_from') ?? '');
        $this->mailer = new Mailer(
            $from === '' && $config->get('mail_transport') !== 'memory' ? 'none' : (string) $config->get('mail_transport'),
            $from !== '' ? $from : 'noreply@localhost',
            'Open Brand Exchange',
            [
                'host' => $config->get('smtp_host'),
                'port' => $config->get('smtp_port'),
                'user' => $config->get('smtp_user'),
                'password' => $config->get('smtp_password'),
                'encryption' => $config->get('smtp_encryption'),
            ],
        );
        $localesDir = $config->get('locales_dir') ?? (rtrim((string) $config->get('ui_dir'), '/') . '/locales');
        $this->emails = new Emails($this->settings, is_dir((string) $localesDir) ? (string) $localesDir : null);
        $this->auth = new Auth($this);
        $this->jobs = new Jobs($this);
        $this->importer = new Importer($this);
        $this->webhooks = new Webhooks($this);
        $this->stats = new Stats($this->db);
        $this->exports = new Exports($this);
        $this->downloads = new Downloads($this);
        $this->accessRequests = new AccessRequests($this);
        $this->users = new Users($this);
        $this->sources = new Sources($this);
        $this->router = new Router();
        $this->routes();
    }

    public static function create(string $root, array $overrides = []): self
    {
        return new self(Config::load($root, $overrides));
    }

    // ---------------------------------------------------------------- lifecycle

    /** First-start work: the setup code. */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        if (!$this->settings->setupDone()) {
            $this->ensureSetupCode();
        }
    }

    public function ensureSetupCode(): string
    {
        $preset = $this->config->get('setup_code');
        if (is_string($preset) && $preset !== '') {
            return $preset;
        }
        $state = $this->settings->state();
        $code = $this->crypto->decrypt($state['setup_code_enc'] ?? null);
        if ($code === null) {
            $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $raw = '';
            for ($i = 0; $i < 12; $i++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
            $this->settings->updateState(['setup_code_enc' => $this->crypto->encrypt($code)]);
            $file = $this->config->dataDir() . '/SETUP-CODE.txt';
            @file_put_contents($file, "Open Brand Exchange setup code: " . $code . "\nEnter it in the setup wizard in your browser. This file is deleted after setup.\n");
            @chmod($file, 0600);
            $this->log->notice('Setup code for the first-run wizard: ' . $code . ' (also in data/SETUP-CODE.txt)');
        }
        return $code;
    }

    /** Runs $fn after the response was sent (or at the end of handle() in tests). */
    public function defer(callable $fn): void
    {
        $this->deferred[] = $fn;
    }

    public function hasDeferred(): bool
    {
        return $this->deferred !== [];
    }

    public function runDeferred(): void
    {
        while ($this->deferred !== []) {
            $fn = array_shift($this->deferred);
            try {
                $fn();
            } catch (\Throwable $e) {
                $this->log->error('Background task failed: ' . $e->getMessage(), $e);
            }
        }
    }

    public function worker(): Worker
    {
        return new Worker($this);
    }

    // ---------------------------------------------------------------- request pipeline

    public function handle(Request $req): Response
    {
        $this->request = $req;
        $this->reader->baseUrl = $this->baseUrl($req);
        $this->limiter->headers = [];
        try {
            $this->boot();
            $res = $this->dispatch($req);
        } catch (ApiError $e) {
            $res = Response::problem($e, $req->path);
        } catch (\Throwable $e) {
            $this->log->error('Unhandled error on ' . $req->method . ' ' . $req->path . ': ' . $e->getMessage(), $e);
            $res = Response::problem(new ApiError('internal_error', 'Something went wrong on the server. The error was logged.'), $req->path);
        }
        foreach ($this->limiter->headers as $k => $v) {
            if ($res->getHeader($k) === null) {
                $res->header($k, $v);
            }
        }
        if (str_starts_with($req->path, '/obx/') || str_starts_with($req->path, '/.well-known/obx')) {
            $res->header('OBX-Version', Config::OBX_VERSION);
            $res->header('Access-Control-Allow-Origin', '*');
            $res->header('Access-Control-Expose-Headers', 'ETag, OBX-Content-SHA256, OBX-Version, RateLimit-Limit, RateLimit-Remaining, RateLimit-Reset, Retry-After, Location, Content-Disposition');
        }
        $res->header('X-Content-Type-Options', 'nosniff');
        if ($res->getHeader('Cache-Control') === null) {
            $res->header('Cache-Control', 'no-store');
        }
        if ($res->getHeader('Referrer-Policy') === null) {
            $res->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        if ($req->method === 'HEAD') {
            $res->body = '';
            $res->stream = null;
            $res->file = null;
        }
        if ($this->config->bool('jobs_inline')) {
            $this->runDeferred();
        }
        return $res;
    }

    private function dispatch(Request $req): Response
    {
        if ($req->method === 'OPTIONS' && (str_starts_with($req->path, '/obx/') || str_starts_with($req->path, '/.well-known/'))) {
            return (new Response(204))
                ->header('Access-Control-Allow-Methods', 'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Authorization, Content-Type, If-Match, If-None-Match, Idempotency-Key, Prefer, Range, OBX-Content-SHA256')
                ->header('Access-Control-Max-Age', '600');
        }
        $match = $this->router->match($req->method, $req->path);
        if ($match === null) {
            return (new UiController($this))->fallback($req);
        }
        [$handler, $params] = $match;
        $req->params = $params;
        $this->guardSetup($req);
        return $handler($req);
    }

    private function guardSetup(Request $req): void
    {
        if ($this->settings->setupDone()) {
            return;
        }
        $p = $req->path;
        $open = ['/admin/api/setup', '/admin/api/session', '/admin/api/public/branding'];
        if (in_array($p, $open, true) || str_starts_with($p, '/admin/api/public/') || $p === '/cron') {
            return;
        }
        if (str_starts_with($p, '/admin/api/')) {
            throw new ApiError('setup_required', 'Finish the first-run setup first.');
        }
        if (str_starts_with($p, '/obx/') || str_starts_with($p, '/.well-known/')) {
            throw new ApiError('setup_required', 'This node has not been set up yet.', [], 503);
        }
    }

    /** Base URL for links in API responses: the configured public URL, or the request's own host. */
    public function baseUrl(?Request $req = null): string
    {
        $req ??= $this->request;
        $configured = $this->config->get('public_url');
        if (is_string($configured) && $configured !== '') {
            $c = rtrim($configured, '/');
            if ($req === null) {
                return $c;
            }
            $chost = strtolower((string) parse_url($c, PHP_URL_HOST) . (parse_url($c, PHP_URL_PORT) ? ':' . parse_url($c, PHP_URL_PORT) : ''));
            if ($chost === $req->host) {
                return $c;
            }
        }
        if ($req === null) {
            return 'http://localhost';
        }
        return $req->origin() . $req->basePath;
    }

    /** Public URL for links in e-mails (configured value wins). */
    public function publicUrl(): string
    {
        $configured = $this->config->get('public_url');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }
        return $this->baseUrl();
    }

    public function secureCookies(Request $req): bool
    {
        if ($req->isHttps()) {
            return true;
        }
        $host = explode(':', $req->host)[0];
        return !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) && str_starts_with((string) $this->config->get('public_url'), 'https://');
    }

    public function clientIp(Request $req): string
    {
        return $req->ip;
    }

    // ---------------------------------------------------------------- routes

    private function routes(): void
    {
        $r = $this->router;
        $disc = new DiscoveryController($this);
        $p = new ProtocolController($this);
        $i = new IngestController($this);
        $s = new SessionController($this);
        $a = new AdminController($this);
        $c = new CatalogAdminController($this);
        $po = new PortalController($this);
        $ui = new UiController($this);

        $r->add('GET', '/.well-known/obx', [$disc, 'wellKnown']);
        $r->add('GET', '/cron', [$ui, 'cron']);

        // OBX Protocol
        $r->add('POST', '/obx/v1/access-requests', [$p, 'createAccessRequest']);
        $r->add('GET', '/obx/v1/access-requests/{id}', [$p, 'getAccessRequest']);
        $r->add('POST', '/obx/v1/oauth/token', [$p, 'token']);
        $r->add('GET', '/obx/v1/grant', [$p, 'grant']);
        $r->add('GET', '/obx/v1/changes', [$p, 'changes']);
        $r->add('GET', '/obx/v1/brands', [$p, 'brands']);
        $r->add('GET', '/obx/v1/brands/{id}', [$p, 'brand']);
        $r->add('GET', '/obx/v1/products', [$p, 'products']);
        $r->add('GET', '/obx/v1/products:by-gtin', [$p, 'byGtinGet']);
        $r->add('POST', '/obx/v1/products:by-gtin', [$p, 'byGtinPost']);
        $r->add('GET', '/obx/v1/products/{id}', [$p, 'product']);
        $r->add('GET', '/obx/v1/assets/{id}', [$p, 'asset']);
        $r->add('GET', '/obx/v1/assets/{id}/renditions/{name}', [$p, 'rendition']);
        $r->add('POST', '/obx/v1/exports', [$p, 'createExport']);
        $r->add('GET', '/obx/v1/exports/{id}', [$p, 'getExport']);
        $r->add('GET', '/obx/v1/exports/{id}/manifest', [$p, 'exportManifest']);
        $r->add('GET', '/obx/v1/exports/{id}/parts/{number}', [$p, 'exportPart']);
        $r->add('POST', '/obx/v1/usage', [$p, 'usage']);
        $r->add('GET', '/obx/v1/webhooks', [$p, 'listWebhooks']);
        $r->add('POST', '/obx/v1/webhooks', [$p, 'createWebhook']);
        $r->add('DELETE', '/obx/v1/webhooks/{id}', [$p, 'deleteWebhook']);
        $r->add('POST', '/obx/v1/webhooks/{id}/test', [$p, 'testWebhook']);

        // OBX Ingest
        $r->add('GET', '/obx/v1/ingest', [$i, 'info']);
        $r->add('GET', '/obx/v1/ingest/', [$i, 'info']);
        foreach (['brand', 'product', 'variant', 'asset'] as $kind) {
            $path = '/obx/v1/ingest/' . $kind . 's/{external_id}';
            $r->add('GET', $path, fn (Request $q) => $i->get($q, $kind));
            $r->add('PUT', $path, fn (Request $q) => $i->put($q, $kind));
            $r->add('PATCH', $path, fn (Request $q) => $i->patch($q, $kind));
            $r->add('DELETE', $path, fn (Request $q) => $i->delete($q, $kind));
        }
        $r->add('PUT', '/obx/v1/ingest/products/{external_id}/asset-order', [$i, 'assetOrder']);
        $r->add('PUT', '/obx/v1/ingest/assets/{external_id}/files/{rendition}', [$i, 'putFile']);
        $r->add('HEAD', '/obx/v1/ingest/blobs/{sha256}', [$i, 'headBlob']);
        $r->add('PUT', '/obx/v1/ingest/blobs/{sha256}', [$i, 'putBlob']);
        $r->add('POST', '/obx/v1/ingest/uploads', [$i, 'createUpload']);
        $r->add('GET', '/obx/v1/ingest/uploads/{id}', [$i, 'getUpload']);
        $r->add('DELETE', '/obx/v1/ingest/uploads/{id}', [$i, 'abortUpload']);
        $r->add('PUT', '/obx/v1/ingest/uploads/{id}/parts/{number}', [$i, 'putPart']);
        $r->add('POST', '/obx/v1/ingest/uploads/{id}/complete', [$i, 'completeUpload']);
        $r->add('POST', '/obx/v1/ingest/batch', [$i, 'batch']);
        $r->add('POST', '/obx/v1/ingest/imports', [$i, 'import']);
        $r->add('GET', '/obx/v1/ingest/jobs', [$i, 'jobs']);
        $r->add('GET', '/obx/v1/ingest/jobs/{id}', [$i, 'job']);
        $r->add('GET', '/obx/v1/ingest/jobs/{id}/results', [$i, 'jobResults']);

        // Admin: public, session, setup
        $r->add('GET', '/admin/api/public/branding', [$s, 'branding']);
        $r->add('GET', '/admin/api/public/logo/{file}', [$ui, 'logo']);
        $r->add('GET', '/admin/api/setup', [$s, 'setupState']);
        $r->add('POST', '/admin/api/setup', [$s, 'setup']);
        $r->add('GET', '/admin/api/session', [$s, 'session']);
        $r->add('POST', '/admin/api/session/login', [$s, 'login']);
        $r->add('POST', '/admin/api/session/logout', [$s, 'logout']);
        $r->add('POST', '/admin/api/password/forgot', [$s, 'forgot']);
        $r->add('POST', '/admin/api/password/reset', [$s, 'reset']);
        $r->add('GET', '/admin/api/invites/{token}', [$s, 'invite']);
        $r->add('POST', '/admin/api/invites/{token}/accept', [$s, 'acceptInvite']);
        $r->add('PATCH', '/admin/api/me', [$s, 'me']);

        // Admin: settings, catalog, imports
        $r->add('GET', '/admin/api/settings', [$a, 'settings']);
        $r->add('PATCH', '/admin/api/settings', [$a, 'patchSettings']);
        $r->add('PUT', '/admin/api/settings/logo', [$a, 'putLogo']);
        $r->add('DELETE', '/admin/api/settings/logo', [$a, 'deleteLogo']);
        $r->add('GET', '/admin/api/catalog/brands', [$c, 'brands']);
        $r->add('GET', '/admin/api/catalog/products', [$c, 'products']);
        $r->add('GET', '/admin/api/catalog/products/{external_id}', [$c, 'product']);
        foreach (['brand', 'product', 'variant', 'asset'] as $kind) {
            $path = '/admin/api/catalog/' . $kind . 's/{external_id}';
            $r->add('PUT', $path, fn (Request $q) => $c->put($q, $kind));
            $r->add('PATCH', $path, fn (Request $q) => $c->patch($q, $kind));
            $r->add('DELETE', $path, fn (Request $q) => $c->delete($q, $kind));
        }
        $r->add('PUT', '/admin/api/catalog/products/{external_id}/asset-order', [$c, 'assetOrder']);
        $r->add('PUT', '/admin/api/catalog/assets/{external_id}/files/{rendition}', [$c, 'putFile']);
        $r->add('HEAD', '/admin/api/catalog/blobs/{sha256}', [$c, 'headBlob']);
        $r->add('PUT', '/admin/api/catalog/blobs/{sha256}', [$c, 'putBlob']);
        $r->add('GET', '/admin/api/imports', [$a, 'imports']);
        $r->add('POST', '/admin/api/imports', [$a, 'createImport']);
        $r->add('GET', '/admin/api/imports/{id}', [$a, 'importRun']);
        $r->add('GET', '/admin/api/imports/{id}/errors', [$a, 'importErrors']);
        $r->add('GET', '/admin/api/import-template.csv', [$a, 'importTemplate']);
        $r->add('GET', '/admin/api/import-sources', [$a, 'sources']);
        $r->add('POST', '/admin/api/import-sources', [$a, 'createSource']);
        $r->add('GET', '/admin/api/import-sources/{id}', [$a, 'source']);
        $r->add('PATCH', '/admin/api/import-sources/{id}', [$a, 'patchSource']);
        $r->add('DELETE', '/admin/api/import-sources/{id}', [$a, 'deleteSource']);
        $r->add('POST', '/admin/api/import-sources/{id}/run', [$a, 'runSource']);
        $r->add('POST', '/admin/api/import-sources/{id}/test', [$a, 'testSource']);

        // Admin: retailers, access, users, keys, automation, stats
        $r->add('GET', '/admin/api/retailers', [$a, 'retailers']);
        $r->add('POST', '/admin/api/retailers', [$a, 'createRetailer']);
        $r->add('GET', '/admin/api/retailers/{id}', [$a, 'retailer']);
        $r->add('PATCH', '/admin/api/retailers/{id}', [$a, 'patchRetailer']);
        $r->add('DELETE', '/admin/api/retailers/{id}', [$a, 'deleteRetailer']);
        $r->add('GET', '/admin/api/access-requests', [$a, 'accessRequests']);
        $r->add('GET', '/admin/api/access-requests/{id}', [$a, 'accessRequest']);
        $r->add('POST', '/admin/api/access-requests/{id}/approve', [$a, 'approve']);
        $r->add('POST', '/admin/api/access-requests/{id}/reject', [$a, 'reject']);
        $r->add('GET', '/admin/api/users', [$a, 'usersList']);
        $r->add('POST', '/admin/api/users/invites', [$a, 'inviteUser']);
        $r->add('PATCH', '/admin/api/users/{id}', [$a, 'patchUser']);
        $r->add('DELETE', '/admin/api/users/{id}', [$a, 'deleteUser']);
        $r->add('POST', '/admin/api/users/{id}/resend-invite', [$a, 'resendInvite']);
        $r->add('GET', '/admin/api/api-clients', [$a, 'apiClients']);
        $r->add('POST', '/admin/api/api-clients', [$a, 'createApiClient']);
        $r->add('POST', '/admin/api/api-clients/{id}/rotate', [$a, 'rotateApiClient']);
        $r->add('DELETE', '/admin/api/api-clients/{id}', [$a, 'revokeApiClient']);
        $r->add('GET', '/admin/api/ingest-tokens', [$a, 'ingestTokens']);
        $r->add('POST', '/admin/api/ingest-tokens', [$a, 'createIngestToken']);
        $r->add('POST', '/admin/api/ingest-tokens/{id}/rotate', [$a, 'rotateIngestToken']);
        $r->add('DELETE', '/admin/api/ingest-tokens/{id}', [$a, 'revokeIngestToken']);
        $r->add('GET', '/admin/api/webhooks', [$a, 'webhooksList']);
        $r->add('POST', '/admin/api/webhooks', [$a, 'createWebhook']);
        $r->add('PATCH', '/admin/api/webhooks/{id}', [$a, 'patchWebhook']);
        $r->add('DELETE', '/admin/api/webhooks/{id}', [$a, 'deleteWebhook']);
        $r->add('POST', '/admin/api/webhooks/{id}/test', [$a, 'testWebhook']);
        $r->add('GET', '/admin/api/webhooks/{id}/deliveries', [$a, 'webhookDeliveries']);
        $r->add('GET', '/admin/api/stats', [$a, 'statsSummary']);
        $r->add('GET', '/admin/api/stats/events', [$a, 'statEvents']);

        // Portal
        $r->add('GET', '/admin/api/portal/viewer', [$po, 'viewerResponse']);
        $r->add('GET', '/admin/api/portal/products', [$po, 'products']);
        $r->add('GET', '/admin/api/portal/facets', [$po, 'facets']);
        $r->add('GET', '/admin/api/portal/products/data.csv', [$po, 'filterCsv']);
        $r->add('GET', '/admin/api/portal/products/{id}', [$po, 'product']);
        $r->add('GET', '/admin/api/portal/products/{id}/download.zip', [$po, 'productZip']);
        $r->add('POST', '/admin/api/portal/gtin-lookup', [$po, 'gtinLookup']);
        $r->add('GET', '/admin/api/portal/selection', [$po, 'selection']);
        $r->add('PUT', '/admin/api/portal/selection', [$po, 'putSelection']);
        $r->add('DELETE', '/admin/api/portal/selection', [$po, 'clearSelection']);
        $r->add('GET', '/admin/api/portal/selection/download.zip', [$po, 'selectionZip']);
        $r->add('GET', '/admin/api/portal/selection/data.csv', [$po, 'selectionCsv']);
        $r->add('GET', '/admin/api/portal/assets/{id}/download', [$po, 'assetDownload']);
        $r->add('GET', '/admin/api/portal/download-jobs', [$po, 'downloadJobs']);
        $r->add('POST', '/admin/api/portal/download-jobs', [$po, 'createDownloadJob']);
        $r->add('GET', '/admin/api/portal/download-jobs/{id}', [$po, 'downloadJob']);
        $r->add('GET', '/admin/api/portal/download-jobs/{id}/file', [$po, 'downloadJobFile']);
        $r->add('GET', '/admin/api/portal/api-clients', [$po, 'apiClients']);
        $r->add('POST', '/admin/api/portal/api-clients', [$po, 'createApiClient']);
        $r->add('POST', '/admin/api/portal/api-clients/{id}/rotate', [$po, 'rotateApiClient']);
        $r->add('DELETE', '/admin/api/portal/api-clients/{id}', [$po, 'revokeApiClient']);
    }
}
