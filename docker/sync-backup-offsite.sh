#!/bin/sh
set -eu

# Remediation item #1 (Data-Restore-Audit-Report-Stack-Internal-Dry-Run-2026-08-29.md):
# automates the off-instance backup copy that was previously a manual rsync
# (sync-prod-backups Claude Code skill), and closes the check A6/A7 gap.
#
# Runs on the HOST (not inside the app container) — same reasoning as the legacy
# docker/backup-db.sh: this only needs read access to the already-written dump
# files in ./backups, not the app's own runtime. Meant to run right after the
# existing `./run cron run` host crontab entry, so it always syncs whatever the
# app just wrote, not on its own independent schedule.
#
# Credentials are read from environment, never hardcoded — see .env.spaces.example.
# Per DE-22's own pattern: this script does not create or rotate the Spaces key,
# that is a credential-provisioning step for whoever holds the DO account.
#
#   0 3 * * * /opt/app_skeleton/docker/sync-backup-offsite.sh >> /opt/app_skeleton/logs/sync-offsite.log 2>&1
#
# Logs locally FIRST, before anything that touches the database — see the
# 2026-08-29 finding that cron_run_log cannot report a DB-outage failure,
# because writing to it needs the same connection that just failed. This
# script's own success/failure is never gated on the app's database at all.

BACKUP_DIR="${BACKUP_DIR:-/opt/app_skeleton/backups}"
LOG_FILE="${LOG_FILE:-/opt/app_skeleton/logs/sync-offsite.log}"
REMOTE_NAME="${DO_SPACES_RCLONE_REMOTE:-xten-spaces}"
BUCKET="${DO_SPACES_BUCKET:?set DO_SPACES_BUCKET}"
REGION="${DO_SPACES_REGION:-syd1}"
ACCESS_KEY="${DO_SPACES_ACCESS_KEY:?set DO_SPACES_ACCESS_KEY}"
SECRET_KEY="${DO_SPACES_SECRET_KEY:?set DO_SPACES_SECRET_KEY}"

TIMESTAMP=$(date -Iseconds)

# rclone config generated fresh each run from env vars, not a persisted config
# file with embedded secrets on disk.
RCLONE_CONFIG=$(mktemp)
trap 'rm -f "$RCLONE_CONFIG"' EXIT

cat > "$RCLONE_CONFIG" <<-EOF
	[${REMOTE_NAME}]
	type = s3
	provider = DigitalOcean
	access_key_id = ${ACCESS_KEY}
	secret_access_key = ${SECRET_KEY}
	endpoint = ${REGION}.digitaloceanspaces.com
	EOF

echo "[${TIMESTAMP}] sync-offsite: starting, bucket=${BUCKET} region=${REGION}" >> "$LOG_FILE"

if rclone --config "$RCLONE_CONFIG" sync "$BACKUP_DIR" "${REMOTE_NAME}:${BUCKET}/prod/app_skeleton-backups" \
    --min-age 1m \
    >> "$LOG_FILE" 2>&1; then
    echo "[${TIMESTAMP}] sync-offsite: success" >> "$LOG_FILE"
    exit 0
else
    EXIT_CODE=$?
    echo "[${TIMESTAMP}] sync-offsite: FAILED, exit code ${EXIT_CODE} — see above for rclone output" >> "$LOG_FILE"
    exit "$EXIT_CODE"
fi
