# A typical shared-hosting PHP: Apache with mod_php and .htaccess, and only the extensions a
# budget host offers (PDO SQLite, GD, curl, zip, mbstring, openssl; no pdo_mysql, pcntl or intl).
# Used by scripts/test-hosting.sh; not for production.
ARG PHP=8.3
FROM php:${PHP}-apache
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libzip-dev unzip \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" gd zip \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite \
 && cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini \
 && printf 'error_reporting = E_ALL\nlog_errors = On\nerror_log = /dev/stderr\n' > /usr/local/etc/php/conf.d/zz-host.ini \
 && printf '<Directory /var/www/html>\n  AllowOverride All\n</Directory>\n' > /etc/apache2/conf-available/host.conf \
 && a2enconf host
ARG DOCROOT=/var/www/html
RUN sed -ri "s#DocumentRoot /var/www/html#DocumentRoot ${DOCROOT}#" /etc/apache2/sites-available/000-default.conf
