-- MySQL/MariaDB port of postgresql/016_error_log.sql; keep the two in step.
-- Lightweight self-hosted error monitoring (project audit, Tier 3).
-- Captures dispatch:beforeException's own exceptions (see
-- app/config/services_web.php) into a queryable table instead of only
-- the flat app.log file -- a browsable admin view is the actual point.
-- Deliberately not capturing bootstrap_web.php's own top-level
-- set_exception_handler/shutdown-function crashes here: those exist
-- specifically to survive a DI/DB that isn't available yet, so they
-- stay log-file-only by design, not a gap this migration should close.
CREATE TABLE error_log (
    id               INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    exception_class  VARCHAR(255) NOT NULL,
    message          TEXT,
    file             VARCHAR(500),
    line             INTEGER,
    trace            TEXT,
    request_method   VARCHAR(10),
    request_uri      VARCHAR(1000),
    user_id          INTEGER,
    resolved_at      DATETIME,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Table-level: MySQL silently ignores a REFERENCES written on the column.
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX error_log_created_at_idx ON error_log (created_at);
CREATE INDEX error_log_resolved_at_idx ON error_log (resolved_at);
