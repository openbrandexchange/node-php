# Contributing

Thank you for helping. This is the PHP edition of the Open Brand Exchange portal, the one brands install on ordinary web hosting, so a change here has to keep working on a plain PHP 8.2 host with nothing more than PDO SQLite, GD, curl, zip and mbstring. Small, focused pull requests are easiest to review.

## Before you start

- For anything bigger than a fix, open an issue first and describe the problem you want to solve.
- Security problems: never in a public issue, see [SECURITY.md](SECURITY.md).
- Changes to the API itself belong in the [spec](https://github.com/openbrandexchange/spec) repository first; this portal follows the spec. The web interface lives in [admin-ui](https://github.com/openbrandexchange/admin-ui) and is vendored into `public/ui` with `scripts/update-ui.sh`.
- Be kind: [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) applies everywhere in the project.

## Setting up

Everything runs in Docker; you do not need PHP on your computer.

```sh
docker compose up -d                 # a portal on http://localhost:8080
docker compose logs portal | grep "Setup code"
```

## What every change needs

```sh
./scripts/test.sh                    # PHPUnit on PHP 8.3 (PHP=8.2 or PHP=8.4 for the others)
./scripts/test-mysql.sh mysql        # the same on MySQL 8.4 (or: mariadb)
./scripts/test-sources.sh            # scheduled imports against real S3 and SFTP servers
./scripts/test-hosting.sh            # release zip + web installer on stock php-apache 8.2/8.3/8.4, no cron
./scripts/ui-walk.sh                 # the real web interface in a browser, axe on every screen, keyboard only
./scripts/conformance.sh ../obx-go   # the official conformance suite (needs a checkout of obx-go; DB=mysql|mariadb, RENDITIONS=client)
docker run --rm -v "$PWD":/app -w /app obx-node-php-test vendor/bin/phpstan analyse   # static analysis
./scripts/check-licenses.sh          # licences of production dependencies
```

CI runs all of these on every push ([.github/workflows/ci.yml](.github/workflows/ci.yml)). While the repositories are private, the conformance job needs a repository secret `OBX_READ_TOKEN` (a fine-grained token with read access to `openbrandexchange/obx-go`); without it the job is skipped with a notice.

- **Tests test behaviour.** HTTP tests drive the whole node through its router (`tests/Http`), unit tests cover pure logic (`tests/Unit`), the scripts above test real servers, real hosting and a real browser. Do not assert on source code.
- **Shared hosting first.** No new PHP extension may become required. Anything that needs one (GD, zip, curl, pcntl) must degrade and say so in the installer. Background work must also run on hosts without cron.
- **Words.** Follow the [glossary](https://github.com/openbrandexchange/spec/blob/main/docs/glossary.md): no protocol terms in anything a person reads (say *EAN*, *automatic upload*, *API key for your software*). E-mails use the admin-ui's texts; the installer's texts are in `resources/installer/<lang>.json`, and a new text needs all 25 languages (the tests fail on a missing text or placeholder).
- **Database.** Migrations are append-only (`src/Db/Schema.php`): add a new version, never change a released one. They must run on SQLite, MySQL 8 and MariaDB.
- **Security.** Read [docs/security.md](docs/security.md) before touching sign-in, URLs, uploads, imports or webhooks.
- **Changelog.** Add a line under "Unreleased" in [CHANGELOG.md](CHANGELOG.md) for anything a brand, a retailer or a host notices.

## Sign your commits (DCO)

Contributions are accepted under the [Developer Certificate of Origin 1.1](https://developercertificate.org/): by signing off you state that you wrote the change or have the right to submit it under the project's licence (AGPL-3.0-only). Add the sign-off with `git commit -s`, which appends

```
Signed-off-by: Your Name <you@example.com>
```

using your real name. No contributor licence agreement is needed.

## Licence

By contributing you agree that your contribution is licensed under the [GNU Affero General Public License v3.0 only](LICENSE).
