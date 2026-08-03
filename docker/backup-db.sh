#!/bin/sh
set -eu

# Daily Postgres backup — meant to run from the host crontab, e.g.:
#
#   0 3 * * * /opt/app_skeleton/docker/backup-db.sh >> /opt/app_skeleton/logs/backup-db.log 2>&1
#
# Uses the host's own pg_dump against db's loopback-bound port
# (docker-compose.yml's db.ports, 127.0.0.1:5432) rather than
# `docker compose exec`, so this doesn't depend on the app container's
# health or the compose project being in any particular state — just
# the db container being up.

BACKUP_DIR="${BACKUP_DIR:-/opt/app_skeleton/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
ENV_FILE="${ENV_FILE:-/opt/app_skeleton/.env}"
TIMESTAMP=$(date +%Y%m%d-%H%M%S)

# Same credentials docker-compose itself reads from .env — one source of
# truth, not a second copy that can drift.
set -a
. "$ENV_FILE"
set +a

mkdir -p "$BACKUP_DIR"

DUMP_FILE="$BACKUP_DIR/${DB_NAME}-${TIMESTAMP}.sql.gz"

PGPASSWORD="$DB_PASSWORD" pg_dump \
    -h 127.0.0.1 \
    -p 5432 \
    -U "$DB_USER" \
    -d "$DB_NAME" \
    --no-owner \
    --no-privileges \
    | gzip > "$DUMP_FILE"

echo "backup-db: wrote $DUMP_FILE ($(du -h "$DUMP_FILE" | cut -f1))"

# Local retention only — protects against "oops, bad migration/bad
# data," not against losing the droplet itself. Deliberately not doing
# more than that yet.
find "$BACKUP_DIR" -name "${DB_NAME}-*.sql.gz" -mtime "+${RETENTION_DAYS}" -delete

# NEXT STEP, not yet built: upload $DUMP_FILE to off-droplet storage
# (DigitalOcean Spaces or similar) here, e.g. via s3cmd/rclone/aws-cli —
# needed for real disaster recovery (droplet loss), which local
# retention alone does not cover.
