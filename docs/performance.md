# Performance

`scripts/load-test.sh` seeds a node in the production Docker image with a catalog far larger than most brands have, writes it through the node's own write path (validation, visibility, change feed), and times the read paths from a second container over HTTP, one request at a time. CI runs it weekly and on demand (the `load` job).

- **Catalog:** 2 brands, 10,000 articles, 60,000 EANs (2 colours × 3 sizes each, valid check digits), 40,000 images (2 per colour; metadata plus one shared JPEG, so the image versions are made once), 110,002 change-feed entries.
- **Machine:** Apple M2 Pro, Docker Desktop with 10 CPUs and 8 GB; the container with its defaults (PHP 8.3, opcache, 16 Apache workers). Other work was running on the machine, so treat single numbers as ±30 %.
- **Run it yourself:** `./scripts/load-test.sh` (SQLite), `DB=mysql ./scripts/load-test.sh` (MySQL 8.4), `KEEP=1` to leave the node running for `EXPLAIN`.

## Results (0.1.0, 2026-09-28)

Milliseconds per request, measured at the client.

| Request | SQLite p50 | SQLite p95 | MySQL 8.4 p50 | MySQL 8.4 p95 |
|---|---:|---:|---:|---:|
| `GET /obx/v1/changes?limit=500`, the whole snapshot (222 pages) | 57 | 111 | 15 | 34 |
| `GET /obx/v1/changes?limit=500`, the same history as live pages (221 pages) | 13 | 18 | 27 | 37 |
| `GET /obx/v1/products?limit=100` (40 pages, keyset cursor) | 32 | 41 | 42 | 52 |
| `GET /obx/v1/products:by-gtin` (1 EAN) | 2.8 | 4.1 | 3.9 | 5.8 |
| `POST /obx/v1/products:by-gtin` (100 EANs, full product documents) | 68 | 75 | 54 | 65 |
| `GET /obx/v1/products/{id}` | 3.4 | 4.1 | 3.9 | 4.9 |
| Portal list, first page (48 articles) | 22 | 28 | 11 | 60 |
| Portal list, page 100 | 51 | 55 | 44 | 63 |
| Portal search `q=sweater` | 26 | 31 | 17 | 34 |
| Portal list with type + season filter | 21 | 27 | 13 | 26 |
| Portal facets (brand, type, colour, season, collection, target group) | 71 | 78 | 52 | 77 |
| Admin catalog list (50 articles) | 29 | 32 | 47 | 131 |
| Portal EAN list (50 EANs) | 11 | 14 | 35 | 49 |

A retailer's software reads the complete catalog (110,002 change entries) in 16 s on SQLite and 5 s on MySQL, and then stays current with small live pages. Seeding the catalog took 31 s on SQLite and 297 s on MySQL (every write is a transaction with the change feed; real imports run in the background).

## What the load test changed

- **Live change feed:** a page loaded every object with its own query; it now loads them in four queries per page.
- **Indexes (schema 3):** colour facet and filter, newest-first sort, the GDPR lookups (downloads and audit entries by person or retailer), import runs by source and job. No index starts with `visible`: with only two values it made SQLite read the live feed and EAN lists through it instead of the primary key (66 → 13 ms per live page, 54 → 11 ms for 50 EANs).
- **Facets:** one grouped query per facet took 250 ms on MySQL; the counts are now made in one pass over the matching articles and their colours (52 ms). A test pins the rule that every count equals what the list shows with that value chosen.
- **SQLite statistics:** the hourly housekeeping runs `PRAGMA optimize`.

## Limits worth knowing

- Deep portal pages use an offset (page 100 ≈ 50 ms); retailers normally search or filter instead. The protocol's product list and change feed use keyset cursors and stay flat.
- Server-side image versions are the slowest part of an import (GD decodes and resizes every new original once; unchanged images cost nothing); the `obx` tool can make them on the brand's computer instead (`OBX_RENDITIONS=client`).
- The numbers are single requests. On shared hosting the host's PHP workers and disk decide how many run at once; in Docker `OBX_APACHE_WORKERS` does ([operations.md](operations.md#limits-and-sizing)).
