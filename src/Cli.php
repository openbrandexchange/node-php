<?php

declare(strict_types=1);

namespace Obx\Node;

use Obx\Node\Db\Db;
use Obx\Node\Db\Schema;

/**
 * Command line: `php bin/obx <command>`.
 */
final class Cli
{
    private const HELP = <<<TXT
Open Brand Exchange node (PHP) %s

Usage: php bin/obx <command>

  cron           Run background work once (imports, zips, e-mails, webhooks). Add to crontab:
                 * * * * * php /path/to/bin/obx cron >/dev/null 2>&1
  worker         Keep running background work (Docker, systemd, supervisor).
  setup-code     Show the one-time setup code for the first-run wizard.
  cron-url       Show the web address to call when your host has no cron (e.g. via a cron service).
  migrate        Create or update the database tables.
  serve [port]   Start a local development server (default port 8080).
  version        Show the version.

Configuration comes from config/config.php (written by the web installer) or environment
variables: OBX_PUBLIC_URL, OBX_SECRET_KEY, OBX_DB_DSN, OBX_SETUP_CODE, OBX_ALLOW_PRIVATE_URLS, …

TXT;

    /** @param list<string> $args */
    public static function main(string $root, array $args): int
    {
        $cmd = $args[0] ?? 'help';
        $out = static function (string $s): void {
            fwrite(STDOUT, $s . "\n");
        };
        try {
            switch ($cmd) {
                case 'version':
                    $out(Config::SOFTWARE . ' ' . Config::VERSION . ' (OBX ' . Config::SPEC_VERSION . ')');
                    return 0;
                case 'serve':
                    $port = (int) ($args[1] ?? getenv('PORT') ?: 8080);
                    $out('Portal on http://localhost:' . $port . '/ (Ctrl+C to stop)');
                    $config = Config::load($root);
                    if ($config->installed()) {
                        $app = new App($config);
                        if (!$app->settings->setupDone()) {
                            // A newly made code is already logged by ensureSetupCode(); an
                            // existing one (after a restart) is not, so print that here.
                            $existing = ($app->settings->state()['setup_code_enc'] ?? null) !== null;
                            $code = $app->ensureSetupCode();
                            if ($existing) {
                                $out('Setup code for the setup wizard: ' . $code);
                            }
                        }
                    }
                    passthru(escapeshellarg(PHP_BINARY) . ' -d enable_post_data_reading=0 -d upload_max_filesize=512M -d post_max_size=520M -S 0.0.0.0:' . $port . ' -t ' . escapeshellarg($root . '/public') . ' ' . escapeshellarg($root . '/public/index.php'), $code);
                    return (int) $code;
                case 'migrate':
                    $config = Config::load($root);
                    $db = new Db((string) $config->get('db_dsn'), $config->get('db_user'), $config->get('db_password'));
                    Schema::migrate($db);
                    $out('Database is at schema version ' . Schema::latestVersion() . '.');
                    return 0;
            }
            $config = Config::load($root);
            if (!$config->installed()) {
                fwrite(STDERR, "Not installed yet: open /install in your browser or set OBX_DB_DSN / OBX_SECRET_KEY.\n");
                return 1;
            }
            $app = new App($config);
            switch ($cmd) {
                case 'cron':
                    $done = $app->worker()->tick(50);
                    $app->settings->updateState(['cron_ts' => time()]);
                    $app->runDeferred();
                    if (in_array('-v', $args, true)) {
                        $out(json_encode($done));
                    }
                    return 0;
                case 'worker':
                    $out('Worker started.');
                    $app->worker()->loop();
                    return 0;
                case 'setup-code':
                    if ($app->settings->setupDone()) {
                        $out('Setup is already done. Log in at ' . $app->publicUrl() . '/login');
                        return 0;
                    }
                    $out('Setup code: ' . $app->ensureSetupCode());
                    return 0;
                case 'cron-url':
                    $out($app->publicUrl() . '/cron?key=' . $app->crypto->derive('cron', 32));
                    return 0;
                default:
                    fwrite(STDOUT, sprintf(self::HELP, Config::VERSION));
                    return $cmd === 'help' || $cmd === '--help' || $cmd === '-h' ? 0 : 1;
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error: ' . Support\Log::scrub($e->getMessage()) . "\n");
            return 1;
        }
    }
}
