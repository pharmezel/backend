#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

PORT="${PORT:-10000}"

mkdir -p \
  storage/logs \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/app/public/drugs \
  storage/app/public/profiles \
  bootstrap/cache \
  resources/views

php artisan storage:link --force 2>/dev/null || ln -sf ../storage/app/public public/storage 2>/dev/null || true

chmod -R 775 storage bootstrap/cache || true
chown -R www-data:www-data storage bootstrap/cache || true

# Trim accidental spaces/quotes from Render UI paste
APP_KEY="$(printf '%s' "${APP_KEY:-}" | sed -e 's/^["'\'' ]*//' -e 's/["'\'' ]*$//')"
export APP_KEY

if [[ -z "${APP_KEY}" ]]; then
  echo "ERROR: APP_KEY is empty at container start." >&2
  echo "Render → Web Service → Environment → add APP_KEY, Save, then Manual Deploy." >&2
  exit 1
fi

# Drop dev-only cached manifests (e.g. Laravel Pail from local composer install)
rm -f bootstrap/cache/*.php

php artisan package:discover --ansi || true

if [[ "${RUN_MIGRATE_FRESH:-0}" == "1" ]]; then
  echo "RUN_MIGRATE_FRESH=1: dropping all tables and re-running migrations"
  php artisan migrate:fresh --force
elif [[ "${RUN_MIGRATIONS:-1}" == "1" ]]; then
  php artisan migrate --force
fi

if [[ "${RUN_SEEDERS:-0}" == "1" ]]; then
  php artisan db:seed --force
fi

php artisan config:cache || php artisan config:clear
php artisan route:cache || true

# Re-apply ownership after artisan writes to bootstrap/cache as root
chmod -R 775 storage bootstrap/cache || true
chown -R www-data:www-data storage bootstrap/cache || true

# Render requires binding to $PORT (not Apache default 80)
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
if grep -q '<VirtualHost \*:80>' /etc/apache2/sites-available/000-default.conf; then
  sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

echo "Starting Apache on port ${PORT}"
exec apache2-foreground
