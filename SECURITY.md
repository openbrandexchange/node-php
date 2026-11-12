# Security policy

## Reporting a vulnerability

Please report security problems privately to **security@openbrandexchange.com**. Do not open a public issue, pull request or discussion for them.

Include what you found, how to reproduce it (the smallest steps, requests or a proof of concept), which version or commit you tested (`php bin/obx version`, or the footer of the installer), and what an attacker could do with it. If you would like to be credited, say how.

What happens next:

| When | What |
|---|---|
| within 3 working days | we confirm we received your report |
| within 10 working days | we tell you whether we can reproduce it and how serious we think it is |
| within 90 days | a fix is released, or we agree a later date with you |

We will keep you informed, and we ask you not to disclose the problem until a fix is available (coordinated disclosure). We will not take legal action against research done in good faith that respects user privacy, does not degrade the service and stays within this policy. Test against your own installation (`docker compose up -d` gives you one in a minute), never against a portal that belongs to someone else.

## Supported versions

Security fixes go into the latest release. While the project is 0.x, only the newest minor version gets fixes; upgrade to it ([docs/operations.md](docs/operations.md#upgrading), release notes in [CHANGELOG.md](CHANGELOG.md)).

## Scope

This repository is the PHP edition of the Open Brand Exchange portal ("Portal for web hosting"): the OBX Protocol, the OBX Ingest API, the admin and retailer API behind the web interface, the web installer, the background worker, the release zip and the Docker image. In scope, for example: access to another retailer's content, bypassing sign-in, CSRF or rate limits, server-side request forgery through imports or webhooks, path traversal, unsafe archive handling, secrets in logs, and weaknesses in the defaults of the release zip or the image.

Problems in the web interface itself belong to [openbrandexchange/admin-ui](https://github.com/openbrandexchange/admin-ui) and problems in the protocol to [openbrandexchange/spec](https://github.com/openbrandexchange/spec), but you may report them to the same address and we will forward them.

How the portal protects data is described in [docs/security.md](docs/security.md).

This project is published by the Open Brand Exchange Foundation (in formation).
