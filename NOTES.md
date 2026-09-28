# Implementation notes (node-php)

Decisions taken where the spec (v0.1.0-draft) was silent or ambiguous, and deliberate
extensions. Each is the simplest reading; the spec repository is the source of truth.

## Protocol and feed

1. **Upserts are filtered at read time.** Change entries store identity only; `data` is read when
   the feed is served, and an upsert whose object is no longer visible is left out
   (protocol.md §6.2). Conformance test I-REPLAY-2 reads the feed once after the whole batch and,
   since spec 2053882, expects exactly that: `expected-changes.json` without the upserts of objects
   that are not visible after the last operation (every delete stays). Reading after each
   operation (I-REPLAY-1) returns exactly `expected-changes.json`. All nodes follow §6.2.
2. **Ordering within a brand cascade**: brand, products (by external id), variants (by external
   id), assets (by product external id, then colour with `null` first, then position).
3. **MySQL**: writers are serialised on a lock row (`SELECT … FOR UPDATE`) so feed sequence
   numbers become visible in order; SQLite serialises writers by itself (`BEGIN IMMEDIATE`).
4. **Signed rendition URLs** expire between one and two hours, aligned to the hour, so the same
   thumbnail URL is reused within an hour (browser caching). Every signed request re-checks that
   the client or account is still allowed (blocking takes effect immediately).
5. **Product `ETag`** is computed over the ProductDetail without rendition URLs, so it only changes
   when content changes.
6. **Default scopes of a new API client** are all scopes the retailer's grant allows, including
   `usage:write` (node-admin.yaml mentions catalog:read, media:read and media:original). Token
   scopes are intersected with the grant on every call, so switching originals off applies at once.
7. **Access-request status polls** are limited to 10 per minute per request token (the spec asks
   clients to poll at most once a minute).
8. **Brand `logo_url`** from the Ingest API is published as given (not fetched and mirrored).
9. **Rights expiry** is swept by the background worker (cron / worker / after admin requests); reads
   check `expires_at` themselves, so an expired asset is never served even before the sweep.

## Ingest and imports

10. **Renditions are rendered synchronously** on write when GD is available (`server_generated`
    true) and cached per original hash, so re-imports never render twice. Uploaded web/square/thumb
    files are ignored on such nodes (with a warning). Without GD the node reports
    `server_generated: false` and requires client renditions, validated for size and JPEG type.
    Renditions ignore EXIF orientation and embedded colour profiles (GD); originals are byte-exact.
11. **Accepted originals**: JPEG, PNG, WebP (checked by file content). TIFF is not accepted because
    GD cannot read it.
12. **Filename qualifiers** also recognise the two-part keys `size_chart`, `tilted_left` and
    `tilted_right` (`X_RED_1_tilted_left.jpg`).
13. **Images without a colour** (import-format.md, "Images without a colour"): the colour part `ALL`
    in a file name (any case) means every colour (`color_code: null`), and the external id is
    `{style}_ALL_{position}`; the same id is used for assets.csv rows with an empty `color_code`
    unless `asset_id` is given. Only assets.csv can address a colour whose code really is `ALL`.
14. **Materials** accept a decimal comma (`87,5% wool, 12,5% cashmere`).
15. **Product-level validation problems** in `products.csv` that make the product unusable (no name,
    empty brand column while the node has several brands, invalid care code) skip the product with its variants; field
    problems that can be corrected (bad country code, unparsable materials) are warnings and leave
    the field empty.
16. **Mirror imports** count the *published* products of the brands in the file; more than half
    missing → `mass_unpublish_refused`, the run fails and nothing is written.
17. **Dry runs** apply the import inside a transaction that is rolled back (exact counts, nothing
    written, no rendering).
18. **Scheduled imports** fingerprint the remote state (listing for SFTP/S3, content hash for a web
    address). An unchanged source is recorded as a succeeded run with zero counts. The SFTP host key
    is pinned on first use (`host_key_fingerprint`), and a changed key stops the import.
19. **Uploads**: PHP keeps only the last of several multipart parts with the same name and caps the
    number of files (`max_file_uploads`). The node therefore switches PHP's form parsing off
    (`enable_post_data_reading=Off` in `public/.user.ini`, `.htaccess`, the Docker image and
    `docs/nginx.conf`) and streams multipart bodies itself. Hosts that ignore these settings still
    work, but repeated `images` fields lose all but the last file; upload images as one zip there.

## Admin and portal

