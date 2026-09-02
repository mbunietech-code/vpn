#!/usr/bin/env bash
# Run after Hostinger pulls a new commit (or as the deployment's post-deploy
# command if your plan supports one). Safe to re-run.
set -euo pipefail
cd "$(dirname "$0")"

echo "==> composer"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> migrate"
php artisan migrate --force

echo "==> caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache || true

echo "==> storage link"
php artisan storage:link || true

echo "deployed $(git rev-parse --short HEAD 2>/dev/null || echo '?')"
