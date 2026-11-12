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
  migrate        Create or update the database tables (forward only).
  check          Check the configuration, database, data folder and file storage; exit 1 on problems
                 (--config-only: only the settings, no connections).
  backup FILE    Write a backup (database, files, secret key) to FILE.zip; --no-files leaves files out.
  restore FILE   Restore a backup into an empty database (--force replaces an existing portal).
  serve [port]   Start a local development server (default port 8080).
  version        Show the version.

Configuration comes from config/config.php (written by the web installer) or environment
variables: OBX_PUBLIC_URL, OBX_SECRET_KEY, OBX_DB_DSN, OBX_SETUP_CODE, OBX_ALLOW_PRIVATE_URLS, …

TXT;

    /** @param callable(string): void $out */
    private static function check(string $root, callable $out, bool $configOnly = false): int
    {
        $config = Config::load($root);
        $problems = $config->validate();
        foreach ($problems as $p) {
            $out('✗ ' . $p);
        }
        if ($configOnly) {
            if ($problems === []) {
                $out('✓ Configuration');
            }
            return $problems === [] ? 0 : 1;
        }
        if (!$config->installed()) {
            $out('✗ Not installed yet: open /install in your browser or set OBX_DB_DSN / OBX_SECRET_KEY.');
            return 1;
        }
        if ($problems !== []) {
            return 1;
        }
        $out('✓ Configuration');
        try {
            $app = new App($config);
        } catch (\Throwable $e) {
            $out('✗ Database: ' . Support\Log::scrub($e->getMessage()));
            return 1;
        }
        $out('✓ Database at schema version ' . Schema::currentVersionOf($app->db) . ' (' . ($app->db->isSqlite() ? 'SQLite' : 'MySQL') . ')');
        $failed = false;
        $dir = $config->dataDir();
        if (is_dir($dir) && is_writable($dir)) {
            $out('✓ Data folder ' . $dir . ' is writable');
        } else {
            $out('✗ Data folder ' . $dir . ' is not writable');
            $failed = true;
        }
        try {
            $app->blobs->ping();
            $out('✓ File storage (' . $config->get('blob_store') . ')');
        } catch (\Throwable $e) {
            $out('✗ File storage: ' . Support\Log::scrub($e->getMessage()));
            $failed = true;
        }
        foreach (['gd' => 'image versions on the server', 'zip' => 'zip uploads and downloads', 'curl' => 'scheduled imports and webhooks', 'zlib' => 'Excel imports', 'xmlreader' => 'Excel imports'] as $ext => $what) {
            $out((extension_loaded($ext) ? '✓ ' : '! ') . 'PHP extension ' . $ext . ' (' . $what . ')' . (extension_loaded($ext) ? '' : ': missing'));
        }
        $out('  Setup: ' . ($app->settings->setupDone() ? 'done' : 'not done yet'));
        return $failed ? 1 : 0;
    }

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
                    $problems = $config->validate();
                    if ($problems !== []) {
                        throw new ConfigInvalid($problems);
                    }
                    $db = new Db((string) $config->get('db_dsn'), $config->get('db_user'), $config->get('db_password'));
                    $before = Schema::currentVersionOf($db);
                    Schema::migrate($db);
                    $out($before === Schema::latestVersion()
                        ? 'Database is at schema version ' . Schema::latestVersion() . ' (nothing to do).'
                        : 'Database updated from schema version ' . $before . ' to ' . Schema::latestVersion() . '.');
                    return 0;
                case 'check':
                    return self::check($root, $out, in_array('--config-only', $args, true));
                case 'restore':
                    $file = $args[1] ?? '';
                    if ($file === '' || !is_file($file)) {
                        fwrite(STDERR, "Usage: php bin/obx restore FILE.zip [--force]\n");
                        return 1;
                    }
                    $config = Config::load($root);
                    $problems = $config->validate();
                    if ($problems !== []) {
                        throw new ConfigInvalid($problems);
                    }
                    $m = Ops\Backup::restore($config, $file, in_array('--force', $args, true));
                    $out('Restored the backup of ' . $m['created_at'] . ' (' . $m['software'] . ' ' . $m['version'] . ', schema ' . $m['schema_version'] . '): '
                        . array_sum($m['rows']) . ' rows, ' . $m['files_restored'] . ' files.');
                    if (empty($m['files_included'])) {
                        $out('The backup has no files (made with --no-files): the files must still be in the configured storage.');
                    }
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
                    $max = null;
                    foreach ($args as $a) {
                        if (str_starts_with($a, '--max-seconds=')) {
                            $max = max(1, (int) substr($a, 14));
                        }
                    }
                    $app->worker()->loop($max);
                    return 0;
                case 'backup':
                    $file = $args[1] ?? '';
                    if ($file === '' || str_starts_with($file, '--')) {
                        fwrite(STDERR, "Usage: php bin/obx backup FILE.zip [--no-files]\n");
                        return 1;
                    }
                    $m = (new Ops\Backup($app))->create($file, !in_array('--no-files', $args, true));
                    $out('Backup written to ' . $file . ': ' . array_sum($m['rows']) . ' rows, ' . $m['files'] . ' files, schema version ' . $m['schema_version'] . '.');
                    if ($m['files_missing'] !== []) {
                        $out('Warning: ' . count($m['files_missing']) . ' files were not found in the storage and are missing from the backup.');
                    }
                    $out('The file holds personal data and the secret key: keep it somewhere safe.');
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
