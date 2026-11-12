# Security

How the PHP edition protects brands, retailers and their data. It implements the normative [security requirements](https://github.com/openbrandexchange/spec/blob/main/docs/security.md) of the specification and passes the conformance suite's security profile (S-HDR, S-TOK, S-SESS, S-ISO, S-URL, S-SSRF, S-UPL, S-CSV, S-PATH, S-URLS). Found a problem? See [SECURITY.md](../SECURITY.md): report it privately to security@openbrandexchange.com.

## Isolation

- A retailer's people and API keys only ever see what the brand gave that retailer: lists, lookups, the change feed, exports, zips, selections, statistics and signed file links all apply the same visibility rule. Anything outside it answers `404`, never `403`, so its existence does not leak.
- Download jobs, selections, exports, webhook subscriptions, access-request status and self-service API keys belong to their owner; ids are never capabilities.
- Blocking a retailer or revoking a key takes effect on the next request; sessions of a blocked retailer end at once.

## Secrets and sign-in

- There is no default password. A freshly installed portal can only be claimed with the one-time setup code from `data/SETUP-CODE.txt` (FTP) or the log (Docker), or one the operator preset with `OBX_SETUP_CODE`.
- Passwords: argon2id (bcrypt where argon2 is missing), 10–72 characters, the 1000 most common refused. API client secrets, upload keys, request tokens, invitation and reset tokens are random (≥ 128 bits) and stored as SHA-256 hashes, shown once. Webhook secrets and import credentials are encrypted with AES-256-GCM under a key derived from `OBX_SECRET_KEY` (or `data/secret.key`); credentials are write-only in the admin.
- Recognisable prefixes for secret scanners: `obxi_` upload key, `obxc_` client id, `obxs_` client secret, `obxr_` request token, `whsec_` webhook secret.
- Sessions: `obx_session` cookie, `HttpOnly`, `SameSite=Lax`, `Secure` except on `http://localhost`, 30 days sliding, a new id at sign-in; ended at sign-out, password change, blocking and deletion. Every state-changing admin request needs the `X-CSRF-Token` bound to the session. Upload keys and API tokens are refused on the admin API, sessions on the protocol and ingest APIs.
- Links in e-mails always use the configured public address or the one recorded at setup, never the `Host` of the request, so a forged host cannot capture a password-reset token.
- Every comparison of secrets and signatures is constant-time.

## Files and links

- Image, export and zip links are signed (HMAC-SHA256 over method, path and expiry) and expire within one to two hours; a signed link cannot be moved to another file or version, and stops working when the image is unpublished or the retailer is blocked. Only the brand logo in the discovery document is public.
- Downloads set `Cache-Control: private`, `X-Content-Type-Options: nosniff` and a sanitised `Content-Disposition`.
- Uploads: size limits are enforced before buffering (`413`), declared SHA-256 checksums are verified, file types are recognised by their bytes (JPEG, PNG, WebP only; no SVG, also not as a logo), and images above 100 megapixels are refused before decoding.
- Zip imports refuse absolute paths, `..`, symlinks, drive letters and device names, cap the total size and number of entries, and never open nested archives.
- Excel workbooks (`.xlsx`) are read in memory, never unpacked to disk, under fixed limits checked from the zip directory before anything is inflated and again on the bytes actually inflated (1 000 parts, 256 MiB, 100 times the packed size above 10 MiB, 100 000 rows, 1 000 columns). Workbooks with macros, ActiveX, external links or data connections are refused before those parts are read; formulas are never calculated and nothing is fetched. XML is parsed with XMLReader without network access, and a `DOCTYPE` in any part refuses the file, so no entity other than the five XML ones is ever expanded.
- CSV exports prefix cells that start with `=`, `+`, `-`, `@`, a tab or a carriage return, except purely numeric ones such as EANs.
- The web interface's files are served only from `public/ui`; encoded `..` paths are refused, and on shared hosting the `.htaccess` files keep `config/`, `data/`, `src/`, `vendor/` and every hidden file private. The shared-hosting test checks that `config/config.php`, the SQLite file, the setup code and the secret key cannot be downloaded.

<a id="outbound-requests"></a>
## Outbound requests (SSRF)

Scheduled-import addresses, SFTP and S3 endpoints of imports, and webhook targets are chosen by users, so the portal:

- allows only `https` (plus `sftp` for SFTP imports);
- resolves the host name itself and refuses loopback, private (RFC 1918, RFC 4193), CGNAT, multicast, reserved and unspecified addresses, in every spelling (IPv4-mapped IPv6, `0.0.0.0`, decimal or short forms are never fetched);
- **always** refuses link-local addresses, including the cloud metadata address 169.254.169.254, even with the development switch `OBX_ALLOW_PRIVATE_URLS=true` (which only opens loopback, private networks and plain http, for local testing);
- **pins the checked address** for the connection (curl `CURLOPT_RESOLVE`; SFTP connects to the checked address), so a DNS answer that changes between the check and the connection (DNS rebinding) cannot redirect it;
- re-checks every redirect (at most 5; webhooks follow none), caps response sizes, and times out (15 s to connect, 10 minutes for an import, 10 s for a webhook).

This is the same rule in every OBX portal edition. The brand's own file storage (`OBX_S3_ENDPOINT`) is configured by the operator and is exempt.

## Headers

- Every response: `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: DENY` and a Content-Security-Policy with `frame-ancestors 'none'`; `Strict-Transport-Security: max-age=63072000` over HTTPS; `Cache-Control: no-store` unless the response is a cacheable file.
- The web interface: `default-src 'self'; script-src 'self'` (no inline or evaluated script), `frame-ancestors 'none'`, `form-action 'self'`, `object-src 'none'`.
- The installer: `default-src 'none'` with inline styles only.
- CORS: only the discovery document (`/.well-known/obx`) answers `Access-Control-Allow-Origin: *`. The APIs are for servers; browsers on other sites cannot read them with a retailer's token.
- PHP errors are never shown to visitors (`display_errors` off in `public/index.php`, `.user.ini`, `.htaccess` and the Docker image); `expose_php` and Apache's version banner are off.

## Abuse

Rate limits with `RateLimit-*` headers and `429` + `Retry-After` on sign-in (per address and per e-mail address), setup, password reset, invitations, access requests, the token endpoint, discovery and the APIs. Background jobs are bounded per owner and in time. Behind a reverse proxy set `OBX_TRUST_PROXY`/`OBX_TRUSTED_PROXIES`, or every visitor shares the proxy's limits ([operations.md](operations.md#configuration)).

## Logs, audit and privacy

- Secrets, tokens, `Authorization` headers, cookies, signed-link query strings and the cron key are scrubbed from logs and error messages. Logs hold no client addresses.
- The audit log records who changed what, never values or secrets; client addresses in it are removed after at most 30 days ([operations.md](operations.md#audit-log)).
- Personal data is minimal, exportable and deletable ([operations.md](operations.md#personal-data-gdpr)).

## Operator checklist

- Serve the portal over HTTPS only, and set `OBX_PUBLIC_URL` to the HTTPS address.
- Keep `OBX_SECRET_KEY` (or `data/secret.key`) and the backups secret; they unlock stored credentials and signed links.
- On shared hosting, prefer the document root on `public/`; never make `config/` or `data/` world-readable over the web (the `.htaccess` files do this for you on Apache; on nginx use [nginx.conf](nginx.conf)).
- Leave `OBX_ALLOW_PRIVATE_URLS` off in production.
- Keep PHP and the portal up to date (Dependabot opens update pull requests weekly; the release notes flag security fixes).
