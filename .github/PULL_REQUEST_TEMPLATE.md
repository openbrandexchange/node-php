## What and why

<!-- One or two sentences; link the issue. -->

## How it was tested

- [ ] `./scripts/test.sh` (and `PHP=8.2`/`PHP=8.4` when the change could differ)
- [ ] `./scripts/test-mysql.sh mysql` when the change touches SQL
- [ ] `./scripts/test-hosting.sh` when the change touches the installer, the release zip, `.htaccess` or background work
- [ ] `./scripts/ui-walk.sh` when the change touches what the web interface calls
- [ ] PHPStan is clean

## Checklist

- [ ] Tests test behaviour (no assertions on source code)
- [ ] No new required PHP extension; works without cron
- [ ] Texts follow the glossary; new installer texts exist in all 25 languages
- [ ] Migrations only added, never changed
- [ ] CHANGELOG.md has a line under "Unreleased" if a brand, retailer or host notices this
- [ ] Commits are signed off (`git commit -s`, DCO)
