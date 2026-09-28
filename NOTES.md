# Implementation notes (node-php)

Decisions taken where the spec (v0.1.0-draft) was silent or ambiguous, and deliberate
extensions. Each is the simplest reading; the spec repository is the source of truth.

## Protocol and feed

1. **Upserts are filtered at read time.** Change entries store identity only; `data` is read when
   the feed is served, and an upsert whose object is no longer visible is left out
   (protocol.md §6.2). Consequence for conformance test I-REPLAY-2: when a whole batch is read
   *after* it finished, the upsert entries of objects that the same batch deleted at the end are
   not returned (the delete entries are). Reading after each operation (I-REPLAY-1) returns
   exactly `expected-changes.json`. The spec should say which of the two I-REPLAY-2 expects.
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
13. **assets.csv rows with an empty `color_code`** (all colours) and a file name that does not follow
    the pattern get the external id `{style}_ALL_{position}` unless `asset_id` is given.
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

20. **Extensions of the non-normative admin API** (admin-ui may use them):
    - `Settings.branding.portal_name` and `PublicBranding.portal_name` (LocalizedText or null, as
      the admin-ui uses it): the brand's own word for the portal ("<Brand> · <word>"); default per
      language from the glossary (nl Beeldbank, de Händlerportal, fr Espace revendeurs, en Brand portal).
    - `Settings.node.cron` = `{url, command, last_run_at}` for hosts without a cron job.
    - `GET /admin/api/portal/products?sort=newest` (default: style number).
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
