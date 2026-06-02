#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

PORT="${PORT:-10000}"

# Render sets RENDER_EXTERNAL_URL; default APP_URL when missing so asset URLs are correct.
if [[ -z "${APP_URL:-}" ]] && [[ -n "${RENDER_EXTERNAL_URL:-}" ]]; then
  export APP_URL="${RENDER_EXTERNAL_URL}"
  echo "APP_URL set from RENDER_EXTERNAL_URL: ${APP_URL}"
fi

# Optional Render persistent disk — set PUBLIC_DISK_ROOT to the disk mount path in Render env.
PUBLIC_ROOT="${PUBLIC_DISK_ROOT:-storage/app/public}"

ensure_upload_storage() {
  mkdir -p \
    storage/logs \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    "${PUBLIC_ROOT}/drugs" \
    "${PUBLIC_ROOT}/profiles" \
    bootstrap/cache \
    resources/views

  chmod -R 775 storage bootstrap/cache || true
  chown -R www-data:www-data storage bootstrap/cache || true

  if [[ "${PUBLIC_ROOT}" != "storage/app/public" ]]; then
    chmod -R 775 "${PUBLIC_ROOT}" || true
    chown -R www-data:www-data "${PUBLIC_ROOT}" || true
  fi

  # If group perms are not enough, widen upload dirs only (common on PaaS).
  if ! su -s /bin/sh www-data -c "test -w '${PUBLIC_ROOT}/drugs'"; then
    echo "WARN: widening permissions on upload directories" >&2
    chmod -R 777 "${PUBLIC_ROOT}/drugs" "${PUBLIC_ROOT}/profiles" || true
  fi

  if ! su -s /bin/sh www-data -c "touch '${PUBLIC_ROOT}/drugs/.write_test' && rm -f '${PUBLIC_ROOT}/drugs/.write_test'"; then
    echo "ERROR: www-data cannot write to ${PUBLIC_ROOT}/drugs — image uploads will fail." >&2
    ls -la "${PUBLIC_ROOT}" >&2 || true
    exit 1
  fi

  echo "Upload storage OK: ${PUBLIC_ROOT}/drugs (writable by www-data)"
}

ensure_upload_storage

php artisan storage:link --force 2>/dev/null || true
# Prefer Laravel /storage route over Apache symlink (symlink 404s skip index.php on Docker).
rm -f public/storage 2>/dev/null || true

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

# Artisan may recreate dirs/files as root — re-apply upload permissions before serving.
ensure_upload_storage

# Render requires binding to $PORT (not Apache default 80)
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
if grep -q '<VirtualHost \*:80>' /etc/apache2/sites-available/000-default.conf; then
  sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

echo "Starting Apache on port ${PORT}"
exec apache2-foreground
