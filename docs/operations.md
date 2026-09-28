# Operations

Everything a brand, or whoever hosts the portal for them, needs to keep it running: settings, health checks, logs, metrics, background work, backups and restores, upgrades, limits and data retention. How to install it in the first place is in [hosting.md](hosting.md).

The command-line tool is `php bin/obx` (in Docker: `docker exec -u www-data <container> php bin/obx …`). On shared hosting without a shell, every command can also be run once from the hosting panel's cron screen.

| Command | What it does |
|---|---|
| `php bin/obx check` | checks the configuration, the database, the data folder and the file storage; exit code 1 on problems. `--config-only` checks the settings without connecting anywhere |
| `php bin/obx migrate` | creates or updates the database tables (forward only) |
| `php bin/obx cron` | one pass of background work (imports, zips, e-mails, webhooks, housekeeping), at most ~50 s |
| `php bin/obx worker [--max-seconds=N]` | keeps doing background work (Docker, systemd, supervisor) |
| `php bin/obx backup FILE.zip [--no-files]` | a complete backup, see [Backups](#backups) |
| `php bin/obx restore FILE.zip [--force]` | restores a backup |
| `php bin/obx setup-code` / `cron-url` / `version` | the first-run code, the web address for hosts without cron, the version |

## Configuration

The installer writes `config/config.php`. Every setting can also come from an environment variable, which wins over the file. The portal checks all settings when it starts and names every mistake with its variable (`php bin/obx check` shows the same list); a Docker container with a wrong setting stops at once with exit code 78 and the list in its log, a web request gets `503` and the list goes to the error log.

| Variable (config.php key) | Meaning | Default |
|---|---|---|
| `OBX_PUBLIC_URL` (`public_url`) | public address, used in e-mails and API links | API links: the request's address; e-mails: the address recorded at setup |
| `OBX_SECRET_KEY` (`secret_key`) | 32+ characters; encrypts stored credentials, signs links and cursors | generated into `data/secret.key` |
| `OBX_SETUP_CODE` (`setup_code`) | preset one-time setup code | generated, printed in the log and `data/SETUP-CODE.txt` |
| `OBX_DB_DSN`, `OBX_DB_USER`, `OBX_DB_PASSWORD` | database, e.g. `mysql:host=localhost;dbname=obx;charset=utf8mb4` | `sqlite:data/obx.sqlite` |
| `OBX_DATA_DIR` (`data_dir`) | images, zips, temporary files and the SQLite file | `data/` |
| `OBX_BLOB_STORE` + `OBX_S3_ENDPOINT`, `OBX_S3_REGION`, `OBX_S3_BUCKET`, `OBX_S3_PREFIX`, `OBX_S3_ACCESS_KEY_ID`, `OBX_S3_SECRET_ACCESS_KEY`, `OBX_S3_PATH_STYLE` | images in an S3-compatible bucket (`s3`) instead of on disk (`fs`) | `fs` |
| `OBX_MAIL_TRANSPORT` (`mail`, `smtp`, `none`), `OBX_MAIL_FROM`, `OBX_SMTP_HOST`, `OBX_SMTP_PORT`, `OBX_SMTP_USER`, `OBX_SMTP_PASSWORD`, `OBX_SMTP_ENCRYPTION` (`tls`, `ssl`, `none`) | e-mail | PHP `mail()`, off without a sender |
| `OBX_RENDITIONS` | `auto`, `server` or `client`: who makes web/square/thumb | `auto` (server when GD is available) |
| `OBX_MAX_UPLOAD_BYTES` | largest single upload | 100 MB |
| `OBX_RATE_LIMIT_FACTOR` | multiplies all rate limits (`0.01` to test 429 handling) | `1` |
| `OBX_ALLOW_PRIVATE_URLS` | `true` only for local development: imports and webhooks may reach private addresses and plain http (link-local and cloud-metadata addresses stay refused) | `false` |
| `OBX_TRUST_PROXY` / `OBX_TRUSTED_PROXIES` | behind a reverse proxy or load balancer: take the client address from `X-Forwarded-For` (for rate limits and the audit log). `OBX_TRUST_PROXY=true` trusts any direct peer (Cloud Run); `OBX_TRUSTED_PROXIES` takes a list such as `10.0.0.0/8,192.168.1.5` or `private` | off |
| `OBX_LOG_FORMAT` (`text`, `json`), `OBX_LOG_FILE` (a file or `php://stderr`), `OBX_ACCESS_LOG` | logs, see [Logs](#logs) | `text` to PHP's error log, no access lines (Docker: `json` on stderr with access lines) |
| `OBX_METRICS_TOKEN` | 16+ characters: serve Prometheus metrics at `/metrics` | off |
| `OBX_WORKER` (Docker) | `0` disables the in-container worker (Cloud Run uses Cloud Scheduler) | `1` |
| `OBX_APACHE_WORKERS` (Docker) | Apache processes serving requests at once | `16` |
| `PORT` (Docker) | listen port | `8080` |

## Health checks

| Endpoint | Answer | Use it for |
|---|---|---|
| `GET /healthz` | `200 {"status":"ok"}` whenever PHP answers; it touches neither the database nor the configuration | liveness: restart when it fails repeatedly |
| `GET /readyz` | `200 {"status":"ready","checks":{…}}` when the database answers at the current schema version, the data folder is writable and the file storage answers; `503 {"status":"not_ready"}` with the failing check otherwise. `checks.setup` says whether first-run setup is done (not a failure) | readiness: send traffic only while it answers 200; uptime monitors |

The Docker image has a `HEALTHCHECK` on `/healthz`. On shared hosting, point an uptime monitor at `/readyz`.

## Logs

- **Text** (default): lines like `[obx ERROR] Job job_… failed: … (request 5f1c…)` in PHP's error log, which is what hosting panels show.
- **JSON** (`OBX_LOG_FORMAT=json`, the Docker default): one object per line with `ts`, `level`, `msg`, `request_id` and fields. With `OBX_ACCESS_LOG=1` (the Docker default) every request gets one line `{"msg":"request","method":…,"path":…,"route":"/obx/v1/products/{id}","status":…,"duration_ms":…}`; health checks and `/metrics` are not logged. Apache's own access log is off in the image.
- **Request ids.** Every response carries `X-Request-Id`. The portal keeps an id sent by a proxy or load balancer (8–128 characters of `A-Z a-z 0-9 . _ -`) and makes one otherwise. Every log line written for that request and its audit entry carry the same id, and an unexpected error tells the user the id, so one id finds everything about one request.
- **Never logged:** passwords, tokens, API keys, secrets, `Authorization` headers, cookies, query strings of signed links and the cron key (they are scrubbed even from error messages), and no client addresses.

## Metrics

Set `OBX_METRICS_TOKEN` (16+ characters) and scrape `/metrics` with `Authorization: Bearer <token>`; without the token setting the endpoint answers 404. The metrics are gauges read from the database, so they are correct no matter how many PHP processes serve requests:

`obx_build_info`, `obx_catalog_objects{type,visible}`, `obx_retailers{status}`, `obx_accounts{role,status}`, `obx_api_clients`, `obx_access_requests_pending`, `obx_jobs{kind,status}`, `obx_jobs_oldest_queued_seconds`, `obx_webhook_outbox{status}`, `obx_feed_last_seq`, `obx_import_runs_failed_24h`, `obx_background_last_run_seconds`, `obx_blob_bytes`.

Request rates and latencies come from your web server, proxy or load balancer (PHP keeps no counters between requests). Suggested alerts: `/readyz` failing for 5 minutes; `obx_background_last_run_seconds > 900` (background work stopped: cron missing or the worker died); `obx_jobs_oldest_queued_seconds > 1800`; `obx_import_runs_failed_24h > 0`; `obx_webhook_outbox{status="failed"}` increasing.

## Background work

Imports, zips of many images, e-mails, webhooks and housekeeping run in the background. Three ways, from best to simplest:

1. **A worker process** (Docker image, systemd, supervisor): `php bin/obx worker`. The image starts it; it restarts itself every hour.
2. **A cron job** every minute (or every 5 minutes): `php /path/to/bin/obx cron`, or the cron web address (`php bin/obx cron-url`, also shown in the admin under Settings) when the host can only call a web address.
3. **Nothing at all.** Without cron the portal does the work after its responses: a job starts right after the request that queued it, and any request does a short pass at most once a minute. This is tested on stock Apache hosting ([hosting.md](hosting.md#tested)); it is slower when nobody uses the portal, but nothing is lost.

Every job is a row in the database with a lease. A job whose process died (a deploy, a timeout, a kill) is picked up again after its lease expires (at most 10 minutes) and runs from the start; every job kind is safe to run again (an import of the same data changes nothing, zips are rebuilt). A job that failed three times in a row is marked failed with a message. With the pcntl extension (the Docker image has it) `docker stop` or `SIGTERM` lets the running job finish first.

Retries: webhook deliveries back off exponentially for 24 hours and a subscription that fails for 3 days is switched off; scheduled imports that fail try again at their next time; S3 requests are retried twice (0.25 s, 1 s) on connection errors, 429 and 5xx.

Timeouts on outbound calls: imports and S3 15 s to connect and 10 minutes in total, webhooks 5 s and 10 s, SMTP 30 s, SFTP 15 s to connect.

## Backups

`php bin/obx backup FILE.zip` writes everything in one zip: every table as NDJSON (read inside one transaction, so the rows are a consistent snapshot while the portal keeps running), every stored image (originals and versions), and the secret key. It works the same on SQLite and MySQL, and a backup restores into either. The zip holds personal data and the secret key: store it like a password (encrypted disk, a private bucket), and delete old ones.

- **Docker:** `docker exec -u www-data obx-portal php bin/obx backup /var/www/html/data/backup-$(date +%F).zip`, then copy it off the host (`docker cp`). Or back up the volume while the container is stopped.
- **Shared hosting:** add a second cron job, for example daily at 03:00: `php /path/to/obx-portal/bin/obx backup /path/to/private/backup-$(date +\%u).zip` (seven rotating files, one per weekday; `%` must be written `\%` in crontab). Download the files with FTP now and then. The hosting company's own backups of the web space and the MySQL database are a good second line, but only the `bin/obx backup` file is guaranteed consistent.
- **Images in a bucket (`OBX_BLOB_STORE=s3`):** `--no-files` leaves the images out; switch on versioning or replication in the bucket instead.

### Restore

1. Install the same or a newer release (a fresh folder, a fresh container, or a new database). Configure the database as usual. Leave `OBX_SECRET_KEY` unset (the key comes from the backup) or set it to the value of `secret.key` in the zip.
2. `php bin/obx restore backup.zip`. It refuses a database that already holds a portal; `--force` replaces everything in it.
3. Restart the web server or container, and check `/readyz` and a sign-in.

The restore puts back the tables at the backup's schema version and then runs the newer migrations, so a backup from an older release restores into a newer one. A backup from a newer release is refused.

This procedure is tested: the PHPUnit suite backs up an imported node, restores it into an empty one and compares the change feed, image bytes, API keys and upload keys (`tests/Http/OpsTest.php`, also from MySQL into SQLite), and it was run once by hand with the Docker image (backup of an imported node in one container, restore into a fresh one, sign-in, upload key, catalog and image download all working).

## Upgrading

1. **Read the release notes** in [CHANGELOG.md](../CHANGELOG.md) for every version you skip.
2. **Make a backup** (`php bin/obx backup`). It is your only way back: migrations only move forward.
3. **Replace the code:**
   - Shared hosting: upload the new release zip over the old folder, keeping `config/` and `data/` (they are not in the zip with content). Delete files the new release no longer has only when the notes say so.
   - Docker: `docker compose pull && docker compose up -d` (or build the new tag). The container migrates at start.
   - Cloud Run: run `deploy/cloudrun.sh` again from the new release.
4. **Migrate:** on shared hosting the first request after the upload runs the migrations (one process at a time, under a lock); `php bin/obx migrate` does it explicitly and says from which version to which.
5. **Check** `/readyz` and sign in.

Downgrades are not supported: a database written by a newer release is refused with "the database is at schema version N, but this software only knows up to version M". To go back, install the old release and restore the backup you made before upgrading.

If a migration fails (for example the database user may not create indexes), nothing is half-done on SQLite. On MySQL each statement commits by itself; fix the cause and run `php bin/obx migrate` again: statements whose table or index already exists are skipped.

## Limits and sizing

- One portal handles tens of thousands of articles on a small host: see [performance.md](performance.md) for the numbers of the seeded load test (10,000 articles, 60,000 EANs, 40,000 images).
- PHP: `memory_limit` 512 MB, `max_execution_time` 300 s, uploads up to 512 MB (`public/.user.ini`, `public/.htaccess` and the Docker image set these). Server-side image versions need roughly 4 bytes per pixel of the largest original; images above 100 megapixels are refused.
- Docker: each Apache process may use up to `memory_limit` while it renders images; `OBX_APACHE_WORKERS` (default 16) caps how many run at once. The Compose file limits the container to 2 CPUs and 2 GB; raise both together.
- SQLite is fine for one server. Use MySQL/MariaDB when several containers serve the same portal (Cloud Run with more than one instance).
- Rate limits (per client address unless noted): sign-in 30 per 15 minutes per address and 10 per e-mail address, setup 10, forgot-password e-mails 5 and new passwords 10 per 15 minutes, invitation links 30, access requests 5 per hour, token endpoint 30 per minute, discovery 600 per minute; the protocol and ingest APIs per key as in the spec. `429` answers carry `Retry-After`. Behind a proxy set `OBX_TRUST_PROXY`, or every visitor shares the proxy's limits.

## Personal data (GDPR)

The brand is the controller; a host running the portal for the brand is its processor. The portal keeps personal data minimal: names, business e-mail addresses and languages of retailer people and admins, access requests, sign-in and change history (audit log) and who downloaded what. It stores no client addresses except in the audit log, for at most 30 days. A template for the brand's privacy notice is in [privacy-notice-template.md](privacy-notice-template.md).

- **Access and portability:** a person downloads everything stored about them with `GET /admin/api/me/export`; an admin exports a person with `GET /admin/api/users/{id}/export` and a whole retailer company with `GET /admin/api/retailers/{id}/export`. The JSON document (`"format": "obx-personal-data-export/1"`) names the brand as controller and lists the retention settings. The paths and the format are the same in every OBX portal.
- **Erasure:** a person deletes their own account with `DELETE /admin/api/me` (not the last admin); an admin deletes a person (`DELETE /admin/api/users/{id}`) or a retailer (`DELETE /admin/api/retailers/{id}`). Deleting a person removes the account, sessions and selection, and removes the name, e-mail address and client address from the statistics and the audit log (the entries keep a random account id). Deleting a retailer does that for all its people and removes its API keys, webhooks and access requests. Statistics keep only the company name.
- **Retention** is a brand setting (`PATCH /admin/api/settings` with `retention`), applied by the hourly housekeeping:

| Setting | Default | Range | What goes |
|---|---|---|---|
| `download_events_days` | 730 | 30–3650 | download statistics; a person's name leaves them after at most 400 days |
| `audit_log_days` | 365 | 30–3650 | audit entries |
| `ip_days` | 30 | 1–30 | client addresses in audit entries |
| `access_requests_days` | 365 | 30–3650 | decided access requests (open ones stay until decided) |

Fixed: sessions end 30 days after the last visit (and at sign-out, password change, blocking and deletion), rate-limit counters (which hold addresses) go an hour after their window, invitation links 7 days, password links 1 hour, finished jobs 14 days, webhook delivery logs 7 days, change-feed history 90 days, prepared zips 7 days.

## Audit log

Every state-changing admin API request is recorded after it ran (by brand admins and retailer people alike), plus exports of personal data and every sign-in attempt with its outcome. Retailers' own working state (selection, download jobs, EAN lookups) is not recorded; their downloads are in the statistics. Entries never hold request bodies, passwords, tokens or secrets, only the names of the fields that were sent.

`GET /admin/api/audit-log?limit=50&cursor=…` (admins only; newest first; filters `action`, `actor_id`, `target_id`, `outcome=succeeded|failed`). Each entry has `id`, `at`, `actor` (`type`, `id`, `name`, `role`, `email`), `action` (for example `retailers.update`, `session.login`, `access-requests.approve`), `method`, `route`, `target_type`, `target_id`, `status`, `succeeded`, `request_id`, `ip` and `details`. The shape is the same in every OBX portal.

## Graceful stop

The Docker image turns `SIGTERM` (`docker stop`, a deploy) into a graceful Apache stop: running requests finish for up to 25 seconds; the worker finishes its current job and exits. Give the container at least 30 seconds (`stop_grace_period` in the Compose file). On shared hosting PHP requests end on their own; background work that was cut off is resumed ([Background work](#background-work)).

## Releases

Versions follow semantic versioning; the version is `Config::VERSION` in `src/Config.php`. A maintainer releases by updating CHANGELOG.md and the version, then pushing a tag `vX.Y.Z`. The release workflow (`.github/workflows/release.yml`) builds the release zip (with `vendor/` and the web interface), `SHA256SUMS`, SPDX and CycloneDX SBOMs and build provenance as a draft GitHub release, and pushes multi-architecture images (linux/amd64, linux/arm64) to `ghcr.io/openbrandexchange/node-php`. Nothing is published by pushing to `main`.

Check a download with `sha256sum -c SHA256SUMS` (on macOS `shasum -a 256 -c SHA256SUMS`) and, for provenance, `gh attestation verify obx-portal-php-vX.Y.Z.zip --repo openbrandexchange/node-php`.

## Troubleshooting

| Symptom | Look at |
|---|---|
| "Service unavailable" on every page | the error log: the configuration problems or the database error are listed there; `php bin/obx check` |
| "This portal still needs to be set up" after setup | fixed in 0.1.0 (an old worker could undo setup); upgrade |
| Imports stay "queued" | background work is not running: add a cron job, or check `obx_background_last_run_seconds` / the worker's log |
| Uploads of large zips fail | `upload_max_filesize` / `post_max_size` in the host's PHP settings (the installer's server check shows the current limit); upload images as one zip if the host ignores `.user.ini` |
| E-mails do not arrive | the sender address and SMTP settings; without a sender, e-mails are off and invitation links are shown to copy |
| Retailers get "too many requests" behind a proxy | set `OBX_TRUST_PROXY` or `OBX_TRUSTED_PROXIES` |
