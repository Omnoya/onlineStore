FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        git \
        libcurl4-openssl-dev \
        libonig-dev \
        libsqlite3-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" \
        curl \
        dom \
        mbstring \
        pdo_mysql \
        pdo_sqlite \
        simplexml \
        xml \
        xmlwriter \
        zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . .

RUN mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install \
        --no-interaction \
        --prefer-dist \
        --no-progress \
        --optimize-autoloader

COPY docker/entrypoint.sh /usr/local/bin/online-store-entrypoint
RUN chmod +x /usr/local/bin/online-store-entrypoint

ENTRYPOINT ["online-store-entrypoint"]
CMD ["apache2-foreground"]
