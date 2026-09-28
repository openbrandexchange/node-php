#!/bin/sh
# Container start: check the configuration (stop with a clear message when it is wrong),
# prepare the database, show the setup code, start the background worker (unless
# OBX_WORKER=0), then run Apache. On `docker stop` (SIGTERM) Apache finishes running
# requests (graceful stop, at most 25 s) and the worker finishes its current job.
set -eu
PORT="${PORT:-8080}"
export OBX_APACHE_WORKERS="${OBX_APACHE_WORKERS:-16}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
mkdir -p /var/www/html/data
chown -R www-data:www-data /var/www/html/data
as_www() { setpriv --reuid=www-data --regid=www-data --init-groups "$@"; }

if ! as_www php /var/www/html/bin/obx check --config-only; then
  echo "The portal did not start: fix the settings above (environment variables OBX_*)." >&2
  exit 78
fi
# The database may still be starting (Compose with MySQL): retry for up to a minute.
i=0
until as_www php /var/www/html/bin/obx migrate; do
  i=$((i+1))
  if [ "$i" -ge 30 ]; then
    echo "The portal did not start: the database is not reachable (see the error above)." >&2
    exit 69
  fi
  sleep 2
done
as_www php /var/www/html/bin/obx setup-code || true

worker_pid=""
if [ "${OBX_WORKER:-1}" = "1" ]; then
  (
    trap 'kill -TERM "$child" 2>/dev/null; wait "$child"; exit 0' TERM INT
    while :; do
      as_www php /var/www/html/bin/obx worker --max-seconds=3600 &
      child=$!
      wait "$child" || sleep 5
    done
  ) &
  worker_pid=$!
fi

"$@" &
main_pid=$!
stopping=0
# shellcheck disable=SC2329 # invoked by the trap below
stop() {
  stopping=1
  kill -WINCH "$main_pid" 2>/dev/null || true
  [ -n "$worker_pid" ] && kill -TERM "$worker_pid" 2>/dev/null || true
}
trap stop TERM INT
status=0
wait "$main_pid" || status=$?
# Apache may have been interrupted by the trap: wait for it to really finish.
while kill -0 "$main_pid" 2>/dev/null; do wait "$main_pid" || status=$?; done
if [ -n "$worker_pid" ]; then
  kill -TERM "$worker_pid" 2>/dev/null || true
  wait "$worker_pid" 2>/dev/null || true
fi
# A requested stop is a clean exit.
[ "$stopping" = "1" ] && exit 0
exit "$status"
