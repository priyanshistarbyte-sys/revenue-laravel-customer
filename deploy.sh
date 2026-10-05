#!/usr/bin/env bash
#
# Server-side deploy steps for revenue_laravel.
# Run from the project root AFTER the new code is checked out
# (the GitHub Actions workflow does `git reset --hard origin/master` first).
#
# You can also run it by hand on the server:  bash deploy.sh
#
set -euo pipefail

echo "→ Installing PHP dependencies (production)…"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "→ Running database migrations…"
php artisan migrate --force

echo "→ Rebuilding framework caches…"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link || true

# If you run a queue worker (e.g. via supervisor), uncomment so it reloads code:
# php artisan queue:restart

echo "✓ Deploy complete"
