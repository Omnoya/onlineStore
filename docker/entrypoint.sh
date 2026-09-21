#!/bin/sh
set -eu

cd /var/www/html

mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

if [ ! -L public/storage ]; then
    if [ -e public/storage ]; then
        echo 'public/storage exists but is not a symbolic link.' >&2
        exit 1
    fi

    ln -s /var/www/html/storage/app/public public/storage
fi

exec "$@"
