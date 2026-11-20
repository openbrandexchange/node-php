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
    Renditions of a JPEG are turned upright by its EXIF orientation (read from the APP1 segment
    in pure PHP, no exif extension; since 0.3.0); embedded colour profiles are ignored (GD);
    originals are byte-exact.
11. **Accepted originals**: JPEG, PNG, WebP (checked by file content). TIFF is not accepted because
    GD cannot read it.
12. **Filename qualifiers** also recognise the two-part keys `size_chart`, `tilted_left` and
    `tilted_right` (`X_RED_1_tilted_left.jpg`).
13. **Images without a colour** (import-format.md, "Images without a colour"): the colour part `ALL`
    in a file name (any case) means every colour (`color_code: null`), and the external id is
    `{style}_ALL_{position}`; the same id is used for assets.csv rows with an empty `color_code`
    unless `asset_id` is given. Only assets.csv can address a colour whose code really is `ALL`.
14. **Materials** take a decimal point only (since 0.3.0, import-format.md §1.4): `87,5% wool`
    is `validation_failed` with a detail that starts with `materials: decimal comma`. Measurement
    cells keep accepting a comma.
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
28. **Cloud Run** is no longer a documented route (the deploy script was removed with the move to
    ordinary web hosting and the node-go installer). The cron key stays HMAC-derived from
    `OBX_SECRET_KEY`, so an external scheduler can call `/cron?key=…`.
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
    Validator: unknown members at any depth are ignored with a warning naming their JSON Pointer
    (`Unknown member "/materials/0/fibers/1/grade" ignored.`), never a 422; extension members
    (note 68) are kept; a registry feature
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
    `recycled_<material>` / `organic_<material>` are accepted everywhere and the key's qualifier
    comes first in `qualifiers`, before those sent with it (ingest.md §3.4); with two prefixes
    (`organic_recycled_cotton`) both are qualifiers, in the order written. The draft's single
    `"qualifier"` is read as `qualifiers: [it]` (`null` = none; ignored when `qualifiers` is there
    too) with one warning `qualifier_deprecated` per write in `warning_details`, pointer of the
    first such fibre (ingest.md §3.1, I-MAT-1); only `qualifiers` is stored, so the draft form of
    the stored list is `unchanged`. An empty
    string as a name is `validation_failed` like in every LocalizedText; a `null` language is
    left out. In an import every code in brackets is a qualifier, lowercased with `_`. The label warning `animal_origin_unknown` names the material
    code, `fibre_percentages_not_100` the part code.
62. **Upgrade of a 0.1 or 0.2 database (schema 5)** runs the upgrade of note 48 again, which now
    first turns 0.1/0.2 materials into 0.3 (`recycled: true` → qualifier `recycled`; with both
    flags true, both, `recycled` first). Every visible article whose
    materials or care changed shape gets one upsert entry and a new `updated_at`, since its
    representation changed for consumers; variants and assets get none.
