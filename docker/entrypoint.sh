#!/bin/sh
set -eu

# The bind-mounted host directories (logs/, public/files/, sessions/,
# storage/ticket-attachments/) can arrive owned by whoever created them on
# the host — root, if a deploy script does `mkdir -p data` over SSH as
# root, which is exactly what happened on the first prod deploy
# (2026-07-30) and silently broke error logging: www-data (php-fpm's run
# user, uid 33) had no write access, so error_log() failed with no visible
# symptom beyond a generic crash page. This container runs as root (no
# USER directive in the Dockerfile — PHP-FPM itself drops to www-data per
# the pool config), so fixing ownership here on every start means it can
# never again depend on whoever ran `mkdir` on the host getting it right.
# storage/ticket-attachments was added after this line was first written
# (REQ-025) and missed the same treatment — confirmed on prod as the
# silent cause of ticket-attachment uploads failing (mkdir(): Permission
# denied, moveTo() failing, both non-fatal warnings the app didn't check).
mkdir -p /app/storage/ticket-attachments
chown -R www-data:www-data /app/logs /app/public/files /app/sessions /app/storage

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
    DISPLAY_ERRORS="Off"
    OPCACHE_REVALIDATE="0"
else
    DISPLAY_ERRORS="On"
    OPCACHE_REVALIDATE="1"
fi

{
    echo "display_errors = ${DISPLAY_ERRORS}"
    echo "opcache.validate_timestamps = ${OPCACHE_REVALIDATE}"
    # Only set sendmail_path if a real relay is actually configured (see
    # msmtp setup below) — leaving it unset preserves the existing
    # no-MTA-configured behavior (mail() fails and logs, doesn't throw) for
    # any environment that hasn't set SMTP_HOST/SMTP_PASSWORD, e.g. local
    # dev running this compose file without real mail credentials.
    if [ -n "${SMTP_HOST:-}" ] && [ -n "${SMTP_PASSWORD:-}" ]; then
        echo 'sendmail_path = "/usr/bin/msmtp -t"'
    fi
} > /etc/php/8.3/fpm/conf.d/zz-app-env.ini

# msmtp relay config — only rendered if SMTP_HOST/SMTP_PASSWORD are set.
# msmtp refuses to run at all if its config file is group/world-readable
# (it holds a plaintext password), hence the chmod 600 — and it needs to
# be readable by www-data specifically, since that's who php-fpm (and
# therefore mail()) actually runs as.
#
# Default port 465 with implicit TLS (tls_starttls off), not 587/STARTTLS
# — confirmed against mail.xten.au's actual published mail client settings
# (2026-07-30): "SMTP Port: 465" under its SSL/TLS section. Port 465
# connects via TLS from the start; STARTTLS (587) negotiates TLS after an
# initial plaintext connection — msmtp needs the right one configured or
# the handshake fails outright, they aren't interchangeable.
if [ -n "${SMTP_HOST:-}" ] && [ -n "${SMTP_PASSWORD:-}" ]; then
    cat > /etc/msmtprc <<MSMTP
defaults
auth           on
tls            on
tls_starttls   off
logfile        /app/logs/msmtp.log

account        default
host           ${SMTP_HOST}
port           ${SMTP_PORT:-465}
user           ${SMTP_USER}
password       ${SMTP_PASSWORD}
from           ${SMTP_USER}
MSMTP
    chown www-data:www-data /etc/msmtprc
    chmod 600 /etc/msmtprc
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
