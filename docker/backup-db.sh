#!/bin/sh
set -eu

# Superseded by BackupTask (REQ-077, app/modules/cli/tasks/BackupTask.php)
# — that runs inside the app container itself via the same
# cron_jobs/CronRunner system AuditTask already uses, so a fresh install
# only needs one host crontab entry (`./run cron run`), not this
# separate one. Kept for any instance that already has this wired into
# its host crontab; not removed outright since swapping it out is a
# deliberate per-instance step (see RB-05), not automatic. New instances
# should use BackupTask, not this script.
#
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
TMP_SQL="$BACKUP_DIR/.${DB_NAME}-${TIMESTAMP}.sql.tmp"
ERR_FILE=$(mktemp)

# Reports every run (success or failure) to cron_run_log so it shows up
# on the backend's Cron screen (Session 13) alongside the app's own
# jobs — this script is driven by host cron, not the app's own
# runDueJobs() loop (the app container has no pg_dump and no reason to
# grow one just for this), so without reporting here the daily backup
# would be entirely invisible to the admin UI. cron_job_id is left NULL
# — see cron_run_log's migration comment. A failure to reach the DB to
# log is swallowed (|| true) — it must never mask, or be mistaken for,
# the backup's own actual result. Inherent limitation, not a bug: most
# realistic pg_dump failures (wrong credentials, db down/unreachable)
# use these exact same connection details, so the very failure this
# exists to report is often also the one that stops the report from
# landing. logs/backup-db.log (the host crontab's own >> redirect,
# see top of file) is still the fallback of record for that case —
# this INSERT is a convenience for the common case, not a replacement.
report_and_exit() {
    exit_code=$?
    trap - EXIT

    if [ "$exit_code" -eq 0 ]; then
        status="success"
        output="wrote $DUMP_FILE ($(du -h "$DUMP_FILE" 2>/dev/null | cut -f1))"
    else
        status="error"
        output=$(tail -c 2000 "$ERR_FILE" 2>/dev/null)
        [ -n "$output" ] || output="backup failed, exit code $exit_code"
    fi

    # Piped via stdin, not -c "..." — psql's :'var' quoted-variable
    # substitution is only honoured reading a script (stdin or -f), not
    # in -c single-command mode (verified directly against psql 18.4:
    # -c leaves ":'status'" completely uninterpreted and errors on the
    # literal colon). This is exactly the case :'var' exists for —
    # output is arbitrary command/error text that may contain quotes.
    PGPASSWORD="$DB_PASSWORD" psql -h 127.0.0.1 -p 5432 -U "$DB_USER" -d "$DB_NAME" -q \
        -v status="$status" -v output="$output" \
        >/dev/null 2>&1 <<-SQL || true
		INSERT INTO cron_run_log (cron_job_id, job_name, status, output, ran_at) VALUES (NULL, 'Daily Postgres Backup', :'status', :'output', now());
		SQL

    rm -f "$ERR_FILE" "$TMP_SQL"
    exit "$exit_code"
}
trap report_and_exit EXIT

# Dumped to a plain file first, not `pg_dump | gzip > file` directly —
# under plain `set -e` (no pipefail in POSIX sh/dash), a pipe's exit
# status is the LAST command's (gzip), so a failed pg_dump partway
# through would still let gzip "succeed" on a truncated dump and this
# script would report success. Splitting the steps makes pg_dump's own
# exit code directly checkable.
PGPASSWORD="$DB_PASSWORD" pg_dump \
    -h 127.0.0.1 \
    -p 5432 \
    -U "$DB_USER" \
    -d "$DB_NAME" \
    --no-owner \
    --no-privileges \
    > "$TMP_SQL" 2>"$ERR_FILE"

gzip -c "$TMP_SQL" > "$DUMP_FILE"

echo "backup-db: wrote $DUMP_FILE ($(du -h "$DUMP_FILE" | cut -f1))"

# Local retention only — protects against "oops, bad migration/bad
# data," not against losing the droplet itself. Deliberately not doing
# more than that yet.
find "$BACKUP_DIR" -name "${DB_NAME}-*.sql.gz" -mtime "+${RETENTION_DAYS}" -delete

# NEXT STEP, not yet built: upload $DUMP_FILE to off-droplet storage
# (DigitalOcean Spaces or similar) here, e.g. via s3cmd/rclone/aws-cli —
# needed for real disaster recovery (droplet loss), which local
# retention alone does not cover.