63. **Cursors from before 0.3 expire (protocol.md §6.4) through a cursor epoch.** Every
    `/changes` cursor (live, snapshot, the export manifest's `changes_cursor`) is sealed with the
    epoch in `obx_settings` `cursor_epoch` (0 when the row is absent, as on every 0.1/0.2 node).
    `Feed::page` answers a cursor from another epoch with `410 cursor_expired` before reading it.
    Schema 5 sets the epoch to 1 (a fixed value, not an increment, so re-running a half-applied
    migration expires nothing more); a later incompatible upgrade sets 2. A separate settings row
    rather than a key in `state`, so the long-running worker never writes an old copy over it.
    The change entries of note 62 stay: they cost little and keep a consumer correct that kept
    its data and restarted from a snapshot.

64. **Several qualifiers per fibre (spec 5737aa2).** `qualifiers` is always a list (`[]` when
    none) in the stored model, so every path (Ingest API, admin, protocol, `/changes`, exports)
    returns it. Repeats are dropped keeping the first (with its name); a fibre whose `qualifiers`
    is `null` or left out has none. In an import, a bracket with a comma (`(organic, recycled)`) is
    `validation_failed` and skips the row, checked on the whole cell before fibres are split on
    commas (otherwise it would read as two broken fibres and only give the "unparsable" warning).
    The CSV export writes one bracket per code, in order, so it imports back unchanged.
65. **Upgrade of a draft-0.3 database (schema 6).** 0.3 was developed with one `qualifier` per
    fibre; a database that ran schema 5 of that draft stores it. Schema 6 adds the column
    `obx_products.qualifiers` and runs the upgrade of note 48 once more, which turns
    `"qualifier": {...}` into `"qualifiers": [{...}]` (`null` into `[]`) and fills the column. A
    visible article whose representation changed gets one upsert entry; the cursor epoch is not
    raised again (0.3 was never released with the draft shape, and the entries bring every
    consumer up to date). On a 0.1/0.2 database schemas 5 and 6 run in one go and the second
    pass changes nothing but the column.
66. **Portal filter and facet on qualifiers.** `obx_products.qualifiers` holds the product's
    qualifier codes as `|code|code|` (null when none), written with the product. The filter
    `qualifier` (repeated or comma-separated, in the query and in a download job's `filter`) is
    `(qualifiers LIKE '%|a|%' ESCAPE '!' OR …)`, with `_` escaped, the same SQL on SQLite, MySQL and
    MariaDB; a value that is not a code (`[a-z0-9_]`) matches nothing. A column rather than a
    join table keeps the write path, deletes and backups unchanged. The facet `qualifiers` is
    disjunctive like the others (note 21), in the vocabulary's order and then by code; `label` is
    the vocabulary's LocalizedText, for another code the name one of the viewer's products gave
    it, and absent when there is none.
67. **Running without Docker.** `composer dev` (`bin/dev`) runs the portal with PHP's built-in
    web server in `data/dev/` and sets it up in-process through the portal's own API (setup,
    upload key, Kestrel import from `tests/fixtures/spec/kestrel`, a retailer with a portal
    account), so the demo exercises the same code as a real first run. It never reads
    `config/config.php`, so it cannot touch a real install in the same folder; it is not in the
    release zip. `LOCAL=1 scripts/conformance.sh` runs the suite the same way, with
    `PHP_CLI_SERVER_WORKERS=4` (the built-in server otherwise answers one request at a time) and `bin/obx worker`.

## Before the release of 0.3.0 (spec e74ccb2)

68. **Extension members** (`x-<vendor>-<name>`, protocol.md §2, ingest.md §3.1) are stored in the
    write model of brands, products, variants, colours, assets, rights, provenance, the parts,
    fibres and terms of materials, care entries, certifications, measurements and footwear
    materials, sorted by name after the standard members, and returned as stored by the Ingest
    GET, the protocol, `/changes` and export manifests (Representation appends them). Up to
    64 KiB of serialised extensions per object (the spec asks for at least 8 KiB); more is
    `422 validation_failed`. An import keeps those of the object, its colour, and of the parts,
    fibres, care entries and certifications it states again with the same codes (by position and
    code for fibres); a different composition drops the extensions of fibres it replaced.
69. **Variant identity in imports** (import-format.md §1.2.1): `variant_id`, else the variant
    of this product with the row's GTIN (as GTIN-14), else
    `<style>-<color>-<size>[-<size_length>]` with control characters and `/` as `_`
    (`ImportEngine::defaultVariantId`). A GTIN held by a variant of another product is checked
    while planning, so the whole row is skipped (`gtin_conflict`, column `gtin`) before its
    product part is written; two rows on one variant: the later is `validation_failed`
    (column `variant_id`). Variants made by 0.2 (external id = GTIN) are found by step 2. As in
    obx-go (its NOTES 8): the same GTIN on two sizes of one file is `gtin_conflict` on `gtin` for
    the later row, and a product whose size rows are all refused is not written either (only
    rows without any size column write a product on their own).
70. **`percentage` may be `null`** (protocol.md §5.9.1): optional on write, always present in
    responses; `fibre_percentages_not_100` only when every fibre of the part states one and the
    sum is not 100, or when the stated ones exceed 100. The CSV export writes such a fibre as the
    code alone (`outer: leather`). `material_unknown` (every item type, once per code, after the
    label warnings) comes from the same `ArticleData::labelWarnings`, with the code as `value`
    in imports and the pointer of the first fibre in `warning_details`.
71. **Idempotency-Key** (1 to 200 characters, else `400 invalid_request`) compares the body:
    `POST /batch` the sha256 of the raw body, `POST /imports` the sha256 of every part (form
    fields and files, in order), `POST /exports` the sha256 of the canonical JSON body. Another
    body within 24 hours is `409 idempotency_key_reused`; keys stored before schema 7 have no
    hash and are replayed as before. The same export is returned also while it runs.
72. **Signed URLs and tokens:** a request with a Bearer token is authenticated by the token even
    when it carries a signature (protocol.md §5.5); a bad signature without a token is
    `403 forbidden`. Rotating a client secret deletes its access tokens, so they answer
    `401 invalid_token`; `access_revoked` stays for revoked clients and blocked retailers.
    `limit` above the maximum was already clamped. `scripts/conformance-operator.sh` is the
    suite's operator hook (both conformance modes pass it): `rotate_client_secret` for P-AUTH-2
    and `simulate_spec_upgrade` (raises the cursor epoch) for P-FEED-7; the admin API does the rest.
73. **Language tags** are language, optional script, optional region (`zh-Hant`, `es-419`,
    `sr-Latn-RS`) everywhere a tag is validated; `name:<lang>` columns are matched
    case-insensitively with `_` read as `-` and stored in canonical case
    (`Validator::canonicalLanguage`); another form is an unknown column.
74. **Portal appearance** (`Settings.branding.theme`, `login`, `home`, `favicon_url`; branding
    images): `theme`, `login` and `home` live in their own `obx_settings` row (`appearance`) and
    are decoded with objects kept as objects, so members and block types the node does not know
    come back byte for byte (also `{}` versus `[]`). PATCH applies a JSON merge patch to each of
    the three (`home.blocks` is a list, so it is replaced as a whole) and validates the result:
    known members only, unknown ones are never an error. Further choices: block ids are unique
    within `home.blocks`; a `tiles` block may leave out `items`, but a list has 1 to 12 entries;
    a `products` block may have both `style_numbers` and `query` (the interface uses
    `style_numbers`). An image counts
    as used (listed `used: true`, delete `409 conflict`) when it is `login.background_image` or
    the `image` of any block or block item, also of block types the node does not know; only the
    known places are checked for existence (`422`). Images are stored as blobs plus a row in
    `obx_branding_images`; their URLs (`/admin/api/public/images/{id}`, also `url` in the image
    list) and the favicon URL are absolute, like `logo_url`, so they work when the portal runs in
    a subfolder. Public branding adds `favicon_url`, `theme` and `login` with
    `background_image_url`; the portal viewer's `branding` also has `home`, where every block or
    item that has `image` also gets `image_url` (`null` when the image is gone). Favicons are
    PNG or WebP, square, at most 1 MB, sent as the raw body like the logo. The 201st image is
    `409 limit_exceeded`. This node has no demo mode, so images are always served
    `public, max-age=31536000, immutable`.

## Known limitations (tracked)

Each is a deliberate choice for 0.1.0 with its reason; none is reachable as a broken screen.

- **Renditions ignore ICC profiles** (GD has no colour management); originals are byte-exact and
  brands export sRGB in practice. Revisit when a host offers Imagick widely.
- **TIFF originals are refused** (GD cannot read TIFF); the installer and import errors say so.
- **Brand `logo_url` from the Ingest API is published as given**, not fetched and mirrored (a
  fetch would be one more SSRF surface for little gain; the admin upload path stores logos).
- **Request metrics** (rates, latencies) are not kept by PHP between requests; `/metrics` has
  gauges from the database, and request metrics come from the web server or proxy.
- **Hosts that ignore `.user.ini` and `.htaccess`** keep PHP's own upload parsing: several files
  under the same field name lose all but the last there; upload images as one zip.
- **The 21 languages other than en, nl, de and fr** in the installer are machine-quality
  translations that follow the glossary; they need a native review, like the web interface's.
