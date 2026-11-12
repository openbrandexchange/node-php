# Changelog

All notable changes to the PHP edition of the Open Brand Exchange portal ("Portal for web hosting"). The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [semantic versioning](https://semver.org/): while it is 0.x, a minor version may change behaviour, and the upgrade notes say what to do.

## [0.2.0] - 2026-11-12

Article data for fashion, shoes, bags and luggage: this release implements Open Brand Exchange specification 0.2.0 (OBX Protocol 0.2, [proposal 0002](https://github.com/openbrandexchange/spec/blob/main/docs/proposals/0002-product-data.md)) and passes the conformance suite of obx-go 0.2.0, including P-CAT-5, P-CAT-6, I-IMP-7 and I-IMP-8, on SQLite and MySQL, with server-made and with client-made image versions. Everything new is optional: retailers' software written for 0.1 keeps working.

### For brands

- **Article data** in the spreadsheet, the Ingest API and the admin: the kind of article (`item_subtype`, for example cabin size or ankle boot) and the article type "luggage", "contains non-textile parts of animal origin", the footwear materials of upper, lining and sock and outer sole, certifications (scheme, licence number, product or material), features (shell, wheels, lock, expandable, closure, laptop compartment, water resistance, detachable and adjustable parts, fit, sleeve length, neckline and your own), and per size the second size (jeans 30/32), the colour family and the measurements in cm, kg, litres and inch.
- New import columns `item_subtype`, `contains_animal_origin`, `footwear_materials`, `certifications`, `feature:<key>`, `color_family`, `size_length` and the measurement columns (`height_cm` … `inseam_cm`; a decimal comma is fine). A cell that does not follow the rules skips its row with an error that names the column. Like every other column, a feature or measurement column the file does not have leaves that value as it is, so a file with only `weight_kg` changes only the weights.
- Materials take `(recycled)` and `(organic)`: `outer: 100% polyester (recycled)`. The 0.1 spelling `recycled_polyester` / `organic_cotton` still works and means the same.
- **Label warnings**: imports and the Ingest API say when a product misses what its label needs by law (fibre composition, the animal-origin statement, footwear materials, percentages that do not add up to 100), with the legal basis. They never block publishing.
- The admin web interface admin-ui 0.2.0 (commit 2582973) shows and edits all of this; the import template has the column `contains_animal_origin`.

### For retailers

- The article page shows the label information, measurements and features, and the article data CSV has the columns of the import format, with a column per feature and per measurement (it can be imported again as it is).

### For retailers' software

- Products have `item_subtype`, `contains_animal_origin`, `footwear_materials`, `certifications` and `features`; variants `size_length`, `measurements` and `color.family`. Every one of them is always present, `null` when not stated; fibres carry `recycled` / `organic` (only when true) and never the 0.1 keys. `OBX-Version: 0.2`.
- IngestResult has `warning_details` (`code`, `pointer`, `detail`) for the label warnings.

### Upgrading

- Upload the new files (or pull the new image) and open the portal: database schema 4 brings the stored articles to the 0.2 form once. Articles whose fibres used a 0.1 key (`recycled_polyester`) get one change entry each; everything else keeps its `updated_at`, so retailers' software does not download the catalog again. Sending the same spreadsheet again afterwards changes nothing. Make a backup first (`php bin/obx backup`), as for every upgrade.

## [0.1.0] - 2026-11-12

The first release. It implements Open Brand Exchange specification 0.1.0 (OBX Protocol 0.1) and passes the official conformance suite: protocol, ingest, portal and security profiles, with server-made and with client-made image versions.

### For brands

- A portal for your retailers, "<Brand> · <portal word>" in 25 languages: access requests, browsing with filters, EAN lists, single images, a zip per article or selection, everything in a filter as a background zip, and article data as CSV. Every download is counted.
- The admin: brand profile and branding (logo, colour, welcome text, your own portal word), articles and images, imports with clear error lists, retailers, access requests, accounts, API keys for retailers' software, upload keys, webhooks and download statistics.
- Automation: the canonical spreadsheet plus images named `STYLE_COLOUR_1.jpg`, scheduled imports from a web address, an SFTP folder or an S3/R2 bucket (mirror mode refuses to take more than half of the catalog offline), the `obx` command-line tool and the OBX Ingest API. Sending the same data twice changes nothing.
- Images are stored byte for byte as uploaded; the web, square and thumbnail versions are made on the server (GD) or by the web interface and `obx`.
- Privacy tools: an audit log of admin actions and sign-ins, "download my data" and account deletion for every person, exports of a retailer's data, and retention settings for download statistics, the audit log, IP addresses and decided access requests. A privacy-notice template for brands is in `docs/privacy-notice-template.md`.

### For retailers' software

- The OBX Protocol: discovery document, OAuth2 client credentials, change feed with snapshot and live phase, products, EAN lookups (up to 2,000 codes), assets with signed rendition URLs (Range, ETag), bulk exports, usage events and signed webhooks.

### Hosting

- Shared web hosting: upload the release zip with FTP and open `/install`. Needs PHP 8.2–8.4 with PDO (SQLite or MySQL/MariaDB), OpenSSL and mbstring; GD, zip and curl are recommended. The installer speaks 25 languages. Without a cron job the portal does its background work after ordinary requests.
- Docker (`docker compose up -d`, SQLite or MySQL), Google Cloud Run (`deploy/cloudrun.sh`) and your own server with nginx (`docs/nginx.conf`). Multi-architecture images for linux/amd64 and linux/arm64.
- Operations: `/healthz`, `/readyz`, optional Prometheus `/metrics`, JSON logs with request ids, configuration checked at start (`php bin/obx check`), forward-only migrations that refuse a database from a newer release, `php bin/obx backup` / `restore` (also from SQLite to MySQL and back), graceful stop of the container.

### Security

- Passwords with argon2id, tokens stored as SHA-256 hashes, import credentials and webhook secrets encrypted with AES-256-GCM; session cookies with CSRF tokens; signed, expiring content links; outbound requests only to public addresses (link-local and cloud metadata addresses always refused, the checked address pinned for the connection); zip imports checked for unsafe paths and sizes; formula-safe CSV; strict Content-Security-Policy, `frame-ancestors 'none'`, HSTS over HTTPS; CORS only on the discovery document; rate limits on sign-in, setup, password reset, access requests and the token endpoint.

### Also in this release

#### Changed

- The web interface is served under admin-ui's strict Content-Security-Policy: no inline styles.
- The public logo no longer sends a CORS header; CORS is only on the discovery document, as in every edition.

#### Fixed

- A missing file under `/assets/` or `/locales/` is a 404, also when the browser accepts HTML, so a tab left open during an upgrade reloads once instead of reading the start page as code.
- `LICENSE` and `NOTICE` of the web interface are served as plain text.
- Hosts where PHP runs as the web server's user (Apache with mod_php): the FTP account can now open `data/SETUP-CODE.txt` and `config/config.php`, the installer offers only the cron web address (a command-line cron job could not write the database there), and `bin/obx` explains an unreadable `config.php` instead of printing a PHP warning.
- The installer no longer claims to have written a setup code while `data/` is not writable, and says how to fix the folder permissions (775, else 777).
- Without Apache's mod_rewrite, the portal answers with a one-line explanation instead of a bare 403.
- "Test connection" of a scheduled import no longer adds an English-only "N file(s) found." next to the web interface's own message.
- An import no longer takes away what it does not state about an image (spec import rule 8): the images alone, or with an assets.csv that has no row for them, keep their embargo, credit, alt text and AI label, also when those were set in the admin. Before, every such import reset them to the defaults, which lifted embargoes.
- Every zip a retailer downloads (one product, the selection, a background download) holds an `assets.csv` with one row per image: its usage rights, embargo and AI provenance in the import format's columns (spec import-format.md §2.3). Before, a zip held only the files, so a shop could publish an embargoed image without seeing the embargo.
- A column that a products.csv does not have leaves that field of an existing product or variant as it is (spec import rule 9, per language for texts): a file with only `style_number` and `name:nl` changes only the Dutch names. Before, it removed the descriptions, materials, care codes and the other languages. An empty cell in a column the file has still removes the value.
- Image names: `_model_other` is role model with view other, as the spec says (it was read as role other).
- A new API key without chosen scopes gets `catalog:read` and `media:read`, plus `media:original` when the retailer may download originals, as the spec says; it got every scope its grant allowed, `usage:write` included.
- Renewing an API key that was made when approving an access request retires the secret still waiting for the requester's software, so the status address no longer hands out a secret that stopped working ("Show the key" in the admin renews it).

#### Added

- The installer's *Open the setup wizard* link fills in the setup code (as `#code=`, never sent to the server).
- Web interface admin-ui 0.1.1 (commit d3ee574): the cron address under Settings, web version as the first download choice, download texts without an e-mail promise, a "Get started" step to set up e-mail while none is configured.

[0.2.0]: https://github.com/openbrandexchange/node-php/releases/tag/v0.2.0
[0.1.0]: https://github.com/openbrandexchange/node-php/releases/tag/v0.1.0
