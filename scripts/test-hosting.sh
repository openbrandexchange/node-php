#!/usr/bin/env bash
# The shared-hosting path, end to end: the release zip is "uploaded" into a stock
# php:<version>-apache with only PDO SQLite, GD, curl, zip and mbstring, then
#   - the web installer (server check, setup code from data/SETUP-CODE.txt, SQLite),
#   - private folders are not reachable over the web,
#   - the setup wizard, an upload key, a Kestrel import, a background zip download and a due
#     scheduled import all finish WITHOUT a cron job (the portal works after its responses),
#   - the Apache/PHP log has no warnings, notices or deprecations.
# Two layouts: "root" (everything uploaded into public_html) and "public" (document root
# pointed at the public/ folder).
#   scripts/test-hosting.sh [php-version ...]        default: 8.2 8.3 8.4
# Needs Docker, curl, python3 and zip. Builds the release zip first unless ZIP=path is given.
set -euo pipefail
here=$(cd "$(dirname "$0")/.." && pwd)
versions=("$@")
[ ${#versions[@]} -eq 0 ] && versions=(8.2 8.3 8.4)
fixtures="$here/tests/fixtures/spec/kestrel"
work=$(mktemp -d)
names=()
cleanup() {
  for n in "${names[@]:-}"; do [ -n "$n" ] && docker rm -f "$n" >/dev/null 2>&1 || true; done
  rm -rf "$work"
}
trap cleanup EXIT

zip=${ZIP:-}
if [ -z "$zip" ]; then
  "$here/scripts/build-release.sh" >/dev/null
  zip="$here/build/obx-portal-php-$(sed -n "s/.*public const VERSION = '\([^']*\)'.*/\1/p" "$here/src/Config.php").zip"
fi
(cd "$work" && unzip -q "$zip")
(cd "$fixtures" && zip -q -r "$work/kestrel.zip" products.csv assets.csv images)
cp "$fixtures/products.csv" "$work/feed.csv"

json() { python3 -c "import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1], {'d': d}))" "$1"; }
fail() { echo "FAIL [$label]: $*" >&2; [ -n "${name:-}" ] && docker logs "$name" 2>&1 | tail -30 >&2; exit 1; }