20. **Admin API beyond node-admin.yaml** (the web interface may use them; node-go and node-ts use
    the same paths and shapes):
    - `Settings.branding.portal_name` (now in node-admin.yaml): the brand's own portal word per
      language; default per language from the glossary.
    - `Settings.node.cron` = `{url, command, last_run_at}` for hosts without a cron job.
    - `Settings.retention` = `{download_events_days, audit_log_days, ip_days, access_requests_days}`
      (PATCH validates the ranges; docs/operations.md).
    - `GET /admin/api/audit-log` (shared entry shape, dotted action names, filters `action`,
      `actor_id`, `target_id`, `outcome`), `GET /admin/api/retailers/{id}/export`,
      `GET /admin/api/users/{id}/export`, `GET /admin/api/me/export`
      (`obx-personal-data-export/1`) and `DELETE /admin/api/me`.
    - `GET /admin/api/portal/products?sort=newest` (default: style number).
    The admin-ui does not show the audit log, retention settings or "download my data" yet; the
    API is there for it and for scripts.
21. **Facets are disjunctive**: each facet's counts apply all *other* filters, so choosing one brand
    still shows the other brands with their counts.
22. **EAN paste** splits `text` on every non-digit (as specified), so a code written with a space
    becomes two codes; the protocol lookup (`gtins` list) removes inner spaces instead.
23. **Download-job zips** get an ETag that identifies the file, not a content hash, so
    `OBX-Content-SHA256` is not sent for them.
24. **Rate limits**: login 30 per 15 minutes per IP and 10 per e-mail (offices share an IP); the
    other limits follow protocol.md §12 and node-admin.yaml.

## E-mails and languages

