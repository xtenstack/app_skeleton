-- MySQL/MariaDB port of postgresql/012_ticket_type_notes.sql; keep the two in step.
-- ticket_type: the four buckets from the Session 11 request (Bug/Issue/
-- Feature/Support), stored lowercase to match severity/status's existing
-- convention. notes: staff-only internal notes, deliberately separate from
-- description (the reporter-supplied text) — never returned by the API
-- module's TicketsController::serialize(), backend-only.
ALTER TABLE tickets
    ADD COLUMN ticket_type VARCHAR(20) NOT NULL DEFAULT 'bug',
    ADD COLUMN notes       TEXT;
