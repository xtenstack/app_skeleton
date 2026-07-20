-- Cron system: cron_jobs is read by the CronTask CLI runner (./run cron run),
-- which is what an actual OS cron entry should call every minute. frequency
-- is a strtotime()-relative expression (e.g. '+1 day') applied to
-- last_run_at to decide if a job is due — not a full cron expression parser,
-- deliberately, to keep this simple. task/task_action map directly to a CLI
-- task class + action (e.g. task='audit', task_action='archive' -> AuditTask::archiveAction()).
CREATE TABLE cron_jobs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            VARCHAR(100) NOT NULL,
    task            VARCHAR(50) NOT NULL,
    task_action     VARCHAR(50) NOT NULL,
    frequency       VARCHAR(50) NOT NULL,
    enabled         INTEGER NOT NULL DEFAULT 1,
    last_run_at     DATETIME,
    last_status     VARCHAR(20),
    last_output     TEXT,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
