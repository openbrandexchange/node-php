# Implementation notes (node-php)

Decisions taken where the spec (v0.1.0, since 0.2.0 v0.2.0, since 0.3.0 v0.3.0) was silent or ambiguous, and deliberate
extensions. Each is the simplest reading; the spec repository is the source of truth.

## Protocol and feed

1. **Upserts are filtered at read time.** Change entries store identity only; `data` is read when
   the feed is served, and an upsert whose object is no longer visible is left out
   (protocol.md §6.2). Conformance test I-REPLAY-2 reads the feed once after the whole batch and,
   since spec 42d627e, expects exactly that: `expected-changes.json` without the upserts of objects
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
6. **Default scopes of a new API client** are `catalog:read` and `media:read`, plus
   `media:original` when the retailer may download originals (node-admin.yaml); `usage:write` only
   when asked for. Token scopes are intersected with the grant on every call, so switching
   originals off applies at once.
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
    audit log, schema 3 indexes found with the load test, schema 4 (0.2.0) brings stored articles
    to the 0.2 model (note 48), schema 5 (0.3.0) materials and care to the 0.3 terms (note 62).
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
45. **PHP as another user than the FTP account** (Apache with mod_php; found in a fresh-eyes
    install on stock `php:8.2-apache`): files the portal writes belong to the web server's user.
    The installer detects it (`posix_geteuid()` differs from the owner of `src/`) and then writes
    `data/SETUP-CODE.txt` 0644 (else 0600) and `config/config.php` 0644 (else 0640), so the owner
    can open them over FTP; `data/` and `config/` are never served. A cron job runs as the FTP
    account there and could not write the database, so the installer shows only the cron web
    address, the admin repeats it under Settings (`settings.node.cron`), and `bin/obx` explains an
    unreadable `config.php` instead of printing a PHP warning. The installer's link to the wizard
    carries the code as `#code=` (a fragment never reaches the server) and the wizard fills it in.
    Without mod_rewrite every address answers the root `.htaccess`'s one-line explanation.

## Article data (0.2.0)

46. **Stored in the write model.** The article data of spec 0.2 is part of the product and
    variant write model (the `input` JSON), like the other fields; schema 4 adds no columns. The
    Validator writes the canonical 0.2 model: every field present (`null` when not stated),
    fibres normalised (`recycled_polyester` → `polyester` + `recycled: true`, flags only when
    true), features in registry order then the other keys sorted, measurements in schema order,
    an empty `features`, `measurements` or `certifications` and a `footwear_materials` without any
    part stored as `null`.
47. **Null and absent** follow obx-go's mock node: every new product and variant property (and
    `color.family`) is always present, `null` when not stated; inside `measurements` and
    `features` only stated members appear; `footwear_materials` always has its three parts;
    `licence_number` is always present (string or `null`).
48. **Upgrade of a 0.1 database (schema 4).** Every product and variant gets the 0.2 model and
    representation once, in batches of 500, on SQLite and MySQL alike. When the representation
    only gains the new properties as `null`, it is replaced without a change entry and keeps its
    `updated_at` (a consumer learns nothing new, and a full re-download of every catalog after an
    upgrade would be wasted); a visible article whose fibres used a 0.1 key gets an upsert entry,
    since its content changed. Re-sending the same data afterwards is `unchanged`.
49. **Import cells (import-format.md §1.6).** A cell of `contains_animal_origin`,
    `footwear_materials`, `certifications`, `feature:<key>`, `color_family`, `size_length` (more
    than 64 characters) or a measurement column that does not follow the rules is an error for
    that row, and the whole row is skipped (product part included), as in obx-go's importer.
    `item_subtype` follows `item_type` (a value that is no key is a warning and left empty), and an
    unparsable `materials` cell stays a warning (note 15). Variant cells on a row without a
    variant are ignored with a warning; a different `color_family` on a later size of the same
    colour is a warning and the first value wins. The 0.1 warning about percentages that do not add
    up to 100 is replaced by the label warning `fibre_percentages_not_100`, which, as in obx-go,
    applies to textile item types only.
