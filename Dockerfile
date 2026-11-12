# Open Brand Exchange · Portal for web hosting (node-php) as a container.
# Apache + PHP 8.3 with GD (server-side image versions), zip, SQLite and MySQL.
#   docker build -t obx-portal-php .
#   docker run -p 8080:8080 -v obx-data:/var/www/html/data obx-portal-php
# Multi-arch (linux/amd64, linux/arm64) images are built by .github/workflows/release.yml.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts \
      --ignore-platform-req=ext-gd --ignore-platform-req=ext-zip --ignore-platform-req=ext-pcntl

FROM php:8.5-apache
ARG VERSION=dev
LABEL org.opencontainers.image.title="Open Brand Exchange · Portal for web hosting" \
      org.opencontainers.image.description="Brand portal for retailers with the OBX Protocol and OBX Ingest API (PHP edition)" \
      org.opencontainers.image.source="https://github.com/openbrandexchange/node-php" \
      org.opencontainers.image.url="https://openbrandexchange.com" \
      org.opencontainers.image.licenses="AGPL-3.0-only" \
      org.opencontainers.image.vendor="Open Brand Exchange Foundation (in formation)" \
      org.opencontainers.image.version="${VERSION}"
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libzip-dev libicu-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" gd zip pdo_mysql opcache pcntl intl \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers \
 && a2disconf other-vhosts-access-log \
 && sed -ri 's#/var/www/html#/var/www/html/public#g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri '/CustomLog/d' /etc/apache2/sites-available/000-default.conf \
 && cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
COPY docker/apache.conf /etc/apache2/conf-available/obx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-obx.ini
RUN a2enconf obx
WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN rm -rf tests docker deploy .git .github scripts \
 && mkdir -p data config \
 && chown -R www-data:www-data data config
COPY docker/entrypoint.sh /usr/local/bin/obx-entrypoint
RUN chmod +x /usr/local/bin/obx-entrypoint
# Defaults for a single container with SQLite on the /var/www/html/data volume. JSON logs on
# stderr with one access line per request (Apache's own access log is off).
ENV PORT=8080 \
    OBX_DB_DSN=sqlite:/var/www/html/data/obx.sqlite \
    OBX_WORKER=1 \
    OBX_LOG_FORMAT=json \
    OBX_LOG_FILE=php://stderr \
    OBX_ACCESS_LOG=1 \
    OBX_APACHE_WORKERS=16
VOLUME ["/var/www/html/data"]
EXPOSE 8080
# The entrypoint turns SIGTERM into a graceful Apache stop plus a clean worker stop.
STOPSIGNAL SIGTERM
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS "http://127.0.0.1:${PORT}/healthz" >/dev/null || exit 1
ENTRYPOINT ["obx-entrypoint"]
CMD ["apache2-foreground"]
