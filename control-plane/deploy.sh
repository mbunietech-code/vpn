#!/usr/bin/env bash
# Run after Hostinger pulls a new commit (or as the deployment's post-deploy
# command if your plan supports one). Safe to re-run.
set -euo pipefail
cd "$(dirname "$0")"

# Laravel 13 needs PHP 8.4+. Hostinger's default CLI `php` can be older than
# the site's web PHP, so pick a new enough binary (override with PHP_BIN).
PHP="${PHP_BIN:-php}"
if ! "$PHP" -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'; then
  for alt in /opt/alt/php84/usr/bin/php /opt/alt/php85/usr/bin/php; do
    [ -x "$alt" ] && PHP="$alt" && break
  done
fi
"$PHP" -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' || { echo "need PHP >= 8.4 (set PHP_BIN)" >&2; exit 1; }
echo "==> using $("$PHP" -r 'echo PHP_BINARY, " ", PHP_VERSION;')"

echo "==> composer"
"$PHP" "$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction

echo "==> migrate"
"$PHP" artisan migrate --force

echo "==> sync setting rows (idempotent)"
"$PHP" artisan db:seed --class=SettingsSeeder --force

echo "==> caches"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan event:cache || true

echo "==> storage link"
"$PHP" artisan storage:link 2>/dev/null || {
  # Hostinger disables exec()/symlink() from PHP — do it in the shell.
  [ -e public/storage ] || ln -sfn ../storage/app/public public/storage
}
mkdir -p storage/app/public/qr storage/app/private/proofs

echo "deployed $(git rev-parse --short HEAD 2>/dev/null || echo '?')"