50. **Label warnings (rule 10)** are computed from the product as stored after the row was applied
    (so also in a dry run, inside its rolled-back transaction) and reported once per product at
    its first row. The Ingest API adds them to `warnings` as `"<code>: <detail>"` and to
    `warning_details`, also for an `unchanged` result; batches and the admin catalog API return
    the same IngestResult.
51. **Validation in the Ingest API** (ingest.md §3.4) is the node's own, like the rest of the
    Validator: unknown members (also inside `footwear_materials`, `measurements` and
    certifications) are ignored with a warning, as for every other object; a registry feature
    outside its type or values, a list with duplicates, a measurement that is not a positive
    number, a strap whose `min` is above its `max`, a footwear material, colour family or
    certification scope outside its list, and a certification without `scheme` or `scope` are
    `422 validation_failed` with the pointer of the field (`/features/wheels`, `/measurements/weight_kg`).
52. **Portal CSV** (`data.csv`): the columns of import-format.md §1.2 in its order, including the
    status columns, with a `feature:<key>` column for every registry key and then the other keys
    the exported products have, and the measurement columns after `size_system`; booleans as
    `yes`/`no`, `strap_length_cm` as `min-max` (one number when both are equal), numbers with `.`.
    Importing the file again changes nothing.

## Excel import (import-format.md §5)

53. **Pure PHP zip reading for workbooks.** ext-zip is only suggested (packages need it), so a
    workbook is read by `XlsxPackage`: the end of central directory (zip64 too) and the central
    directory with `fopen`/`fseek`, entries inflated with `inflate_init`/`inflate_add` (zlib) in
    8 KiB steps into memory, counting the bytes actually inflated against the declared size and
    the 256 MiB total, and checking CRC-32. Nothing is written to disk. Only the parts §5.6 names
    are ever inflated.
54. **The order of §5.6 is the spec's**: size, signature, zip, declared sizes, unsafe entry names
    (before `[Content_Types].xml` is inflated, so a macro part next to a broken content-types
    part is `workbook_unsafe`), then the content types, structure, external relationships, sheet,
    cells in reading order (an unreadable cell and a cell outside the limits: whichever comes
    first), conversion. A part read twice (a sheet looked at for §5.2 and then converted) counts
    once toward the 256 MiB. Cells outside the limits are kept as coordinates only (at most
    100 000; more is certainly too large), so a sheet full of values below row 100 001 does not
    fill memory.
55. **XMLReader** (`LIBXML_NONET`, no `LIBXML_NOENT`/`DTDLOAD`) with a `DOCTYPE` node refused as
    soon as it is read; a libxml error is `workbook_invalid`. Self-closing elements are handled as
    open + close, so `<c r="A1"/>` and `<v/>` read like the reference's SAX events.
56. **The rows go to the CSV import directly** (`Csv::table`, shared with `Csv::read`), keyed by
    Excel row number, so every CSV rule (trimming, empty rows, repeated columns) is the same code
    and every row error already has Excel's number. `ImportEngine::issue` renames the file to
    `products.xlsx` / `assets.xlsx` and puts the §5.5 locator in front of the detail (the column is
    found by its header as written, then its normalised name, then the name before `:`).
57. **A workbook that cannot be imported fails the run** (§5.5), as an unreadable CSV does:
    `status` `failed`, `error` the Problem with the workbook code, nothing of the import written
    (also not the images or `assets.csv` sent with it), and exactly one ImportRowError (row and
    column null; what another file of the import gave before is dropped). That covers
    `payload_too_large` (a workbook in a package above the CSV limit; an upload above it is 413 at
    once) and a PHP without zlib/xmlreader. An empty row 1 and `products.csv` together with
    `products.xlsx` in one package or folder fail with `import_invalid`. A file staged directly
    (upload) wins over one in a package, as for CSV. The "only `*.csv`" fallback of §3 applies only
    when neither `products.csv` nor `products.xlsx` is there and never picks a workbook.
