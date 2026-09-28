# Test image: PHP 8.3 CLI with the extensions the node uses (gd, zip, pdo_mysql, pdo_sqlite).
# Used by scripts/test.sh; not for production.
FROM php:8.3-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libzip-dev unzip git \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" gd zip pdo_mysql \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/obx.ini
WORKDIR /app
