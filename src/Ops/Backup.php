<?php

declare(strict_types=1);

namespace Obx\Node\Ops;

use Obx\Node\App;
use Obx\Node\Config;
use Obx\Node\Db\Db;
use Obx\Node\Db\Schema;
use Obx\Node\Support\Clock;
use Obx\Node\Support\Json;
use Obx\Node\Zip\ZipWriter;

/**
 * Backup and restore of a whole node in one zip file:
 *
 *   manifest.json        software, version, schema version, time, row and file counts
 *   secret.key           the node's secret key (needed to read stored credentials and
 *                        webhook secrets, and for links and cursors handed out earlier)
 *   db/<table>.ndjson    every table, one JSON object per row (driver-independent: a SQLite
 *                        backup restores into MySQL and back)
 *   files/<sha256>       every stored file (originals and versions); left out with --no-files
 *
 * The database is read inside one transaction, so the rows are a consistent snapshot while the
 * node keeps running. Files are content-addressed and never change, so copying them after the
 * snapshot is safe.
 *
 * The zip holds personal data and the secret key: store it like a password.
 */
final class Backup
{
    /** Short-lived tables that are not worth restoring. */
    private const SKIP = ['obx_rate_limits', 'obx_locks'];

    public function __construct(private App $app)
    {
    }

    /** @return list<string> the node's tables in schema order */
    public static function tables(Db $db): array
    {
        $rows = $db->isSqlite()
            ? $db->column("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'obx\\_%' ESCAPE '\\' ORDER BY name")
            : $db->column("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'obx\\_%' ORDER BY table_name");
        return array_values(array_map('strval', $rows));
    }

