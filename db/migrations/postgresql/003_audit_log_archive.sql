-- Mirror of audit_log. Old rows get moved here (not deleted) by the
-- `./run audit archive` CLI task: auth events (login/logout/login_failed)
-- older than 1 day, data-change events (insert/update/delete) older than
-- 1 year. Same schema, plus archived_at recording when the move happened.
-- No SERIAL here deliberately: ids are copied verbatim from audit_log by
-- the archive task, not generated fresh.
CREATE TABLE audit_log_archive (
    id              INTEGER PRIMARY KEY,
    entity_type     VARCHAR(50) NOT NULL,
    entity_id       INTEGER,
    action          VARCHAR(20) NOT NULL,
    actor_user_id   INTEGER,
    old_values      TEXT,
    new_values      TEXT,
    created_at      TIMESTAMP NOT NULL,
    archived_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
