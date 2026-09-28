# Open Brand Exchange · Portal for web hosting

Your own brand portal, on the web hosting you already have. Retailers log in to find your
product photos and article data (EAN, sizes, colours, materials, care), download what they need,
and their shop software can pull the same content automatically. You keep publishing from your
own systems: upload a spreadsheet, point the portal at an export folder, or let your PIM send
updates.

This is the PHP edition of an [Open Brand Exchange](https://openbrandexchange.com) portal
(repository `node-php`). It runs on ordinary shared hosting (Apache or nginx with PHP 8.2+),
in Docker, or on Google Cloud Run. Other editions: *Portal for Cloudflare* and
*Portal for Google Cloud & Docker*. They all speak the same open standard, so retailers and
your automation work with any of them.

**What you get**

- **A portal for your retailers** ("Kestrel · Beeldbank" in Dutch, "Händlerportal" in German,
  …; 25 languages): request access, browse and filter, paste a list of EANs, pick products,
  download single images, a zip per product, a zip of a selection, or everything in a filter in
  the background, and the article data as a spreadsheet (CSV). Every download is counted for you.
- **Your admin**: brand profile and branding (logo, colour, welcome text), products and images,
  imports with clear error lists, retailers and their access requests, retailer accounts, API
  keys for retailers' software, automation tokens, webhooks and download statistics.
- **An open API for retailers' software** (the OBX Protocol): a change feed, product lookups by
  EAN, image downloads in *original*, *web*, *square* and *thumb* versions, bulk exports and
  signed webhooks.
- **Easy automation** (the OBX Ingest API): a spreadsheet plus images named
  `STYLE_COLOUR_1.jpg`, scheduled imports from a web address, an SFTP folder or an S3/R2 bucket,
  the `obx` command-line tool, and copy-paste recipes for cron, GitHub Actions, n8n, Make and
  Zapier. Sending the same data twice changes nothing.
- Images are stored exactly as uploaded (so embedded Content Credentials survive); the portal
  makes the web, square and thumbnail versions itself when the server has GD, otherwise the web
  interface and the `obx` tool make them.

## 60-second quickstart

With Docker, in a copy of this repository:

```sh
git clone https://github.com/openbrandexchange/node-php.git obx-portal && cd obx-portal
docker compose up -d
docker compose logs portal | grep "Setup code"
```

Open <http://localhost:8080> and enter the setup code from the last command. Choose your
company name, languages and your own admin password. There is no default password. Your data
stays in the `portal-data` volume; `docker compose down` keeps it, `docker compose down -v`
deletes it.

Without Compose: `docker build -t obx-portal .` and
`docker run -d --name obx-portal -p 8080:8080 -v obx-data:/var/www/html/data obx-portal`, then
`docker logs obx-portal | grep "Setup code"`.

Without Docker, in the same folder (PHP 8.2+ with pdo_sqlite, mbstring and openssl; GD and zip
recommended; [Composer](https://getcomposer.org)):

```sh
composer install
OBX_PUBLIC_URL=http://localhost:8080 php bin/obx serve 8080
```

The setup code is printed in the terminal (and written to `data/SETUP-CODE.txt`). This built-in
server is for trying the portal out; for real use pick one of the hosting options below.

## Install on your web hosting (FTP)

1. Download the release zip `obx-portal-php-<version>.zip` and unzip it on your computer.
2. Upload the folder with your FTP program. Best: make the portal's `public` folder the
   *document root* of your (sub)domain in the hosting control panel, e.g.
   `brands.yourbrand.com → /obx-portal/public`. If you can only upload into `public_html`, upload
   the *contents* of the folder there; the included `.htaccess` files keep everything except
   `public` private.
3. Make the `data` and `config` folders writable for the web server (usually permission 775).
4. Open `https://brands.yourbrand.com/install`. The installer checks your server, then asks for:
   - the **setup code**: open `data/SETUP-CODE.txt` with your FTP program (this proves you
     uploaded the files);
   - the web address of the portal;
   - the **database**: "Simple" (a file in the data folder, fine for most brands) or the MySQL /
     MariaDB database from your hosting account;
   - optionally the e-mail sender address (and SMTP server) for invitations.
5. Click *Open the setup wizard* and create your admin account.
6. Add a **cron job** in your hosting control panel so imports, zips and e-mails run in the
   background (every minute, or every 5 minutes):
   `php /path/to/obx-portal/bin/obx cron` — or, if your host can only call a web address, the
   cron URL the installer showed. Without a cron job the portal still works: it catches up
   whenever an admin is logged in.

Requirements: PHP 8.2 or newer with PDO (SQLite or MySQL), OpenSSL and mbstring. GD (image
versions on the server), zip (zip uploads) and curl (scheduled imports, webhooks) are strongly
recommended; the installer tells you what is missing. Uploads up to 500 MB need
`upload_max_filesize`/`post_max_size`; `public/.user.ini` and `public/.htaccess` raise them on
most hosts.

## Other ways to host

| Where | How |
|---|---|
| Docker / a VPS | `docker compose up -d` (see `docker-compose.yml`; SQLite by default, MySQL with `--profile mysql`). The container runs Apache, PHP 8.3 with GD and a background worker. |
| Google Cloud Run | `PROJECT=my-project PUBLIC_URL=https://brands.example.com ./deploy/cloudrun.sh` — builds the container, creates Cloud SQL for MySQL, a Cloud Storage bucket for images, the secret key in Secret Manager and a Cloud Scheduler job for background work. Cloud Run disks are temporary, so SQLite is only for trying out (`TRY_OUT=1`). |
| Your own server with nginx | see `docs/nginx.conf` (document root `public/`, PHP-FPM, cron). |

Custom domain: point the domain at your host (A record or CNAME), add it in your hosting panel
with HTTPS, and set the public address (`public_url` in `config/config.php` or
`OBX_PUBLIC_URL`). The discovery address `/.well-known/obx` works on every domain the portal
answers on.

## Automate publishing

Create a token in the admin under *Automation* (it starts with `obxi_`), then pick what fits:

- **Scheduled import** (no code): *Imports → Scheduled imports → Add*, with the address of your
  `products.csv` or zip, an SFTP folder, or an S3-compatible bucket (AWS S3, Cloudflare R2,
  MinIO, Backblaze). Unchanged files are skipped; *Mirror* also takes products offline that
  disappeared from the file, but refuses when more than half would disappear.
- **The `obx` tool** from a PC, server, cron or CI: `obx push --csv products.csv --images ./images`
  or `obx watch ./outbox`.
- **Direct HTTP** from n8n, Make, Zapier or your PIM: `PUT /obx/v1/ingest/products/{style}` and
  friends; every call is safe to repeat.

The file format and all recipes: [canonical import format](https://github.com/openbrandexchange/spec/blob/main/docs/import-format.md)
and [automation recipes](https://github.com/openbrandexchange/spec/blob/main/docs/automation.md).
Download a filled-in template in the admin under *Imports*.

## Configuration

The installer writes `config/config.php`. Every setting can also come from an environment
variable (Docker, Cloud Run), which wins over the file.

| Variable | Meaning | Default |
|---|---|---|
| `OBX_PUBLIC_URL` | public address, used in e-mails and API links | the address of the request |
| `OBX_SECRET_KEY` | 32+ characters; encrypts stored credentials, signs links and cursors | generated into `data/secret.key` |
| `OBX_SETUP_CODE` | preset one-time setup code | generated, printed in the log and `data/SETUP-CODE.txt` |
| `OBX_DB_DSN`, `OBX_DB_USER`, `OBX_DB_PASSWORD` | database, e.g. `mysql:host=localhost;dbname=obx;charset=utf8mb4` | `sqlite:data/obx.sqlite` |
| `OBX_DATA_DIR` | where images, zips and the SQLite file live | `data/` |
| `OBX_BLOB_STORE` + `OBX_S3_ENDPOINT`, `OBX_S3_REGION`, `OBX_S3_BUCKET`, `OBX_S3_PREFIX`, `OBX_S3_ACCESS_KEY_ID`, `OBX_S3_SECRET_ACCESS_KEY`, `OBX_S3_PATH_STYLE` | keep images in an S3-compatible bucket (`s3`) instead of on disk (`fs`) | `fs` |
| `OBX_MAIL_TRANSPORT` (`mail`, `smtp`, `none`), `OBX_MAIL_FROM`, `OBX_SMTP_HOST`, `OBX_SMTP_PORT`, `OBX_SMTP_USER`, `OBX_SMTP_PASSWORD`, `OBX_SMTP_ENCRYPTION` (`tls`, `ssl`, `none`) | e-mail | PHP `mail()`, off without a sender |
| `OBX_RENDITIONS` | `auto`, `server` or `client` (who makes web/square/thumb) | `auto` (server when GD is available) |
| `OBX_MAX_UPLOAD_BYTES` | largest single upload | 100 MB |
| `OBX_RATE_LIMIT_FACTOR` | multiplies all rate limits (e.g. `0.01` to test 429 handling) | `1` |
| `OBX_ALLOW_PRIVATE_URLS` | `true` only for local development: allow imports and webhooks to private addresses and plain http | `false` |
| `OBX_WORKER` (Docker) | `0` disables the in-container background worker (Cloud Run uses Cloud Scheduler) | `1` |
| `PORT` (Docker) | listen port | `8080` |

Command line: `php bin/obx cron | worker | setup-code | cron-url | migrate | serve | version`.

## For developers

Everything runs in Docker; no local PHP is needed.

```sh
./scripts/test.sh                      # PHPUnit: unit + HTTP tests on SQLite (PHP 8.3)
./scripts/test-mysql.sh mysql          # the same against MySQL 8.4 (or: mariadb)
./scripts/test-sources.sh              # scheduled imports against real S3 (SeaweedFS) and SFTP servers
./scripts/conformance.sh ../obx-go     # the official obx-conformance suite against the Docker image (47/47 pass)
./scripts/build-release.sh             # build/obx-portal-php-<version>.zip for FTP upload
./scripts/update-ui.sh ../admin-ui     # re-copy admin-ui's committed dist/ into public/ui (the served web interface)
./scripts/vendor-ui.sh ../admin-ui     # build admin-ui from its sources first, then copy it
./scripts/sync-spec.sh ../spec         # refresh tests/fixtures/spec from the spec repository
```

The HTTP tests drive the whole node through its router with the spec's *Kestrel Outfitters*
fixtures: the snapshot and change feed, the expected product and asset documents, the EAN
lookup, the ingest replay script (one by one, as a JSON batch and as NDJSON), imports (package,
separate files, image manifest, mirror, dry run, zip-slip, row errors), sessions and CSRF,
invitations, access requests, key rotation, the portal, downloads, exports, webhooks over real
sockets, and the installer behind PHP's web server.

Layout: `public/` (web root: `index.php`, `.htaccess`, `ui/`), `src/` (`Catalog` write path and
change feed, `Controllers`, `Import`, `Ingest`, `Jobs`, `Services`, `Security`, `Sources`,
`Storage`, `Install`), `bin/obx`, `tests/`, `deploy/`, `docker/`. Decisions and deviations from the
spec are in [NOTES.md](NOTES.md).

Security: secrets are random and stored hashed (argon2id passwords, SHA-256 tokens) or
encrypted (AES-256-GCM for webhook secrets and import credentials); sessions use HttpOnly
SameSite cookies with CSRF tokens; content links are signed and expire; outbound requests are
checked against private networks (also after redirects and pinned against DNS rebinding);
zip imports are checked for unsafe paths and sizes; CSV exports are formula-safe. Report
vulnerabilities privately through a GitHub security advisory.

## License

AGPL-3.0-only. See [LICENSE](LICENSE). The specification is CC-BY-4.0 and lives in
[openbrandexchange/spec](https://github.com/openbrandexchange/spec).

*Works with Open Brand Exchange.*