25. E-mails use the admin-ui's strings: keys `email.*` and `glossary.portal` in the locale files
    vendored with the web interface (`public/ui/locales/<lang>.json`, i18next style `{{name}}`),
    with built-in English copies (`src/Mail/Emails.php`) as the fallback per key. Templates:
    `invite`, `accessRequestReceived` (to the requester), `accessRequestNew` (to the brand's admins),
    `accessApproved` (+ `apiNote` when an API key was created), `accessRejected` (+ `reason`,
    `contact`), `zipReady`, `passwordReset`; shared `greeting`, `greetingNoName`, `signoff`,
    `footer`, `linkFallback`. `{{expires}}` is formatted in the recipient's language (intl) or as
    `YYYY-MM-DD HH:MM UTC`. The portal word comes from `branding.portal_name` (the brand's
    override, LocalizedText), else `glossary.portal`, else `resources/glossary.json` (a copy of
    admin-ui's glossary). The recipient's language is the account language, or for access requests
    `contact.language`, else the browser language (Accept-Language) at the time of the request.
26. LocalizedText fallback everywhere in the node: the requested language, its base language, then
    `en`, then the first available (`src/Support/Lang.php`).

## Hosting

27. **Setup code**: the web installer creates it (in `data/SETUP-CODE.txt`, readable over FTP only),
    asks for it once and stores it as the preset code, so the wizard asks for the same code. Docker
    prints it at start (`docker logs`); `OBX_SETUP_CODE` presets it.
28. **Cloud Run** runs without the in-container worker (`OBX_WORKER=0`); Cloud Scheduler calls
    `/cron?key=…` every minute. The cron key is HMAC-derived from `OBX_SECRET_KEY` (the deploy script
    derives it the same way with openssl). SQLite on Cloud Run is only for trying out.
29. **Root installs** (everything uploaded into `public_html`): the root `.htaccess` forwards to
    `public/` and denies the other folders; the request's base path then excludes `/public`.

## Integration with the web interface

30. **Vendored UI**: `public/ui` holds admin-ui's committed `dist/` (version in `public/ui/VERSION`),
    copied by `scripts/update-ui.sh` from git, so half-finished local admin-ui changes are never
    vendored. `scripts/ui-walk.sh` clicks through the main flows against a fresh Docker node.
31. **Approval e-mails**: approving a request that creates a portal invitation sends only the
    invitation (it carries the link to choose a password). The "access is ready" mail is sent when
    no invitation was created (existing account, API only) or when an API key was also created.

## Operations and security (0.1.0 hardening)

32. **CORS only on the discovery document.** `/.well-known/obx` (and its preflight) answers
    `Access-Control-Allow-Origin: *`; the protocol, ingest and admin APIs send no CORS headers and
    answer preflights with an error. The APIs are for servers, so a browser on another site can
    never read them with a retailer's token. Same rule in every node.
33. **Outbound requests (SSRF), same rule in every node:** only `https` (plus `sftp`), the host is
    resolved by the node and every address checked; link-local and cloud-metadata addresses
    (169.254/16, fe80::/10), multicast and unspecified addresses are always refused, also with
    `OBX_ALLOW_PRIVATE_URLS=true`, which only opens loopback, private ranges and plain http. The
    checked address is pinned for the connection (curl `CURLOPT_RESOLVE`; SFTP connects to the
    IP), every redirect is checked again. The operator's own blob store endpoint is exempt.
34. **Security headers on every response:** nosniff, `Referrer-Policy`, `X-Frame-Options: DENY`,
    a CSP with `frame-ancestors 'none'` (`default-src 'none'` outside the web interface), HSTS
    (two years, no `includeSubDomains`) when the request came over HTTPS.
35. **Request ids** (`X-Request-Id`, kept when a proxy sends 8–128 of `[A-Za-z0-9._-]`), in every
    log line, audit entry and internal-error message.
36. **Health:** `/healthz` answers from the front controller without configuration or database
    (liveness); `/readyz` checks the database at the current schema, the data folder and the blob
    store. `/metrics` (Prometheus, gauges from the database) only with `OBX_METRICS_TOKEN`.
37. **Configuration is validated** on every start (`Config::validate`): every problem is named with
    its environment variable and config.php key; the Docker entrypoint exits 78.
38. **Migrations** are forward-only and idempotent (SQLite `IF NOT EXISTS`, MySQL skips existing
    tables/indexes), run under a lock (SQLite `BEGIN IMMEDIATE`, MySQL `GET_LOCK`), and a database
    with a higher `schema_version` than the code is refused (`SchemaTooNew`). Schema 2 adds the
    audit log, schema 3 indexes found with the load test.
39. **Node state writes** (`obx_settings.state`) read the stored state inside the write
    transaction; the worker clears its caches every pass. Before this, a worker started before
    first-run setup wrote its old copy back and undid `setup_done`.
40. **Audit log** records every state-changing admin API request after it ran, personal-data
    exports and login attempts; never bodies or values, only field names. Unlike node-go it keeps
    the client address, for at most `retention.ip_days` (≤ 30, security.md §9), because shared
    hosting has no other place to see who signed in from where.
41. **Backups** (`php bin/obx backup`/`restore`) are logical (NDJSON per table + blobs + the secret
    key) so they move between SQLite and MySQL; the database snapshot is one read transaction.
42. **Client address behind proxies:** only when `OBX_TRUSTED_PROXIES` (list, `private`, `*`) or
    `OBX_TRUST_PROXY=true` (the other nodes' switch, = `*`) is set; then the right-most untrusted
    `X-Forwarded-For` hop. The access log holds no client addresses.
43. **Graceful stop** in Docker: the entrypoint turns SIGTERM into Apache's graceful stop and a
    pcntl-based worker stop after the current job. Interrupted jobs are resumed through their
    lease (at most 10 minutes later); every job kind is idempotent.
44. **Installer languages:** `resources/installer/<lang>.json` for all 25 languages, chosen from
    Accept-Language or `?lang=`, with the glossary's portal word and setup-code term; the badge
    text comes from the vendored web interface.

## Known limitations (tracked)

Each is a deliberate choice for 0.1.0 with its reason; none is reachable as a broken screen.

- **Renditions ignore EXIF orientation and ICC profiles** (GD has no colour management); originals
  are byte-exact and brands export sRGB in practice. Revisit when a host offers Imagick widely.
- **TIFF originals are refused** (GD cannot read TIFF); the installer and import errors say so.
- **Brand `logo_url` from the Ingest API is published as given**, not fetched and mirrored (a
  fetch would be one more SSRF surface for little gain; the admin upload path stores logos).
- **New API clients get every scope their grant allows**, including `usage:write`
  (node-admin.yaml names catalog:read, media:read and media:original as defaults); token scopes
  are intersected with the grant on every call.
- **Request metrics** (rates, latencies) are not kept by PHP between requests; `/metrics` has
  gauges from the database, and request metrics come from the web server or proxy.
- **Hosts that ignore `.user.ini` and `.htaccess`** keep PHP's own upload parsing: several files
  under the same field name lose all but the last there; upload images as one zip.
- **`deploy/cloudrun.sh`** is checked with shellcheck (and its cron-key derivation against the
  PHP one) but was not run against a real Google Cloud project here (no account; the owner can run
  it). The Docker image it deploys is the tested one.
- **The 21 languages other than en, nl, de and fr** in the installer are machine-quality
  translations that follow the glossary; they need a native review, like the web interface's.
