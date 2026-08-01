-- Tickets: escalations/reports raised by an agent, staff member, or
-- customer for a human operator to triage. reporter_user_id covers all
-- three uniformly (agents are ordinary `users` rows with the `agent`
-- role — see RBAC section, no separate identity system). Nullable
-- because it's still possible for a ticket to come in without a
-- resolvable user (e.g. a future anonymous/email-in intake path).
-- reporter_api_key_id additionally records which API key authenticated
-- an API-created ticket, for traceability, independent of who the
-- reporter is (e.g. staff filing on a customer's behalf via the backend
-- UI has no api_key_id at all). assigned_to_user_id is always a human
-- (agents are never a valid assignee — enforced at the controller level,
-- see section 6). consolidated_into_ticket_id is a self-reference for
-- merge/dedupe. retest_ref/last_retest_* are the hook a future
-- task-runner reads/writes (see section 5 — nothing here builds that
-- runner). auto_closed_at plus qa_reviewed_at/by/outcome support
-- spot-checking a sample of auto-closes.
CREATE TABLE tickets (
    id                          SERIAL PRIMARY KEY,
    title                       VARCHAR(200) NOT NULL,
    description                 TEXT,
    severity                    VARCHAR(20) NOT NULL DEFAULT 'normal',
    status                      VARCHAR(20) NOT NULL DEFAULT 'open',
    source_ref                  VARCHAR(255),
    reporter_user_id            INTEGER,
    reporter_api_key_id         INTEGER,
    assigned_to_user_id         INTEGER,
    consolidated_into_ticket_id INTEGER,
    retest_ref                  VARCHAR(255),
    retest_agent_key            VARCHAR(50),
    last_retest_result          VARCHAR(10),
    last_retest_at              TIMESTAMP,
    last_retest_notes           TEXT,
    closed_at                   TIMESTAMP,
    close_reason                VARCHAR(20),
    auto_closed_at              TIMESTAMP,
    qa_reviewed_at              TIMESTAMP,
    qa_reviewed_by              INTEGER,
    qa_outcome                  VARCHAR(20),
    deleted_at                  TIMESTAMP,
    created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reporter_user_id) REFERENCES users(id),
    FOREIGN KEY (reporter_api_key_id) REFERENCES api_keys(id),
    FOREIGN KEY (assigned_to_user_id) REFERENCES users(id),
    FOREIGN KEY (consolidated_into_ticket_id) REFERENCES tickets(id),
    FOREIGN KEY (qa_reviewed_by) REFERENCES users(id)
);
