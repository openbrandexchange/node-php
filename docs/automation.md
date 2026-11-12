# Automating publishing

Your PIM, ERP, DAM or a shared folder already has the article data and the photos; the portal should pick them up by itself. Everything here uses the open [OBX Ingest API](https://github.com/openbrandexchange/spec/blob/main/docs/ingest.md) and the [canonical import format](https://github.com/openbrandexchange/spec/blob/main/docs/import-format.md), so the same automation works against every Open Brand Exchange portal. The complete recipes (cron, Windows Task Scheduler, GitHub Action, n8n, Make, Zapier, direct API) are in the specification's [automation guide](https://github.com/openbrandexchange/spec/blob/main/docs/automation.md); this page covers what is particular to this edition.

## An upload key

In the admin, **Automatic upload → Access keys → New key**. The key starts with `obxi_` and is shown once; store it as a secret. Give every system its own key (so you can revoke one without breaking the others) and restrict it to the brands it publishes. Every key can be rotated and revoked; the audit log records who did it ([operations.md](operations.md#audit-log)).

## Pick a way

| You have… | Use | Where |
|---|---|---|
| a CSV or zip export on a web address, an SFTP server or an S3/R2/MinIO/Backblaze bucket | a **scheduled import**, no code | Admin → Automatic upload → Scheduled imports → Add |
| a folder where exports land | `obx watch /path/to/outbox` | [obx](https://github.com/openbrandexchange/obx-go) as a service |
| a server or PC with nightly jobs | `obx push ./export` from cron or Task Scheduler | one line |
| exports in a Git repository | the `obx` GitHub Action | a YAML file |
| n8n, Make, Zapier or your own code | HTTP calls: `PUT /obx/v1/ingest/products/{style}` and friends, `POST /obx/v1/ingest/imports` | [ingest.md](https://github.com/openbrandexchange/spec/blob/main/docs/ingest.md) |

Every call is safe to repeat: sending the same data again changes nothing and causes no updates at retailers.

## The file format in short

`products.csv` with one row per EAN (style number, colour code and name, size, EAN, name, description, materials, care, …; since 0.2 also `item_subtype`, `contains_animal_origin`, `footwear_materials`, `certifications`, `feature:<key>`, `color_family`, `size_length` and the measurement columns such as `weight_kg`, see the specification's [article data columns](https://github.com/openbrandexchange/spec/blob/main/docs/import-format.md#article-data)), an optional `assets.csv`, and images named `STYLE_COLOUR_POSITION.jpg` (for example `KO-1001_NVY_1.jpg`; `_front`, `_back`, `_detail`, `_model` and more after the position set the view). Images without a colour, which apply to every colour of an article, get the id `{style}_ALL_{position}`. The admin offers a filled-in template under **Imports**. JPEG, PNG and WebP are accepted; TIFF is not (GD cannot read it).

## What this edition does

- **Image versions.** With GD the portal makes the web (≤ 2000 px), square and thumbnail versions itself, once per original; uploaded versions are then ignored. Without GD (`OBX_RENDITIONS=client`, or a host without GD) `obx` and the web interface make them and send them along.
- **Scheduled imports** run at their time from the background work ([operations.md](operations.md#background-work)); on a host without cron, the first request after the time starts them. An unchanged source (same listing or same content) is recorded as a run with nothing to do. SFTP server keys are pinned on first use; a changed key stops the import until you clear it. Addresses are checked like every outbound request: only `https` (and `sftp`), never private, loopback, link-local or cloud-metadata addresses ([security.md](security.md#outbound-requests)).
- **Mirror mode** also takes articles offline that disappeared from the file, but refuses (and changes nothing) when more than half of the published articles of the brands in the file would disappear, so a broken export never empties the portal.
- **Dry runs** apply the import inside a transaction that is rolled back: exact counts, nothing written.
- **Limits** are announced in `GET /obx/v1/ingest` (`max_upload_bytes`, part size for resumable uploads, batch sizes). Large image sets go through resumable multipart uploads or an images manifest, which is what `obx push` does.
- **Uploads on shared hosting.** The portal reads multipart uploads itself, so many images in one request work even with PHP's `max_file_uploads`. On hosts that ignore `public/.user.ini` and `.htaccess`, send many images as one zip.

## For retailers: `obx pull`

Retailers' software reads the portal with an API key (Admin → Retailers → a retailer → API key for their software, or self-service when you switch it on). `obx pull https://brands.yourbrand.com --out ./catalog` mirrors the catalog and, by default, the **web** version of every image; `--renditions original,web,square,thumb` picks others (originals only when you allow them), and `--follow` keeps the copy up to date from the change feed.
