# Open Brand Exchange node (PHP) — "Portal for web hosting" as a container.
# Apache + PHP 8.3 with GD (server-side image versions), zip, SQLite and MySQL.
#   docker build -t obx-node-php .
#   docker run -p 8080:8080 -v obx-data:/var/www/html/data obx-node-php
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts --ignore-platform-req=ext-gd --ignore-platform-req=ext-zip

FROM php:8.3-apache
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libzip-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" gd zip pdo_mysql opcache \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers \
 && sed -ri 's#/var/www/html#/var/www/html/public#g' /etc/apache2/sites-available/000-default.conf \
 && printf '<Directory /var/www/html/public>\n  AllowOverride All\n  Require all granted\n</Directory>\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/obx.conf \
 && a2enconf obx \
 && printf 'upload_max_filesize=512M\npost_max_size=520M\nmemory_limit=512M\nmax_execution_time=300\nexpose_php=Off\nopcache.validate_timestamps=0\nenable_post_data_reading=Off\n' > /usr/local/etc/php/conf.d/obx.ini
WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN rm -rf tests docker deploy .git .github \
 && mkdir -p data config \
 && chown -R www-data:www-data data config
COPY docker/entrypoint.sh /usr/local/bin/obx-entrypoint
RUN chmod +x /usr/local/bin/obx-entrypoint
# Defaults for a single container with SQLite on the /var/www/html/data volume.
ENV PORT=8080 \
    OBX_DB_DSN=sqlite:/var/www/html/data/obx.sqlite \
    OBX_WORKER=1
VOLUME ["/var/www/html/data"]
EXPOSE 8080
ENTRYPOINT ["obx-entrypoint"]
CMD ["apache2-foreground"]
