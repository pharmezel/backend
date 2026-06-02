#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Ensure writable dirs (Render runs as root by default in Docker services)
mkdir -p storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache || true

# If APP_KEY isn't set, Laravel will error; fail early with a clear message
if [[ -z "${APP_KEY:-}" ]]; then
  echo "ERROR: APP_KEY is not set. Add APP_KEY in Render environment variables." >&2
  exit 1
fi

# Now that env is present, run composer/autoload scripts equivalent
php artisan package:discover --ansi || true

# Run DB migrations automatically on start (safe for staging; for prod keep it on unless you prefer manual)
if [[ "${RUN_MIGRATIONS:-1}" == "1" ]]; then
  php artisan migrate --force
fi

if [[ "${RUN_SEEDERS:-0}" == "1" ]]; then
  php artisan db:seed --force
fi

# Cache config/routes/views after env is present
php artisan config:cache || php artisan config:clear
php artisan route:cache || true
php artisan view:cache || true

exec apache2-foreground

