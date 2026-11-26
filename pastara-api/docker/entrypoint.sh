#!/bin/sh
set -e

cd /var/www/html

# Ensure writable directories for mounted Dokploy persistent volumes
mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

# Ensure public/storage symlink points to storage/app/public
if [ ! -L public/storage ]; then
    rm -rf public/storage
    php artisan storage:link --no-interaction
fi

# Refresh caches using runtime environment variables from Dokploy
php artisan config:clear --no-interaction
php artisan route:clear --no-interaction
php artisan view:clear --no-interaction

if [ "${APP_ENV:-production}" = "production" ] || [ "${APP_ENV:-}" = "staging" ]; then
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan view:cache --no-interaction
fi

# Optionally run migrations on container startup when explicitly enabled
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

exec "$@"