port=18300
for v in "${versions[@]}"; do
  for layout in root public; do
    port=$((port + 1))
    label="php $v, $layout"
    name="obx-hosting-${v/./}-$layout"
    names+=("$name")
    docroot=/var/www/html
    [ "$layout" = public ] && docroot=/var/www/html/public
    echo "== $label"
    docker build -q -t "obx-hosting:$v-$layout" --build-arg PHP="$v" --build-arg DOCROOT="$docroot" \
      -f "$here/docker/hosting.Dockerfile" "$here/docker" >/dev/null
    docker rm -f "$name" >/dev/null 2>&1 || true
    docker run -d --name "$name" -p "$port:80" "obx-hosting:$v-$layout" >/dev/null
    # "FTP upload": the folder's contents into the web space, data/ and config/ writable.
    docker cp "$work/obx-portal/." "$name:/var/www/html/"
    docker exec "$name" sh -c 'mkdir -p /var/www/html/public/feed && chown -R www-data:www-data /var/www/html/data /var/www/html/config'
    docker cp "$work/feed.csv" "$name:/var/www/html/public/feed/products.csv"
    base="http://localhost:$port"
    for _ in $(seq 1 30); do curl -s -o /dev/null "$base/" && break; sleep 1; done
    jar="$work/jar-$name"

    page=$(curl -fsS "$base/install")
    grep -q 'Install your brand portal' <<<"$page" || fail "installer page not shown"
    curl -fsS -H 'Accept-Language: de-DE,de;q=0.9' "$base/install" | grep -q 'Installieren Sie Ihr Händlerportal' || fail "installer does not follow the browser language"
    curl -fsS "$base/install?lang=fr" | grep -q 'Installez votre espace revendeurs' || fail "installer language switch"

    grep -q 'class="bad"' <<<"$page" && fail "a required server check failed: $(grep -o '<li class="bad">[^<]*' <<<"$page")"
    for check in 'GD with JPEG' 'zip (zip uploads' 'curl (scheduled'; do
      grep -q "<li class=\"ok\">$check" <<<"$page" || fail "server check '$check' not ok"
    done
    code=$(docker exec "$name" cat /var/www/html/data/SETUP-CODE.txt | grep -oE '[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}')
    [ -n "$code" ] || fail "no setup code in data/SETUP-CODE.txt"
    curl -fsS -o /dev/null -w '%{http_code}' --data-urlencode "setup_code=wrong" --data-urlencode "public_url=$base" \
      --data-urlencode db=sqlite "$base/install" >/dev/null
    out=$(curl -fsS --data-urlencode "setup_code=$code" --data-urlencode "public_url=$base" --data-urlencode db=sqlite "$base/install")
    grep -q 'The portal is installed' <<<"$out" || fail "install did not finish: $(grep -o '<ul class="errors">.*</ul>' <<<"$out")"
    # Test only: let the scheduled import fetch from this same server over http.
    docker exec "$name" sh -c "sed -i \"s/'setup_code' =>/'allow_private_urls' => true,\n  'setup_code' =>/\" /var/www/html/config/config.php"

    for path in config/config.php data/obx.sqlite data/SETUP-CODE.txt data/secret.key vendor/autoload.php src/App.php composer.json .htaccess bin/obx; do
      status=$(curl -s -o /dev/null -w '%{http_code}' "$base/$path")
      # Uploaded into public_html, the folders sit inside the web space and must be refused;
      # with the document root on public/ they are outside it (the portal answers instead).
      if [ "$layout" = root ]; then
        case "$status" in 403|404) ;; *) fail "/$path answered $status (must be private)";; esac
      fi
      body=$(curl -s "$base/$path")
      if grep -qE '<\?php|secret_key|SQLite format|"require"|RewriteEngine|/usr/bin/env php' <<<"$body"; then
        fail "/$path leaked its content"
      fi
    done
    curl -fsS "$base/healthz" | grep -q '"ok"' || fail "/healthz"
    curl -fsS "$base/.well-known/obx" -o /dev/null -w '%{http_code}' | grep -q 200 && fail "discovery before setup should be 503" || true

    # Setup wizard over the admin API (what the web interface does).
    curl -fsS -c "$jar" -b "$jar" "$base/admin/api/session" >/dev/null
    csrf=$(awk '$6 == "obx_csrf" {print $7}' "$jar")
    curl -fsS -c "$jar" -b "$jar" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' "$base/admin/api/setup" \
      -d "{\"setup_code\":\"$code\",\"company\":{\"name\":\"Kestrel Outfitters\",\"first_brand_name\":\"Kestrel\",\"default_language\":\"nl\",\"languages\":[\"nl\",\"en\"]},\"admin\":{\"name\":\"Ada\",\"email\":\"admin@kestrel.example\",\"password\":\"correct horse battery\"}}" >/dev/null \
      || fail "setup wizard"
    csrf=$(awk '$6 == "obx_csrf" {print $7}' "$jar")
    admin=(-fsS -c "$jar" -b "$jar" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json')
    token=$(curl "${admin[@]}" "$base/admin/api/ingest-tokens" -d '{"name":"hosting test","scopes":["ingest:read","ingest:write","ingest:delete"]}' | json "d['secret']")

    # Import as a package: the job runs after the response, without cron.
    job=$(curl -fsS -H "Authorization: Bearer $token" -F "package=@$work/kestrel.zip;type=application/zip" "$base/obx/v1/ingest/imports" | json "d['id']")
    for _ in $(seq 1 90); do
      st=$(curl -fsS -H "Authorization: Bearer $token" "$base/obx/v1/ingest/jobs/$job" | json "d['status']")
      [ "$st" = succeeded ] && break; [ "$st" = failed ] && fail "import failed"; sleep 1
    done
    [ "$st" = succeeded ] || fail "import did not finish without cron (status $st)"
    products=$(curl "${admin[@]}" "$base/admin/api/catalog/products" | json "len(d['items'])")
    [ "$products" = 8 ] || fail "expected 8 products, got $products"

    # A background zip of everything (a download job), without cron.
    dj=$(curl "${admin[@]}" "$base/admin/api/portal/download-jobs" -d '{"source":"filter","filter":{},"variant":"web"}' | json "d['id']")
    for _ in $(seq 1 90); do
      st=$(curl "${admin[@]}" "$base/admin/api/portal/download-jobs/$dj" | json "d['status']")
      [ "$st" = succeeded ] && break; [ "$st" = failed ] && fail "download job failed"; sleep 1
    done
    [ "$st" = succeeded ] || fail "download job did not finish without cron (status $st)"
    curl "${admin[@]}" -o "$work/dl.zip" "$base/admin/api/portal/download-jobs/$dj/file"
    n=$(unzip -l "$work/dl.zip" | grep -c '\.jpg$' || true)
    [ "$n" -ge 20 ] || fail "download zip holds $n images"

    # A scheduled import that is due runs on the next ordinary request (once a minute).
    src=$(curl "${admin[@]}" "$base/admin/api/import-sources" \
      -d "{\"name\":\"Nightly\",\"kind\":\"https_url\",\"enabled\":true,\"schedule\":{\"frequency\":\"daily\",\"timezone\":\"UTC\"},\"mode\":\"upsert\",\"config\":{\"kind\":\"https_url\",\"url\":\"http://127.0.0.1/feed/products.csv\"}}" | json "d['id']")
    docker exec -u www-data "$name" php -r '
      $c = require "/var/www/html/config/config.php"; $db = new PDO($c["db_dsn"]);
      $db->exec("UPDATE obx_import_sources SET next_run_ts = 1");
      $s = json_decode($db->query("SELECT v FROM obx_settings WHERE k = '\''state'\''")->fetchColumn() ?: "{}", true);
      $s["tick_ts"] = 0; $s["housekeeping_ts"] = 0;
      $st = $db->prepare("UPDATE obx_settings SET v = ? WHERE k = '\''state'\''"); $st->execute([json_encode($s)]);'
    curl -fsS -o /dev/null "$base/.well-known/obx"
    for _ in $(seq 1 60); do
      st=$(curl "${admin[@]}" "$base/admin/api/import-sources/$src" | json "(d.get('last_run') or {}).get('status')")
      [ "$st" = succeeded ] && break; [ "$st" = failed ] && fail "scheduled import failed"; sleep 1
      curl -fsS -o /dev/null "$base/.well-known/obx"
    done
    [ "$st" = succeeded ] || fail "the due scheduled import did not run without cron (last run: $st)"

    # The portal pages and the API answer; the log is clean.
    curl -fsS -o /dev/null "$base/login" -H 'Accept: text/html' || fail "web interface"
    curl -fsS -o /dev/null "$base/readyz" || fail "/readyz"
    if docker logs "$name" 2>&1 | grep -E 'PHP (Warning|Notice|Deprecated|Fatal|Parse)|obx ERROR'; then
      fail "PHP reported problems (above)"
    fi
    echo "   ok: installer, private folders, setup, import, zip job and scheduled import without cron, clean log"
    docker rm -f "$name" >/dev/null
  done
done
echo "Shared-hosting path passed on PHP ${versions[*]}."
