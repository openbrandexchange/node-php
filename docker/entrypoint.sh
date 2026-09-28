#!/bin/sh
# Container start: listen on $PORT (Cloud Run), prepare the database, show the setup code,
# start the background worker (unless OBX_WORKER=0), then run Apache.
set -e
PORT="${PORT:-8080}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
# OBX_* environment variables reach PHP inside Apache through the process environment.
mkdir -p /var/www/html/data
chown -R www-data:www-data /var/www/html/data
su -s /bin/sh www-data -c "php /var/www/html/bin/obx migrate" || true
su -s /bin/sh www-data -c "php /var/www/html/bin/obx setup-code" || true
if [ "${OBX_WORKER:-1}" = "1" ]; then
  su -s /bin/sh www-data -c "while true; do php /var/www/html/bin/obx worker; sleep 5; done" &
fi
exec "$@"
