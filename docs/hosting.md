# Hosting the portal

The PHP edition runs wherever PHP 8.2 or newer runs. Pick the option that matches what you already have:

| You have | Take | Background work | Database |
|---|---|---|---|
| Ordinary web hosting (cPanel, Plesk, DirectAdmin, a Dutch or German hosting package) | [the release zip over FTP](#shared-web-hosting-ftp) | cron job, or none | SQLite file, or the MySQL/MariaDB of your package |
| A VPS or a server with Docker | [Docker Compose](#docker-and-docker-compose) | built in | SQLite, or MySQL with `--profile mysql` |
| A server with nginx and PHP-FPM | [nginx](#your-own-server-with-nginx-or-apache) | cron or systemd | SQLite or MySQL |
| A Google Cloud project | [Cloud Run](#google-cloud-run) | Cloud Scheduler | Cloud SQL for MySQL |

Whatever you choose, the portal is the same: the same screens, the same API, and backups move between the options ([operations.md](operations.md#backups)).

## Requirements

- PHP 8.2, 8.3 or 8.4 with PDO (SQLite or MySQL), OpenSSL and mbstring. These are on by default on almost every host.
- Recommended: GD with JPEG (the portal makes the web, square and thumbnail versions itself), zip (zip uploads and downloads) and curl (scheduled imports, webhooks, images in a bucket). The installer's server check tells you what is missing and what that means; without GD the web interface and the `obx` tool make the versions instead.
- MySQL 8 or MariaDB 10.6 or newer when you do not use the SQLite file (tested with MySQL 8.4 and MariaDB 11).
- Disk: the images you upload plus their web, square and thumbnail versions (a fraction of the originals for large photos).

<a id="tested"></a>
**Tested on every change** (`scripts/test-hosting.sh`): the release zip in stock `php:8.2-apache`, `php:8.3-apache` and `php:8.4-apache` images with only PDO SQLite, GD, curl, zip and mbstring, both uploaded into `public_html` and with the document root on `public/`. The installer, the private folders, first-run setup, an import, a background zip and a due scheduled import must all work **without a cron job**, and PHP must log no warning or deprecation.

## Shared web hosting (FTP)

1. **Download** `obx-portal-php-<version>.zip` from the [releases](https://github.com/openbrandexchange/node-php/releases) and check it (`sha256sum -c SHA256SUMS`). Unzip it on your computer: you get a folder `obx-portal`.
2. **Upload** it with your FTP program (FileZilla, Cyberduck, WinSCP) or the file manager of your host.
   - *Best:* upload the folder next to (not into) your web folder, and make its `public` folder the **document root** of a (sub)domain in the hosting panel, e.g. `brands.yourbrand.com → /obx-portal/public`.
   - *If you can only use `public_html`:* upload the **contents** of `obx-portal` into it. The `.htaccess` files send every request to `public/` and refuse the other folders (`config`, `data`, `src`, `vendor` …), so nothing private can be downloaded.
3. **Permissions:** make `data` and `config` writable for the web server (usually 775; your FTP program calls it "file permissions").
4. **Install:** open `https://brands.yourbrand.com/install`. The page is in the language of your browser (25 languages; switch at the top). It checks your server, then asks for:
   - the **setup code**: open `data/SETUP-CODE.txt` with your FTP program. This proves you are the one who uploaded the files; nobody else can claim a freshly uploaded portal.
   - the web address of the portal;
   - the database: *Simple* (a file in the data folder, fine for most brands) or the MySQL/MariaDB database from your hosting package;
   - optionally the sender address (and SMTP server) for invitations and "your download is ready" e-mails.
5. **Setup wizard:** click *Open the setup wizard* and create your admin account. There is no default password.
6. **Background work:** add a cron job in the hosting panel, every minute or every 5 minutes:
   `php /home/you/obx-portal/bin/obx cron` (the installer shows the exact path), or, if your host can only call a web address, the cron address it shows. Without a cron job the portal still works: it does the work right after the request that asked for it and catches up whenever someone uses the portal.
7. **Backups:** add a second cron job, daily: `php /home/you/obx-portal/bin/obx backup /home/you/backups/portal-$(date +\%u).zip` ([operations.md](operations.md#backups)).

**Uploads.** `public/.user.ini` (PHP-FPM hosts) and `public/.htaccess` (Apache with mod_php) raise the upload limit to 512 MB and switch PHP's own form parsing off, so many images can be uploaded at once. Some hosts ignore both files: then the installer's check shows a lower limit; ask the host to raise `upload_max_filesize` and `post_max_size`, and upload many images as one zip.

**HTTPS.** Switch on the free certificate (Let's Encrypt) for the domain in the hosting panel. The portal sets HSTS once it is reached over HTTPS.

**Upgrading:** upload the new release over the old folder; `config/` and `data/` stay. See [operations.md](operations.md#upgrading).

## Docker and Docker Compose

In a copy of this repository (or with the published image `ghcr.io/openbrandexchange/node-php`):

```sh
docker compose up -d
docker compose logs portal | grep "Setup code"
```

Open <http://localhost:8080> and enter the setup code. Data lives in the `portal-data` volume. The image runs Apache with PHP 8.3, GD, zip, intl, pcntl and opcache, and a background worker; it logs JSON to stderr, has a health check on `/healthz`, refuses to start with a wrong setting (exit code 78, the problems in the log), stops gracefully on `docker stop`, and is built for linux/amd64 and linux/arm64.

- **MySQL instead of SQLite:** `docker compose --profile mysql up -d` with `OBX_DB_DSN=mysql:host=db;dbname=obx;charset=utf8mb4`, `OBX_DB_USER=obx`, `OBX_DB_PASSWORD=…`.
- **A public server:** put a reverse proxy with HTTPS in front, for example Caddy:

  ```
  brands.yourbrand.com {
      reverse_proxy 127.0.0.1:8080
  }
  ```

  and set `OBX_PUBLIC_URL=https://brands.yourbrand.com` and `OBX_TRUSTED_PROXIES=private` (so rate limits see the visitor's address, not the proxy's).
- **Without Compose:** `docker run -d --name obx-portal -p 8080:8080 -v obx-data:/var/www/html/data -e OBX_PUBLIC_URL=https://brands.yourbrand.com ghcr.io/openbrandexchange/node-php:0.1`.
- **Sizing:** the Compose file limits the container to 2 CPUs and 2 GB and runs at most 16 Apache processes (`OBX_APACHE_WORKERS`). See [operations.md](operations.md#limits-and-sizing).
- **Monitoring:** `/readyz` for uptime checks, `OBX_METRICS_TOKEN` for Prometheus ([operations.md](operations.md#metrics)).

## Your own server with nginx or Apache

- **nginx + PHP-FPM:** [nginx.conf](nginx.conf) is a complete server block: document root on `public/`, everything else private, PHP settings for large uploads, and the cron line. Install the PHP extensions (`php8.3-sqlite3 php8.3-mysql php8.3-gd php8.3-zip php8.3-curl php8.3-mbstring` on Debian/Ubuntu), run `composer install --no-dev` in a clone or use the release zip, then open `/install`.
- **Apache:** point the virtual host's `DocumentRoot` at `public/` with `AllowOverride All`; the `.htaccess` there does the rest (mod_rewrite and mod_headers).
- **Background work:** a cron line for the web server user (`* * * * * php /var/www/obx-portal/bin/obx cron`), or a systemd service running `php bin/obx worker` with `Restart=always`:

  ```ini
  [Unit]
  Description=Open Brand Exchange portal worker
  After=network-online.target

  [Service]
  User=www-data
  WorkingDirectory=/var/www/obx-portal
  ExecStart=/usr/bin/php bin/obx worker --max-seconds=3600
  Restart=always
  KillSignal=SIGTERM
  TimeoutStopSec=60

  [Install]
  WantedBy=multi-user.target
  ```

## Google Cloud Run

```sh
PROJECT=my-project REGION=europe-west4 PUBLIC_URL=https://brands.yourbrand.com ./deploy/cloudrun.sh
```

The script (run it from a checkout of the release, with `gcloud` logged in and billing on the project) enables the services it needs and creates, once:

- the secret key in Secret Manager,
- Cloud SQL for MySQL (`SQL_TIER`, default `db-f1-micro`) with a database and a user whose password is in Secret Manager,
- a Cloud Storage bucket for the images with an HMAC key (the portal talks to it through the S3 API),
- the Cloud Run service from the Dockerfile (1 CPU, 1 GiB, 600 s timeout, `OBX_TRUST_PROXY=true`, no in-container worker),
- a Cloud Scheduler job that calls the portal's cron address every minute.

Run it again to deploy a new release. Cloud Run disks are temporary, so `TRY_OUT=1` (SQLite inside the container, one instance, data lost on every restart) is only for trying it out. Custom domain: `gcloud beta run domain-mappings create --service obx-portal --domain brands.yourbrand.com --region europe-west4`, then set `PUBLIC_URL` to it and run the script again. Health checks: configure `/readyz` as the startup probe and `/healthz` as the liveness probe in the service settings. The script has only been checked with shellcheck, not run against a real project; see [NOTES.md](../NOTES.md).

## Custom domain and the discovery address

Point the domain at your host (an A record or a CNAME), add it in the hosting panel with HTTPS, and set the public address (`public_url` in `config/config.php` or `OBX_PUBLIC_URL`) so e-mails link to it. Retailers' software finds the portal at `https://brands.yourbrand.com/.well-known/obx`, which works on every domain the portal answers on. You can list your portal in the public directory of Open Brand Exchange portals: see [openbrandexchange.com](https://openbrandexchange.com).