    /**
     * @return array<string, mixed> the manifest
     */
    public function create(string $target, bool $withFiles = true): array
    {
        $db = $this->app->db;
        $tmp = $target . '.part';
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Cannot write ' . $target . '.');
        }
        $zip = new ZipWriter($out);
        $counts = [];
        $shas = [];
        $work = $this->app->blobs->tmpDir();
        if ($db->isSqlite()) {
            $db->pdo->exec('BEGIN');
        } else {
            $db->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }
        try {
            $version = Schema::currentVersionOf($db);
            foreach (self::tables($db) as $table) {
                if (in_array($table, self::SKIP, true)) {
                    continue;
                }
                $file = $work . '/backup-' . $table . '-' . bin2hex(random_bytes(4)) . '.ndjson';
                $fh = fopen($file, 'wb');
                $n = 0;
                $stmt = $db->pdo->query('SELECT * FROM ' . $table);
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    fwrite($fh, Json::encode($row) . "\n");
                    $n++;
                    if ($table === 'obx_blobs') {
                        $shas[] = (string) $row['sha256'];
                    }
                }
                fclose($fh);
                $zip->addFile('db/' . $table . '.ndjson', $file);
                @unlink($file);
                $counts[$table] = $n;
            }
        } finally {
            $db->pdo->exec('COMMIT');
        }
        $files = 0;
        $missing = [];
        if ($withFiles) {
            foreach ($shas as $sha) {
                $path = $this->app->blobs->path($sha);
                if ($path === null || !is_file($path)) {
                    $missing[] = $sha;
                    continue;
                }
                $zip->addFile('files/' . $sha, $path);
                $files++;
            }
        }
        $manifest = [
            'format' => 'obx-node-php-backup/1',
            'software' => Config::SOFTWARE,
            'version' => Config::VERSION,
            'schema_version' => $version,
            'created_at' => Clock::iso(),
            'driver' => $db->isSqlite() ? 'sqlite' : 'mysql',
            'node_id' => $this->app->settings->nodeId(),
            'rows' => $counts,
            'files' => $files,
            'files_included' => $withFiles,
            'files_missing' => $missing,
        ];
        $zip->addString('secret.key', $this->app->crypto->keyForBackup() . "\n");
        $zip->addString('manifest.json', Json::encode($manifest, true) . "\n");
        $zip->finish();
        fclose($out);
        @chmod($tmp, 0600);
        if (!rename($tmp, $target)) {
            throw new \RuntimeException('Cannot write ' . $target . '.');
        }
        return $manifest;
    }

    /**
     * Restores a backup into the configured database and blob store. The database must be
     * empty (a fresh install) unless $force, which first deletes the node's tables.
     *
     * @return array<string, mixed> the backup's manifest
     */
    public static function restore(Config $config, string $source, bool $force = false): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Restoring needs the PHP zip extension.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($source, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Cannot open ' . $source . ' as a zip file.');
        }
        $manifest = Json::decode((string) $zip->getFromName('manifest.json'), null);
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'obx-node-php-backup/1') {
            throw new \RuntimeException($source . ' is not a backup made by `php bin/obx backup`.');
        }
        $backupVersion = (int) $manifest['schema_version'];
        if ($backupVersion > Schema::latestVersion()) {
            throw new \RuntimeException('The backup comes from a newer release (schema version ' . $backupVersion . '). Install that release (' . $manifest['version'] . ' or newer) to restore it.');
        }
        $db = new Db((string) $config->get('db_dsn'), $config->get('db_user'), $config->get('db_password'));
        $existing = self::tables($db);
        if ($existing !== []) {
            $hasData = (int) ($db->value("SELECT COUNT(*) FROM obx_settings WHERE k = 'settings'") ?? 0) > 0
                || (int) ($db->value('SELECT COUNT(*) FROM obx_users') ?? 0) > 0;
            if ($hasData && !$force) {
                throw new \RuntimeException('The database already holds a portal. Restore into an empty database, or add --force to replace everything in it.');
            }
            foreach ($existing as $t) {
                $db->pdo->exec('DROP TABLE ' . $t);
            }
        }
        // Tables as they were at the backup's version; newer migrations run after the data is in.
        Schema::migrate($db, $backupVersion);
        $key = trim((string) $zip->getFromName('secret.key'));
        $configured = $config->get('secret_key');
        if (is_string($configured) && $configured !== '' && $key !== '' && !hash_equals($configured, $key)) {
            if (!$force) {
                throw new \RuntimeException('OBX_SECRET_KEY (secret_key in config.php) differs from the key in the backup. Set it to the key in the backup (secret.key inside the zip), or add --force to continue without it: import credentials and webhook secrets then have to be entered again.');
            }
        } elseif ($key !== '') {
            $file = $config->dataDir() . '/secret.key';
            @mkdir($config->dataDir(), 0770, true);
            file_put_contents($file, $key . "\n");
            @chmod($file, 0600);
        }
        $db->tx(function () use ($zip, $db): void {
            foreach (self::tables($db) as $table) {
                if ($table !== 'obx_settings') {
                    $db->pdo->exec('DELETE FROM ' . $table);
                }
            }
            $db->pdo->exec("DELETE FROM obx_settings WHERE k NOT IN ('write_lock', 'schema_version')");
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (!preg_match('#^db/(obx_[a-z_]+)\.ndjson$#', $name, $m)) {
                    continue;
                }
                $table = $m[1];
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new \RuntimeException('Cannot read ' . $name . ' in the backup.');
                }
                while (($line = fgets($stream)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $row = Json::decode($line, null);
                    if (!is_array($row)) {
                        throw new \RuntimeException('A row in ' . $name . ' is damaged.');
                    }
                    if ($table === 'obx_settings' && in_array($row['k'] ?? '', ['write_lock', 'schema_version'], true)) {
                        continue;
                    }
                    $db->insert($table, $row);
                }
                fclose($stream);
            }
        });
        Schema::migrate($db);
        $files = 0;
        if (!empty($manifest['files_included'])) {
            $app = new App($config);
            $tmpDir = $app->blobs->tmpDir();
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (!preg_match('#^files/([a-f0-9]{64})$#', $name, $m)) {
                    continue;
                }
                $tmp = $tmpDir . '/restore-' . $m[1];
                $in = $zip->getStream($name);
                $out = fopen($tmp, 'wb');
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                if (!hash_equals($m[1], (string) hash_file('sha256', $tmp))) {
                    @unlink($tmp);
                    throw new \RuntimeException('The file ' . $m[1] . ' in the backup is damaged (checksum mismatch).');
                }
                $app->blobs->restoreFile($m[1], $tmp);
                $files++;
            }
        }
        $zip->close();
        $manifest['files_restored'] = $files;
        return $manifest;
    }
}
