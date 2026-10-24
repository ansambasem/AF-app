#!/bin/bash
# Runs on the cPanel server after each pull of the cpanel-deploy branch (see .cpanel.yml).
# Copies the built app next to the repository and runs the Laravel deploy steps.
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
APP="$(dirname "$(dirname "$REPO")")/powercollect"

PHP=""
for candidate in /opt/cpanel/ea-php83/root/usr/bin/php /opt/alt/php83/usr/bin/php /usr/local/bin/php; do
    if [ -x "$candidate" ]; then PHP="$candidate"; break; fi
done
if [ -z "$PHP" ]; then echo "PHP 8.3 CLI not found"; exit 1; fi
echo "Deploying $REPO -> $APP with $PHP"

mkdir -p "$APP"
rsync -a --delete \
    --exclude=/.git --exclude=/.cpanel.yml --exclude=/.deploy \
    --exclude=/.env --exclude=/storage/ --exclude=/public/storage \
    "$REPO"/ "$APP"/
# storage/ keeps its uploads and logs; only add folders that are missing.
rsync -a --ignore-existing "$REPO"/storage/ "$APP"/storage/

# cPanel keeps document roots under public_html, so the subdomain's document root
# (public_html/powercollect/public) is a symlink to the app's public/ folder; the rest
# of the app (.env, vendor, storage) stays outside public_html.
WEBROOT="$(dirname "$(dirname "$REPO")")/public_html/powercollect/public"
if [ -d "$WEBROOT" ] && [ ! -L "$WEBROOT" ] && [ -z "$(ls -A "$WEBROOT")" ]; then
    rmdir "$WEBROOT"
fi
if [ ! -e "$WEBROOT" ]; then
    mkdir -p "$(dirname "$WEBROOT")"
    ln -s "$APP/public" "$WEBROOT"
fi

cd "$APP"
if [ ! -f .env ]; then
    echo "No .env in $APP yet: files copied, Laravel commands skipped."
    exit 0
fi

grep -q '^APP_KEY=base64:' .env || "$PHP" artisan key:generate --force
"$PHP" artisan migrate --force
if [ -f "$REPO/.deploy/seed-once" ] && [ ! -f storage/app/.seeded ]; then
    "$PHP" artisan db:seed --force
    touch storage/app/.seeded
fi
[ -e public/storage ] || "$PHP" artisan storage:link
"$PHP" artisan optimize
"$PHP" artisan queue:restart
echo "Deploy finished"
