-- Reverse/rollback support: a reversal is a NEW audit_log row (action =
-- 'reversal') pointing back at the entry it undid, rather than mutating or
-- removing the original row. reversed_audit_log_id is a soft reference to
-- audit_log.id (no FK constraint, kept as a faithful port of the original
-- SQLite patch rather than tightening behavior here) — this table is
-- intentionally append-only/self-referential.
ALTER TABLE audit_log ADD COLUMN reversed_audit_log_id INTEGER;
