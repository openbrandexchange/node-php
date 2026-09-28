<?php

declare(strict_types=1);

namespace Obx\Node\Db;

/**
 * Database schema, versioned. Migrations are append-only; each runs once, in order.
 * Types are written as placeholders and mapped per driver:
 *   {pk} auto-increment primary key, {id} node id / short key, {key} external id (case-sensitive),
 *   {str} short string, {text} long text / JSON, {int} integer.
 */
final class Schema
{
    /** @return array<int, list<string>> */
    private static function migrations(): array
    {
        return [
            1 => [
                'CREATE TABLE obx_settings (k {id} NOT NULL PRIMARY KEY, v {text})',
                'CREATE TABLE obx_brands (
                    id {id} NOT NULL PRIMARY KEY, external_id {key} NOT NULL, name {str} NOT NULL,
                    status {id} NOT NULL, input {text} NOT NULL, rep {text} NOT NULL, rep_hash {id} NOT NULL,
                    visible {int} NOT NULL DEFAULT 0, last_seq {int} NOT NULL DEFAULT 0, etag {id} NOT NULL,
                    created_at {id} NOT NULL, updated_at {id} NOT NULL, rec_updated_at {id} NOT NULL)',
                'CREATE UNIQUE INDEX obx_brands_ext ON obx_brands (external_id)',
                'CREATE TABLE obx_products (
                    id {id} NOT NULL PRIMARY KEY, external_id {key} NOT NULL, brand_id {id} NOT NULL,
                    style_number {str} NOT NULL, status {id} NOT NULL, input {text} NOT NULL, rep {text} NOT NULL,
                    rep_hash {id} NOT NULL, visible {int} NOT NULL DEFAULT 0, last_seq {int} NOT NULL DEFAULT 0,
                    etag {id} NOT NULL, search {text}, item_type {str}, season {str}, collection {str},
                    target_group {str}, created_at {id} NOT NULL, updated_at {id} NOT NULL,
                    content_updated_at {id} NOT NULL, rec_updated_at {id} NOT NULL)',
                'CREATE UNIQUE INDEX obx_products_ext ON obx_products (external_id)',
                'CREATE INDEX obx_products_brand ON obx_products (brand_id)',
                'CREATE INDEX obx_products_style ON obx_products (style_number)',
                'CREATE TABLE obx_variants (
                    id {id} NOT NULL PRIMARY KEY, external_id {key} NOT NULL, product_id {id} NOT NULL,
                    gtin {id}, color_code {str}, status {id} NOT NULL, input {text} NOT NULL, rep {text} NOT NULL,
                    rep_hash {id} NOT NULL, visible {int} NOT NULL DEFAULT 0, last_seq {int} NOT NULL DEFAULT 0,
                    etag {id} NOT NULL, created_at {id} NOT NULL, updated_at {id} NOT NULL, rec_updated_at {id} NOT NULL)',
                'CREATE UNIQUE INDEX obx_variants_ext ON obx_variants (external_id)',
                'CREATE INDEX obx_variants_product ON obx_variants (product_id)',
                'CREATE INDEX obx_variants_gtin ON obx_variants (gtin)',
                'CREATE TABLE obx_assets (
                    id {id} NOT NULL PRIMARY KEY, external_id {key} NOT NULL, product_id {id} NOT NULL,
                    color_code {str}, position {int} NOT NULL, status {id} NOT NULL, input {text} NOT NULL,
                    files {text}, pending {text}, rep {text}, rep_hash {id} NOT NULL, complete {int} NOT NULL DEFAULT 0,
                    visible {int} NOT NULL DEFAULT 0, expires_ts {int}, last_seq {int} NOT NULL DEFAULT 0,
                    etag {id} NOT NULL, created_at {id} NOT NULL, updated_at {id} NOT NULL, rec_updated_at {id} NOT NULL)',
                'CREATE UNIQUE INDEX obx_assets_ext ON obx_assets (external_id)',
                'CREATE INDEX obx_assets_product ON obx_assets (product_id, color_code)',
                'CREATE TABLE obx_blobs (
                    sha256 {id} NOT NULL PRIMARY KEY, bytes {int} NOT NULL, content_type {str} NOT NULL,
                    width {int}, height {int}, created_at {id} NOT NULL, used_ts {int} NOT NULL)',
                'CREATE TABLE obx_renders (
                    original_sha {id} NOT NULL, name {id} NOT NULL, sha256 {id} NOT NULL, width {int} NOT NULL,
                    height {int} NOT NULL, PRIMARY KEY (original_sha, name))',
                'CREATE TABLE obx_changes (
                    seq {pk}, type {id} NOT NULL, op {id} NOT NULL, obj_id {id} NOT NULL, external_id {key},
                    brand_id {id}, product_id {id}, retailer_id {id}, resync {int} NOT NULL DEFAULT 0,
                    updated_at {id} NOT NULL, created_ts {int} NOT NULL)',
                'CREATE INDEX obx_changes_obj ON obx_changes (obj_id)',
                'CREATE TABLE obx_retailers (
                    id {id} NOT NULL PRIMARY KEY, name {str} NOT NULL, reference {str}, website {text},
                    vat_number {str}, country {id}, notes {text}, status {id} NOT NULL, brand_ids {text},
                    allow_original {int}, grant_id {id} NOT NULL, grant_updated_at {id} NOT NULL,
                    last_activity_at {id}, created_at {id} NOT NULL)',
                'CREATE TABLE obx_users (
                    id {id} NOT NULL PRIMARY KEY, email {str} NOT NULL, name {str} NOT NULL, role {id} NOT NULL,
                    retailer_id {id}, status {id} NOT NULL, language {id} NOT NULL, password_hash {text},
                    invite_hash {id}, invite_expires_at {id}, reset_hash {id}, reset_expires_ts {int},
                    last_login_at {id}, created_at {id} NOT NULL)',
                'CREATE UNIQUE INDEX obx_users_email ON obx_users (email)',
                'CREATE TABLE obx_sessions (
                    id_hash {id} NOT NULL PRIMARY KEY, user_id {id} NOT NULL, csrf {id} NOT NULL,
                    created_ts {int} NOT NULL, seen_ts {int} NOT NULL, expires_ts {int} NOT NULL)',
                'CREATE INDEX obx_sessions_user ON obx_sessions (user_id)',
                'CREATE TABLE obx_api_clients (
                    id {id} NOT NULL PRIMARY KEY, client_id {id} NOT NULL, secret_hash {id} NOT NULL, name {str} NOT NULL,
                    retailer_id {id} NOT NULL, scopes {text} NOT NULL, status {id} NOT NULL, created_at {id} NOT NULL,
                    last_used_at {id})',
                'CREATE UNIQUE INDEX obx_api_clients_cid ON obx_api_clients (client_id)',
                'CREATE TABLE obx_access_tokens (
                    token_hash {id} NOT NULL PRIMARY KEY, client_id {id} NOT NULL, scopes {text} NOT NULL,
                    expires_ts {int} NOT NULL, created_ts {int} NOT NULL)',
                'CREATE TABLE obx_ingest_tokens (
                    id {id} NOT NULL PRIMARY KEY, name {str} NOT NULL, prefix {id} NOT NULL, secret_hash {id} NOT NULL,
                    scopes {text} NOT NULL, brand_external_ids {text}, status {id} NOT NULL, expires_at {id},
                    expires_ts {int}, created_at {id} NOT NULL, last_used_at {id})',
                'CREATE UNIQUE INDEX obx_ingest_tokens_hash ON obx_ingest_tokens (secret_hash)',
                'CREATE TABLE obx_access_requests (
                    id {id} NOT NULL PRIMARY KEY, token_hash {id} NOT NULL, status {id} NOT NULL, input {text} NOT NULL,
                    language {id}, retailer_id {id}, api_client_id {id}, secret_enc {text}, claimed {int} NOT NULL DEFAULT 0,
                    portal_invited {int} NOT NULL DEFAULT 0, reason {text}, created_at {id} NOT NULL, decided_at {id})',
                'CREATE TABLE obx_webhooks (
                    id {id} NOT NULL PRIMARY KEY, owner_type {id} NOT NULL, owner_id {id} NOT NULL, url {text} NOT NULL,
                    events {text} NOT NULL, secret_enc {text} NOT NULL, status {id} NOT NULL, created_at {id} NOT NULL,
                    last_delivery_at {id}, last_status {int}, failing_since_ts {int}, notified_seq {int} NOT NULL DEFAULT 0)',
                'CREATE TABLE obx_webhook_outbox (
                    id {id} NOT NULL PRIMARY KEY, webhook_id {id} NOT NULL, event_id {id} NOT NULL, event_type {id} NOT NULL,
                    payload {text} NOT NULL, attempts {int} NOT NULL DEFAULT 0, next_ts {int} NOT NULL, status {id} NOT NULL,
                    created_ts {int} NOT NULL)',
                'CREATE INDEX obx_webhook_outbox_due ON obx_webhook_outbox (status, next_ts)',
                'CREATE TABLE obx_webhook_deliveries (
                    id {id} NOT NULL PRIMARY KEY, webhook_id {id} NOT NULL, event_id {id} NOT NULL, event_type {id} NOT NULL,
                    at {id} NOT NULL, at_ts {int} NOT NULL, status_code {int}, duration_ms {int}, attempt {int} NOT NULL,
                    success {int} NOT NULL, error {text})',
                'CREATE INDEX obx_webhook_deliveries_hook ON obx_webhook_deliveries (webhook_id, at_ts)',
                'CREATE TABLE obx_jobs (
                    id {id} NOT NULL PRIMARY KEY, kind {id} NOT NULL, status {id} NOT NULL, owner_type {id}, owner_id {id},
                    payload {text}, progress_done {int} NOT NULL DEFAULT 0, progress_total {int}, summary {text},
                    result {text}, error {text}, idem_key {str}, created_at {id} NOT NULL, created_ts {int} NOT NULL,
                    started_at {id}, finished_at {id}, lease_ts {int}, attempts {int} NOT NULL DEFAULT 0)',
                'CREATE INDEX obx_jobs_status ON obx_jobs (status, created_ts)',
                'CREATE TABLE obx_job_results (
                    job_id {id} NOT NULL, idx {int} NOT NULL, is_error {int} NOT NULL, item {text} NOT NULL,
                    PRIMARY KEY (job_id, idx))',
                'CREATE TABLE obx_import_runs (
                    id {id} NOT NULL PRIMARY KEY, job_id {id}, source_id {id}, trigger_kind {id} NOT NULL, status {id} NOT NULL,
                    mode {id} NOT NULL, dry_run {int} NOT NULL, files {text}, counts {text}, error {text},
                    created_at {id} NOT NULL, created_ts {int} NOT NULL, started_at {id}, finished_at {id})',
                'CREATE TABLE obx_import_errors (
                    run_id {id} NOT NULL, idx {int} NOT NULL, item {text} NOT NULL, PRIMARY KEY (run_id, idx))',
                'CREATE TABLE obx_import_sources (
                    id {id} NOT NULL PRIMARY KEY, name {str} NOT NULL, kind {id} NOT NULL, enabled {int} NOT NULL,
                    schedule {text} NOT NULL, mode {id} NOT NULL, allow_mass_unpublish {int} NOT NULL, config {text} NOT NULL,
                    credentials_enc {text}, last_run_id {id}, next_run_ts {int}, fingerprint {text},
                    created_at {id} NOT NULL, updated_at {id} NOT NULL)',
                'CREATE TABLE obx_exports (
                    id {id} NOT NULL PRIMARY KEY, client_id {id} NOT NULL, retailer_id {id} NOT NULL, status {id} NOT NULL,
                    request {text} NOT NULL, idem_key {str}, parts {text}, counts {text}, error {text},
                    progress_done {int} NOT NULL DEFAULT 0, progress_total {int}, created_at {id} NOT NULL,
                    created_ts {int} NOT NULL, finished_at {id}, expires_at {id}, expires_ts {int})',
                'CREATE TABLE obx_download_jobs (
                    id {id} NOT NULL PRIMARY KEY, user_id {id} NOT NULL, retailer_id {id}, status {id} NOT NULL,
                    source {id} NOT NULL, variant {id} NOT NULL, include_csv {int} NOT NULL, payload {text},
                    progress_done {int} NOT NULL DEFAULT 0, progress_total {int}, bytes {int}, files {int}, error {text},
                    created_at {id} NOT NULL, created_ts {int} NOT NULL, finished_at {id}, expires_at {id}, expires_ts {int})',
                'CREATE TABLE obx_selections (user_id {id} NOT NULL PRIMARY KEY, product_ids {text} NOT NULL, updated_at {id} NOT NULL)',
                'CREATE TABLE obx_download_events (
                    id {id} NOT NULL PRIMARY KEY, at {id} NOT NULL, at_ts {int} NOT NULL, kind {id} NOT NULL,
                    retailer_id {id}, retailer_name {str}, actor_type {id} NOT NULL, actor_id {id}, actor_name {str},
                    variant {id}, product_id {id}, asset_id {id}, usage_event {id}, files {int} NOT NULL DEFAULT 0, bytes {int})',
                'CREATE INDEX obx_download_events_at ON obx_download_events (at_ts)',
                'CREATE TABLE obx_rate_limits (bucket {str} NOT NULL PRIMARY KEY, window_ts {int} NOT NULL, hits {int} NOT NULL)',
                'CREATE TABLE obx_uploads (
                    id {id} NOT NULL PRIMARY KEY, sha256 {id} NOT NULL, bytes {int} NOT NULL, content_type {str} NOT NULL,
                    part_bytes {int} NOT NULL, parts_total {int} NOT NULL, received {text} NOT NULL, status {id} NOT NULL,
                    owner {id}, expires_ts {int} NOT NULL, created_at {id} NOT NULL)',
                'CREATE TABLE obx_idempotency (
                    k {str} NOT NULL PRIMARY KEY, status {int} NOT NULL, response {text} NOT NULL, created_ts {int} NOT NULL)',
                'CREATE TABLE obx_locks (name {id} NOT NULL PRIMARY KEY, until_ts {int} NOT NULL, holder {id} NOT NULL)',
            ],
            // 0.1.0: audit log of admin actions.
            2 => [
                'CREATE TABLE obx_audit_log (
                    id {pk}, at {id} NOT NULL, at_ts {int} NOT NULL, actor_type {id} NOT NULL, actor_id {id},
                    actor_name {str}, action {str} NOT NULL, target_type {id}, target_id {key}, status {int},
                    detail {text}, ip {id}, request_id {id})',
                'CREATE INDEX obx_audit_log_at ON obx_audit_log (at_ts)',
                'CREATE INDEX obx_audit_log_actor ON obx_audit_log (actor_id)',
            ],
        ];
    }

    /**
     * Brings the database to the latest version. Safe to call on every request (cheap check).
     * Migrations only move forward. A database written by a newer release is refused with a
     * clear message instead of being used by older code.
     *
     * @throws SchemaTooNew when the database is newer than this software
     */
    public static function migrate(Db $db, ?int $upTo = null): void
    {
        $version = self::currentVersion($db);
        $latest = $upTo ?? self::latestVersion();
        if ($upTo !== null && $version >= $upTo) {
            return;
        }
        if ($version === $latest) {
            return;
        }
        self::refuseNewer($version);
        self::locked($db, function () use ($db, $latest): void {
            // Another process may have migrated while we waited for the lock.
            $version = self::currentVersion($db);
            self::refuseNewer($version);
            foreach (self::migrations() as $v => $statements) {
                if ($v <= $version || $v > $latest) {
                    continue;
                }
                self::apply($db, $v, $statements);
            }
        });
    }

    private static function refuseNewer(int $version): void
    {
        if ($version > self::latestVersion()) {
            throw new SchemaTooNew(sprintf(
                'The database is at schema version %d, but this software (%s %s) only knows up to version %d. '
                . 'It was used by a newer release. Install that release again; going back to an older release '
                . 'needs the backup you made before upgrading (see docs/operations.md).',
                $version,
                \Obx\Node\Config::SOFTWARE,
                \Obx\Node\Config::VERSION,
                self::latestVersion(),
            ));
        }
    }

    /** @param list<string> $statements */
    private static function apply(Db $db, int $v, array $statements): void
    {
        // SQLite runs DDL inside a transaction, so a failed migration leaves nothing behind.
        // MySQL commits every DDL statement by itself; statements are written so that
        // re-running a half-applied version after fixing the cause is the recovery path
        // (docs/operations.md).
        if ($db->isSqlite()) {
            $db->pdo->exec('SAVEPOINT obx_migrate_' . $v);
        }
        try {
            foreach ($statements as $sql) {
                if (!$db->isSqlite() && self::alreadyApplied($db, $sql)) {
                    continue;
                }
                $db->pdo->exec(self::translate($db, $sql));
            }
            if ($v === 1) {
                $db->pdo->exec("INSERT INTO obx_settings (k, v) VALUES ('write_lock', '1')");
                $db->pdo->exec("INSERT INTO obx_settings (k, v) VALUES ('schema_version', '1')");
            } else {
                $db->run("UPDATE obx_settings SET v = ? WHERE k = 'schema_version'", [(string) $v]);
            }
            if ($db->isSqlite()) {
                $db->pdo->exec('RELEASE obx_migrate_' . $v);
            }
        } catch (\Throwable $e) {
            if ($db->isSqlite()) {
                $db->pdo->exec('ROLLBACK TO obx_migrate_' . $v);
                $db->pdo->exec('RELEASE obx_migrate_' . $v);
            }
            throw new \RuntimeException('Updating the database to schema version ' . $v . ' failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** MySQL: skips CREATE TABLE / CREATE INDEX statements whose object already exists. */
    private static function alreadyApplied(Db $db, string $sql): bool
    {
        if (preg_match('/^CREATE TABLE (\w+)/', $sql, $m)) {
            return $db->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$m[1]]) > 0;
        }
        if (preg_match('/^CREATE (?:UNIQUE )?INDEX (\w+) ON (\w+)/', $sql, $m)) {
            return $db->value('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$m[2], $m[1]]) > 0;
        }
        return false;
    }

    /** Runs $fn while holding a database-wide migration lock (one process migrates at a time). */
    private static function locked(Db $db, callable $fn): void
    {
        if ($db->isSqlite()) {
            $db->pdo->exec('BEGIN IMMEDIATE');
            try {
                $fn();
                $db->pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                $db->pdo->exec('ROLLBACK');
                throw $e;
            }
            return;
        }
        $got = (int) $db->value("SELECT GET_LOCK('obx_migrate', 120)");
        if ($got !== 1) {
            throw new \RuntimeException('Another process is updating the database; try again in a minute.');
        }
        try {
            $fn();
        } finally {
            $db->value("SELECT RELEASE_LOCK('obx_migrate')");
        }
    }

    public static function currentVersionOf(Db $db): int
    {
        return self::currentVersion($db);
    }

    public static function latestVersion(): int
    {
        return max(array_keys(self::migrations()));
    }

    private static function currentVersion(Db $db): int
    {
        try {
            $v = $db->value("SELECT v FROM obx_settings WHERE k = 'schema_version'");
            return (int) $v;
        } catch (\PDOException) {
            return 0;
        }
    }

    private static function translate(Db $db, string $sql): string
    {
        if ($db->isSqlite()) {
            $map = [
                '{pk}' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                '{id}' => 'TEXT',
                '{key}' => 'TEXT',
                '{str}' => 'TEXT',
                '{text}' => 'TEXT',
                '{int}' => 'INTEGER',
            ];
            return strtr($sql, $map);
        }
        $map = [
            '{pk}' => 'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY',
            '{id}' => 'VARCHAR(100)',
            '{key}' => 'VARCHAR(200)',
            '{str}' => 'VARCHAR(255)',
            '{text}' => 'LONGTEXT',
            '{int}' => 'BIGINT',
        ];
        $sql = strtr($sql, $map);
        if (str_starts_with($sql, 'CREATE TABLE')) {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
        }
        return $sql;
    }
}
