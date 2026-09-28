<?php

declare(strict_types=1);

namespace Obx\Node;

use Obx\Node\Http\Request;
use Obx\Node\Install\Installer;

/**
 * Front controller for web servers (Apache/.htaccess, nginx, php -S, Docker, Cloud Run).
 */
final class Web
{
    public static function run(string $root): void
    {
        $config = Config::load($root);
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (preg_match('#/healthz$#', $path)) {
            // Liveness: PHP answers. Needs neither the database nor the configuration.
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['status' => 'ok', 'software' => Config::SOFTWARE, 'version' => Config::VERSION]);
            return;
        }
        if (!$config->installed() || preg_match('#/install/?$#', $path)) {
            (new Installer($root, $config))->serve();
            return;
        }
        try {
            $app = new App($config);
        } catch (\Throwable $e) {
            $log = new \Obx\Node\Support\Log($config->get('log_file'), (string) $config->get('log_format', 'text'));
            $log->error('Startup failed: ' . $e->getMessage(), null, ['path' => $path]);
            http_response_code(503);
            header('Content-Type: application/problem+json');
            header('Cache-Control: no-store');
            header('Retry-After: 30');
            $detail = $e instanceof ConfigInvalid
                ? 'The portal is not configured correctly. The server log lists the problems (or run: php bin/obx check).'
                : ($e instanceof \Obx\Node\Db\SchemaTooNew
                    ? 'The database belongs to a newer release of this software. See the server log.'
                    : 'The portal cannot reach its database or storage. Check config/config.php and the server log.');
            echo json_encode([
                'type' => 'https://openbrandexchange.com/errors/service_unavailable',
                'title' => 'Service unavailable',
                'status' => 503,
                'detail' => $detail,
                'obx_error' => 'service_unavailable',
            ]);
            return;
        }
        try {
            $req = Request::fromGlobals(is_string($config->get('trusted_proxies')) ? (string) $config->get('trusted_proxies') : null);
            $res = $app->handle($req);
        } catch (\Obx\Node\Http\ApiError $e) {
            $res = \Obx\Node\Http\Response::problem($e);
        }
        $detach = function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
        if (!$detach && $app->hasDeferred() && $res->file === null && $res->stream === null) {
            // mod_php / CLI server: tell the client the response is complete, then keep working.
            $res->header('Connection', 'close');
            $res->header('Content-Length', (string) strlen($res->body));
        }
        $res->send();
        // Background work after the response: FastCGI/LiteSpeed can detach from the client.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            flush();
        }
        @ignore_user_abort(true);
        @set_time_limit(300);
        $app->runDeferred();
    }
}