58. **Uploads**: the name decides (`.xlsx`, any case); a multipart part without a file name is kept
    as a file when its content type is the xlsx type (our own multipart parser; PHP's form parsing
    is off). `.xlsm`, `.xls`, `.xlsb`, `.xltx`, `.xltm`, `.ods`, `.numbers` (and their content types
    for nameless parts) are `415 unsupported_media_type`, as is any workbook on a PHP without zlib or
    xmlreader. A web address source whose path ends in `.xlsx` is a products workbook; an SFTP path
    that names an `.xlsx` file too. In folders only `products.xlsx` and `assets.xlsx` count.
59. **Bounds and text** (now in the spec): a number other than 0 outside 1E-308 to below 1E+308
    (after rounding to 15 digits) is `workbook_invalid`, checked from the decimal text (an exponent
    of more than nine digits never builds a string); a date cell's `<v>` is checked as a number
    first; a date after 9999-12-31 is a conversion error; CDATA is text. An empty header name
    (after trimming space, tab, CR, LF) is no column: no unknown-column warning, and the
    conversion warns once per such column with a value (`column C: `); a cell there is located as
    `row 3, column C: `. Header names (CSV and the sheet choice) count each run of white space and
    `-` as one `_`.

## Materials and care as terms (0.3.0)

60. **Names filled in on write.** The Validator stores materials and care in their 0.3 shape with
    the names, categories and symbols already filled in: for a code in `resources/vocabularies/`
    (a copy of the spec's `vocabularies/`, refreshed by `scripts/sync-spec.sh`) the vocabulary's
    values, also over a name that was sent; for another code the name (category, symbol) that was
    sent, or `null`. The representation is the stored model as it is, so the Ingest API, the
    admin API and the protocol return the same objects, and sending a record back (with or
    without names) is `unchanged`. A new vocabulary in a later release changes stored names only
    through a migration, like schema 5. Names keep the vocabulary's language order (en, nl, de,
    fr); names that were sent are sorted like every LocalizedText.
61. **Write paths are strict about 0.3.** `"part": "main"`, `"fiber"` and the flags are refused
    with `validation_failed` (the 0.2 shapes are not maintained); the 0.1 material keys
    `recycled_<material>` / `organic_<material>` are accepted everywhere and the key wins over a
    qualifier sent with it (ingest.md §3.4); with two prefixes (`organic_recycled_cotton`) the
    first is the qualifier. An empty string as a name is `validation_failed` like in every
    LocalizedText; a `null` language is left out. In an import, two qualifiers on one fibre are an
    error that skips the row (import-format.md §1.4); any other code in brackets is the
    qualifier, lowercased with `_`. The label warning `animal_origin_unknown` names the material
    code, `fibre_percentages_not_100` the part code.
62. **Upgrade of a 0.1 or 0.2 database (schema 5)** runs the upgrade of note 48 again, which now
    first turns 0.1/0.2 materials into 0.3 (`recycled: true` → qualifier `recycled`; with both
    flags true, `recycled` wins: 0.3 has one qualifier per fibre). Every visible article whose
    materials or care changed shape gets one upsert entry and a new `updated_at`, since its
    representation changed for consumers; variants and assets get none.

## Known limitations (tracked)

Each is a deliberate choice for 0.1.0 with its reason; none is reachable as a broken screen.

- **Renditions ignore EXIF orientation and ICC profiles** (GD has no colour management); originals
  are byte-exact and brands export sRGB in practice. Revisit when a host offers Imagick widely.
- **TIFF originals are refused** (GD cannot read TIFF); the installer and import errors say so.
- **Brand `logo_url` from the Ingest API is published as given**, not fetched and mirrored (a
  fetch would be one more SSRF surface for little gain; the admin upload path stores logos).
- **Request metrics** (rates, latencies) are not kept by PHP between requests; `/metrics` has
  gauges from the database, and request metrics come from the web server or proxy.
- **Hosts that ignore `.user.ini` and `.htaccess`** keep PHP's own upload parsing: several files
  under the same field name lose all but the last there; upload images as one zip.
- **`deploy/cloudrun.sh`** is checked with shellcheck (and its cron-key derivation against the
  PHP one) but was not run against a real Google Cloud project here (no account; the owner can run
  it). The Docker image it deploys is the tested one.
- **The 21 languages other than en, nl, de and fr** in the installer are machine-quality
  translations that follow the glossary; they need a native review, like the web interface's.
