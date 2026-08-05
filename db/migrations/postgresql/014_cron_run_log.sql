-- Per-execution cron history. cron_jobs' own last_run_at/last_status/
-- last_output columns only ever hold the MOST RECENT run — no way to see
-- when a job ran two days ago, or to tell one accumulated log line from
-- another (Session 13: "it doesn't have dates, so I have to work out
-- which sequential record I am looking at"). cron_job_id is nullable
-- and ON DELETE SET NULL rather than CASCADE — a deleted job's history
-- stays readable (job_name is a snapshot, not a live join) instead of
-- vanishing with it. Also written to directly by docker/backup-db.sh
-- (cron_job_id NULL, job_name 'Daily Postgres Backup') so the daily
-- backup — driven by host cron against the host's own pg_dump, not the
-- app's runDueJobs() loop, since the app container has no psql/pg_dump
-- and no reason to grow one — still shows up in this same log rather
-- than being invisible to the admin UI.
CREATE TABLE cron_run_log (
    id           SERIAL PRIMARY KEY,
    cron_job_id  INTEGER REFERENCES cron_jobs(id) ON DELETE SET NULL,
    job_name     VARCHAR(100) NOT NULL,
    status       VARCHAR(20) NOT NULL,
    output       TEXT,
    ran_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_cron_run_log_ran_at ON cron_run_log (ran_at DESC);
CREATE INDEX idx_cron_run_log_cron_job_id ON cron_run_log (cron_job_id);
