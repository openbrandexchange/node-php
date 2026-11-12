# Open Brand Exchange · Portal for web hosting

Your own brand portal, on the web hosting you already have. Retailers log in to find your
product photos and article data (EAN, sizes, colours, composition, care, measurements, features
and the other label information), download what they need,
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
- **An open API for retailers' software** (the OBX Protocol 0.3): a change feed, product lookups
  by EAN, the article data (composition and care with their names in English, Dutch, German and
  French, recycled, organic and regenerative fibres, the animal-origin
  statement, footwear materials, certifications, features, measurements per size), image
  downloads in *original*, *web*, *square* and *thumb* versions, bulk exports and signed webhooks.
- **Label warnings**: imports and the Ingest API tell you when an article misses what its label
  needs by EU law (fibre composition, non-textile parts of animal origin, footwear materials);
  they never block publishing.
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
3. Make the `data` and `config` folders writable for the web server: in your FTP program,
   *File permissions* 775 (if the installer still says "not writable" after a reload: 777).
4. Open `https://brands.yourbrand.com` (in your browser's language; 25 languages). The
   installer checks your server, then asks for the **setup code** from `data/SETUP-CODE.txt`
   (open it with your FTP program: this proves you uploaded the files), the web address, the
   database ("Simple", a file in the data folder, or your MySQL/MariaDB) and optionally the
   e-mail sender.
5. Click *Open the setup wizard* (the setup code is filled in) and create your admin account.
   There is no default password.
6. Add a **cron job** in your hosting control panel (every minute or every 5 minutes) with what
   the installer shows: `php /path/to/obx-portal/bin/obx cron`, or the cron web address (on hosts
   where PHP runs as the web server's user, only the web address works). Without a cron job the
   portal still works: it does the background work after its own requests.

Requirements: Apache with mod_rewrite (or nginx), PHP 8.2, 8.3 or 8.4 with PDO (SQLite or MySQL), OpenSSL and mbstring. GD (image
versions on the server), zip (zip uploads) and curl (scheduled imports, webhooks) are strongly
recommended; the installer tells you what is missing. This path is tested on every change on
stock Apache hosting with only PDO SQLite, GD, curl, zip and mbstring, and without cron.
Everything else about hosting (Docker, nginx, Cloud Run, custom domains, HTTPS, backups):
[docs/hosting.md](docs/hosting.md).

## Other ways to host

| Where | How |
|---|---|
| Docker / a VPS | `docker compose up -d` (SQLite by default, MySQL with `--profile mysql`). Apache, PHP 8.3 with GD, a background worker, JSON logs, health check, graceful stop. |
| Google Cloud Run | `PROJECT=my-project PUBLIC_URL=https://brands.example.com ./deploy/cloudrun.sh`: Cloud SQL for MySQL, a Cloud Storage bucket for images, the secret key in Secret Manager, Cloud Scheduler for background work. |
| Your own server with nginx | [docs/nginx.conf](docs/nginx.conf) (document root `public/`, PHP-FPM, cron or a systemd worker). |

## Automate publishing

Create an upload key in the admin under *Automatic upload → Access keys* (it starts with
`obxi_`), then pick what fits: a **scheduled import** from a web address, an SFTP folder or an
S3-compatible bucket (no code; *Mirror* also takes articles offline that disappeared, but refuses
when more than half would disappear; an Excel workbook works wherever a CSV does); the **`obx` tool** (`obx push --csv products.csv --images
./images`, `obx watch ./outbox`); or **direct HTTP** from n8n, Make, Zapier or your PIM. Every
call is safe to repeat. Details: [docs/automation.md](docs/automation.md) and the
specification's [import format](https://github.com/openbrandexchange/spec/blob/main/docs/import-format.md)
and [automation recipes](https://github.com/openbrandexchange/spec/blob/main/docs/automation.md).

## Running it

- Configuration: `config/config.php` (written by the installer) or `OBX_*` environment
  variables; checked at start, every mistake named (`php bin/obx check`). All settings:
  [docs/operations.md](docs/operations.md#configuration).
- Health: `/healthz` (alive) and `/readyz` (database, storage); Prometheus `/metrics` with
  `OBX_METRICS_TOKEN`; JSON logs with request ids (`OBX_LOG_FORMAT=json`).
- Backups: `php bin/obx backup FILE.zip` / `php bin/obx restore FILE.zip` (tested; SQLite and
  MySQL, either way round). Upgrades are forward-only and refuse a database from a newer
  release. See [docs/operations.md](docs/operations.md#backups).
- Privacy: audit log of admin actions, "download my data" and account deletion, retention
  settings, and a [privacy-notice template](docs/privacy-notice-template.md) for brands.
- Performance: a seeded load test with 10,000 articles, 60,000 EANs and 40,000 images keeps
  catalog pages, the change feed and EAN lookups fast ([docs/performance.md](docs/performance.md)).

Command line: `php bin/obx cron | worker | check | migrate | backup | restore | setup-code | cron-url | serve | version`.

## For developers

Everything runs in Docker; no local PHP is needed. [CONTRIBUTING.md](CONTRIBUTING.md) has the
rules for changes (tests test behaviour, shared hosting first, 25 languages, DCO sign-off).

```sh
./scripts/test.sh                      # PHPUnit on PHP 8.3 (PHP=8.2 / PHP=8.4 for the others)
./scripts/test-mysql.sh mysql          # the same on MySQL 8.4 (or: mariadb)
./scripts/test-sources.sh              # scheduled imports against real S3 (SeaweedFS) and SFTP servers
./scripts/test-hosting.sh              # release zip + web installer on stock php-apache 8.2/8.3/8.4, no cron
./scripts/ui-walk.sh                   # the real web interface in a browser: every flow, axe, keyboard only
./scripts/conformance.sh ../obx-go     # the official obx-conformance suite against the Docker image (DB=mysql, RENDITIONS=client)
./scripts/load-test.sh                 # seeded load test (10 000 articles), prints a table of timings
./scripts/check-licenses.sh            # licences of the production dependencies
./scripts/build-release.sh             # build/obx-portal-php-<version>.zip for FTP upload
./scripts/update-ui.sh ../admin-ui     # re-copy admin-ui's committed dist/ into public/ui
./scripts/sync-spec.sh ../spec         # refresh tests/fixtures/spec from the spec repository
```

Static analysis: `docker run --rm -v "$PWD":/app -w /app obx-node-php-test vendor/bin/phpstan analyse`
(level 5, clean). CI runs all of the above on every push; releases are built from version tags
only ([docs/operations.md](docs/operations.md#releases)).

Layout: `public/` (web root: `index.php`, `.htaccess`, `ui/`), `src/` (`Catalog` write path and
change feed, `Controllers`, `Import`, `Ingest`, `Jobs`, `Services`, `Security`, `Sources`,
`Storage`, `Install`, `Ops`), `bin/obx`, `resources/` (installer texts, glossary, common
passwords), `tests/`, `deploy/`, `docker/`, `docs/`. Decisions and deviations from the spec are
in [NOTES.md](NOTES.md).

## Security

Secrets are random and stored hashed (argon2id passwords, SHA-256 tokens) or encrypted
(AES-256-GCM for webhook secrets and import credentials); sessions use HttpOnly SameSite cookies
with CSRF tokens; content links are signed and expire; outbound requests only reach public
addresses (link-local and cloud-metadata addresses are always refused, and the checked address
is pinned for the connection); zip imports and Excel workbooks are checked for unsafe paths, macros
and sizes; CSV exports
are formula-safe; strict security headers; CORS only on the discovery document. The details are
in [docs/security.md](docs/security.md). **Report vulnerabilities privately to
security@openbrandexchange.com** ([SECURITY.md](SECURITY.md)).

## Licence and publisher

AGPL-3.0-only, see [LICENSE](LICENSE) and [NOTICE](NOTICE). The specification is CC-BY-4.0 and
lives in [openbrandexchange/spec](https://github.com/openbrandexchange/spec); the web interface
([admin-ui](https://github.com/openbrandexchange/admin-ui)) is Apache-2.0.

Open Brand Exchange Foundation (in formation) ·
hello@openbrandexchange.com. Everyone taking part follows the [code of conduct](CODE_OF_CONDUCT.md).

*Works with Open Brand Exchange.*
