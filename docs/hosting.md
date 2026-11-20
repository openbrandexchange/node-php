# Hosting the portal

The PHP edition is made for **ordinary web hosting**: the hosting package with a control panel
(DirectAdmin, cPanel, Plesk) that many brands already have for their website. You upload one zip,
open the installer in your browser and answer a few questions. No server of your own, no command line.

| You have | Take | Background work | Database |
|---|---|---|---|
| Ordinary web hosting with PHP (DirectAdmin, cPanel, Plesk, a Dutch or German hosting package) | [the release zip](#ordinary-web-hosting-step-by-step) | cron job, or none | SQLite file, or the MySQL/MariaDB of your package |
| A server with nginx or Apache and PHP-FPM that you manage yourself | [nginx or Apache](#your-own-server-with-nginx-or-apache) | cron or systemd | SQLite or MySQL |

Renting a Linux server (VPS) just for the portal? Then the **Portal for your own server**
([node-go](https://github.com/openbrandexchange/node-go)) is simpler: one command installs it with
HTTPS (`curl -fsSL https://openbrandexchange.com/install.sh | sudo sh -s -- --domain portal.yourbrand.com`).
All editions speak the same protocol and have the same screens; backups of this edition move between
the options above ([operations.md](operations.md#backups)).

**How long it takes.** Trying it out on your own computer takes a few minutes (`composer dev`, see the
README). Putting a real portal online takes longer the first time: count on **half a day to a day** for
a subdomain, HTTPS, the database choice, the e-mail sender, a cron job, your branding and a first import
of your own spreadsheet and images, and more when your hosting company has to switch something on (PHP
version, an extension). Later upgrades take minutes plus a backup.

## Requirements

- PHP 8.2, 8.3 or 8.4 with PDO (SQLite or MySQL), OpenSSL and mbstring. These are on by default on almost every host.
- Recommended: GD with JPEG (the portal makes the web, square and thumbnail versions itself), zip (zip uploads and downloads) and curl (scheduled imports, webhooks, images in a bucket). The installer's server check tells you what is missing and what that means; without GD the web interface and the `obx` tool make the versions instead.
- MySQL 8 or MariaDB 10.6 or newer when you do not use the SQLite file (tested with MySQL 8.4 and MariaDB 11).
- Disk: the images you upload plus their web, square and thumbnail versions (a fraction of the originals for large photos).

<a id="tested"></a>
**Tested on every change** (`scripts/test-hosting.sh`): the release zip in stock `php:8.2-apache`, `php:8.3-apache` and `php:8.4-apache` images with only PDO SQLite, GD, curl, zip and mbstring, both uploaded into `public_html` and with the document root on `public/`. The installer, the private folders, first-run setup, an import, a background zip and a due scheduled import must all work **without a cron job**, and PHP must log no warning or deprecation.

## Ordinary web hosting, step by step

What you need: a hosting package with PHP 8.2 or newer and a control panel, and a domain you can make
a subdomain on, for example `portal.yourbrand.com`. Below are the steps for DirectAdmin, cPanel and
Plesk; other panels have the same steps under similar names. Labels can differ slightly per version and
hosting company.

### Step 1: download the release

Download `obx-portal-php-<version>.zip` from the [releases](https://github.com/openbrandexchange/node-php/releases).
Optionally check it with `SHA256SUMS` from the same release (`sha256sum -c SHA256SUMS --ignore-missing`).
The zip contains one folder, `obx-portal`.

### Step 2: create the subdomain and upload the portal

The best setup makes the folder `obx-portal/public` the **document root** of the subdomain, so only
that folder is reachable from the web. Where the panel cannot do that, put the *contents* of
`obx-portal` in the subdomain's folder: the included `.htaccess` files then send every request to
`public/` and refuse the other folders (`config`, `data`, `src`, `vendor` …). Both setups are tested on
every change ([Requirements](#tested)).

**DirectAdmin**

1. **Subdomain Management** → **Add Subdomain** → type `portal` → **Create**. DirectAdmin makes the
   folder `domains/yourbrand.com/public_html/portal`.
2. **File Manager** → open `domains/yourbrand.com/public_html/portal` → **Upload files** → choose the zip.
   Then select the zip → **Extract**. You now have `portal/obx-portal/…`.
3. Open `obx-portal`, select everything (also the hidden `.htaccess`; switch on "show hidden files" if
   you do not see it) and **Move** it one level up, to `domains/yourbrand.com/public_html/portal`.
   Delete the empty `obx-portal` folder and the zip.

   With an FTP program (FileZilla, Cyberduck, WinSCP) you can instead unzip on your computer and
   upload the *contents* of `obx-portal` straight into that folder.
4. **PHP version:** in **Domain Setup** or **Select PHP version** (depends on the host), choose 8.2 or newer.

**cPanel**

1. **File Manager** → your home folder (not `public_html`) → **Upload** → choose the zip. Select it →
   **Extract**. You now have `/home/<you>/obx-portal`.
2. **Domains** → **Create A New Domain** (older cPanel: **Subdomains**) → `portal.yourbrand.com`. Untick
   "Share document root" and set the **Document Root** to `obx-portal/public` → **Submit**.
3. **PHP version:** **MultiPHP Manager** (or **Select PHP Version**) → choose 8.2 or newer for
   `portal.yourbrand.com`.

**Plesk**

1. **Websites & Domains** → **Add Subdomain** → `portal`, parent domain `yourbrand.com`. As
   **Document root** type `portal.yourbrand.com/obx-portal/public` → **OK**.
2. **Files** (File Manager) → open the folder `portal.yourbrand.com` → **Upload** → choose the zip.
   Select it → **Extract Files**. You now have `portal.yourbrand.com/obx-portal/public`, the
   document root you set.
3. **PHP version:** at the subdomain, **PHP** (PHP Settings) → choose 8.2 or newer.

**Permissions** (all panels): `data` and `config` must be writable for PHP. On almost every panel
host PHP runs as your own account and nothing needs to change. If the installer says "not writable",
set them to 775 in the file manager or FTP program (FileZilla: right-click → *File permissions*); if it
still says so after a reload, use 777. That happens on hosts where PHP runs as the web server's own user.

### Step 3: DNS

If your domain's DNS is with the same hosting company (usually the case when the domain came with the
package), creating the subdomain was enough: the panel added the DNS record for you.

If your domain's DNS is somewhere else, add an A record there, pointing at the hosting server's IP
address (the panel shows it, for example on its start page or under "Account information" / "Server
information"):

| Type | Name | Value |
|---|---|---|
| A | portal | the hosting server's IP address, e.g. 203.0.113.10 |

Leave TTL at the default (or 1 hour). Check with `dig +short portal.yourbrand.com` or an online DNS
checker such as dnschecker.org: it must show that address. Usually that takes 5 to 60 minutes;
sometimes a few hours.

### Step 4: switch on HTTPS (Let's Encrypt)

As soon as the subdomain points at the server:

- **DirectAdmin:** **SSL Certificates** → choose `portal.yourbrand.com` → **Free & automatic
  certificate from Let's Encrypt** → tick the subdomain → **Save**. Then tick **Force SSL with https
  redirect** (in **Domain Setup** or on the same page).
- **cPanel:** **SSL/TLS Status** → select `portal.yourbrand.com` → **Run AutoSSL** (AutoSSL uses Let's
  Encrypt or Sectigo, whichever your host chose; both are free). Then **Domains** → **Force HTTPS
  Redirect** on for the subdomain.
- **Plesk:** **Websites & Domains** → the subdomain → **SSL/TLS Certificates** → **Install** (free basic
  certificate by Let's Encrypt) → **Get it free**. Then tick **Redirect from HTTP to HTTPS** in the
  subdomain's **Hosting Settings**.

The portal sends HSTS once it is reached over HTTPS.

### Step 5: open the installer

Open `https://portal.yourbrand.com` (it goes to the installer at `https://portal.yourbrand.com/install`
until the portal is installed). The page is in the language of your browser (25 languages; switch at the
top). It checks your server, then asks for:

- the **setup code**: open `data/SETUP-CODE.txt` in the file manager (or download it with your FTP
  program). The file appears once `data` is writable. This proves you are the one who uploaded the
  files; nobody else can claim a freshly uploaded portal.
- the web address of the portal (`https://portal.yourbrand.com`);
- the database: *Simple* (a file in the data folder, fine for most brands, nothing to create) or a
  MySQL/MariaDB database you first create in the panel (DirectAdmin: **MySQL Management**; cPanel:
  **Manage My Databases** / **MySQL Database Wizard**; Plesk: **Databases** → **Add Database**);
- optionally the sender address (and SMTP server) for invitations and "your download is ready" e-mails.

Then click *Open the setup wizard*; the setup code is already filled in. Enter your company, languages
and your own admin password. There is no default password.

### Step 6: background work and backups (cron jobs)

Add a cron job, every minute or every 5 minutes, with what the installer shows: `php /home/you/obx-portal/bin/obx cron`,
or the cron web address. DirectAdmin: **Cron Jobs**; cPanel: **Cron Jobs**; Plesk: **Scheduled Tasks**.
On hosts where PHP runs as the web server's user, the installer shows only the web address: a cron job
runs as your own account there, which may not read `config/config.php`. Without a cron job the portal
still works: it does the work right after the request that asked for it and catches up whenever someone
uses the portal.

Backups: add a second cron job, daily: `php /home/you/obx-portal/bin/obx backup /home/you/backups/portal-$(date +\%u).zip`
([operations.md](operations.md#backups)). Where the installer showed only the cron web address, that
command cannot read the portal's files either; use your host's own backup of the whole account (it
includes the `data` folder), or ask the host to run the command as the web server's user.

### Good to know

**No mod_rewrite?** Apache needs `mod_rewrite` to route the portal's pages; almost every host has it. Without it every address answers with a short page that says so. nginx-only hosts ignore `.htaccess`: use [nginx.conf](nginx.conf).

**Uploads.** `public/.user.ini` (PHP-FPM hosts) and `public/.htaccess` (Apache with mod_php) raise the upload limit to 512 MB and switch PHP's own form parsing off, so many images can be uploaded at once. Some hosts ignore both files: then the installer's check shows a lower limit; ask the host to raise `upload_max_filesize` and `post_max_size`, and upload many images as one zip.

**Upgrading:** upload the new release over the old folder (or extract it over it in the file manager); `config/` and `data/` stay. See [operations.md](operations.md#upgrading).

## Your own server with nginx or Apache

- **nginx + PHP-FPM:** [nginx.conf](nginx.conf) is a complete server block: document root on `public/`, everything else private, PHP settings for large uploads, and the cron line. Install the PHP extensions (`php8.3-sqlite3 php8.3-mysql php8.3-gd php8.3-zip php8.3-curl php8.3-mbstring` on Debian/Ubuntu), run `composer install --no-dev` in a clone or use the release zip, then open `/install`. For a VPS of your own, the [Portal for your own server](https://github.com/openbrandexchange/node-go) installs with one command and needs none of this.
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

## Docker (local development only)

The `Dockerfile` and `docker-compose.yml` in this repository are for development and the tests in CI,
not a production route. `docker compose up -d` gives a portal on <http://localhost:8080> (the setup
code: `docker compose logs portal | grep "Setup code"`), with MySQL instead of SQLite through
`--profile mysql`.

## Custom domain and the discovery address

Point the domain at your host ([Step 3: DNS](#step-3-dns)), switch on HTTPS in the hosting panel ([Step 4](#step-4-switch-on-https-lets-encrypt)), and set the public address (`public_url` in `config/config.php` or `OBX_PUBLIC_URL`) so e-mails link to it. Retailers' software finds the portal at `https://portal.yourbrand.com/.well-known/obx`, which works on every domain the portal answers on. You can list your portal in the public directory of Open Brand Exchange portals: see [openbrandexchange.com](https://openbrandexchange.com).
