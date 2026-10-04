#!/usr/bin/env sh
set -eu

cd /var/www/html

APP_PORT="${PORT:-10000}"
sed -i "s/^Listen .*/Listen ${APP_PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9][0-9]*>/<VirtualHost *:${APP_PORT}>/" /etc/apache2/sites-available/000-default.conf

# Render supplies the final public hostname at runtime. Keeping the SPA and API
# on this one origin means Sanctum can use secure HttpOnly session cookies.
if [ -n "${RENDER_EXTERNAL_URL:-}" ]; then
    export APP_URL="${APP_URL:-$RENDER_EXTERNAL_URL}"
    export FRONTEND_URLS="${FRONTEND_URLS:-$RENDER_EXTERNAL_URL}"
fi

if [ -n "${RENDER_EXTERNAL_HOSTNAME:-}" ]; then
    export SANCTUM_STATEFUL_DOMAINS="${SANCTUM_STATEFUL_DOMAINS:-$RENDER_EXTERNAL_HOSTNAME}"
fi

# Render's generated secret is stable but is not formatted as a Laravel key.
# Hashing it produces a stable 32-byte AES key without printing the secret.
case "${APP_KEY:-}" in
    base64:*) ;;
    '') echo "APP_KEY is required." >&2; exit 1 ;;
    *) export APP_KEY="base64:$(printf '%s' "$APP_KEY" | openssl dgst -sha256 -binary | base64 | tr -d '\n')" ;;
esac

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/public
chown -R www-data:www-data storage bootstrap/cache

php artisan package:discover --ansi
php artisan config:clear

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

php artisan storage:link >/dev/null 2>&1 || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "${RUN_QUEUE:-false}" = "true" ]; then
    php artisan queue:work --sleep=3 --tries=1 --timeout=120 &
fi

if [ "${RUN_SCHEDULER:-false}" = "true" ]; then
    (while true; do php artisan schedule:run --no-interaction; sleep 60; done) &
fi

exec apache2-foreground
