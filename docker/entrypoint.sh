#!/bin/sh
set -eu

# Renders app/config/config.local.php from env vars on every container
# start — same gitignored-override mechanism config.php already uses for
# local dev, just populated by Docker instead of by hand. Values are only
# ever written to this file, never baked into the image.
cat > /app/app/config/config.local.php <<PHP
<?php
return [
    'database' => [
        'host'     => '${DB_HOST}',
        'port'     => (int) '${DB_PORT:-5432}',
        'dbname'   => '${DB_NAME}',
        'username' => '${DB_USER}',
        'password' => '${DB_PASSWORD}',
    ],
];
PHP

# php.ini overrides that vary by target — APP_ENV=production (the default)
# is what Internal Prod and every External Client instance run; the dev
# droplet's local test stack sets APP_ENV=development to get errors on
# screen and skip opcode caching of edited files. Xdebug is deliberately
# never installed in this image at all, in either mode — see the
# production-plan checklist this reverses-into (VPS-Two-Droplet-Deploy-Plan.md).
if [ "${APP_ENV:-production}" = "production" ]; then
    {
        echo "display_errors = Off"
        echo "opcache.validate_timestamps = 0"
    } > /etc/php/8.3/fpm/conf.d/zz-app-env.ini
else
    {
        echo "display_errors = On"
        echo "opcache.validate_timestamps = 1"
    } > /etc/php/8.3/fpm/conf.d/zz-app-env.ini
fi

# .encryption_key (App_skeleton\Crypto) must be a bind-mounted host FILE,
# not a named volume — if nothing exists at the host path on first
# `docker compose up`, Docker creates a directory there instead of a file,
# which breaks Crypto's file_exists()/file_put_contents() silently. Create
# the host-side file before first run: touch ./data/encryption_key
if [ ! -f /app/.encryption_key ]; then
    echo "entrypoint: /app/.encryption_key doesn't exist as a file — check the host bind mount was created with 'touch', not left for Docker to create" >&2
fi

# Wait for Postgres — docker-compose's own healthcheck/depends_on handles
# ordering at first boot, but this covers Postgres restarting independently
# of the app container later.
i=0
until php -r "new PDO('pgsql:host=${DB_HOST};port=${DB_PORT:-5432};dbname=${DB_NAME}', '${DB_USER}', '${DB_PASSWORD}');" 2>/dev/null; do
    i=$((i + 1))
    if [ "$i" -ge 30 ]; then
        echo "entrypoint: database never became reachable after 30s, giving up" >&2
        exit 1
    fi
    sleep 1
done

# Idempotent — migrate run, seed run, modules sync (bin/install.php), safe
# to run on every container start whether the DB is fresh or already
# up to date.
php /app/bin/install.php

exec "$@"
