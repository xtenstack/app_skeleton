-- MySQL/MariaDB port of postgresql/025_audit_actor_api_key.sql; keep the two in step.
-- Which API key an audited change or event was made with, alongside the
-- user it belongs to (actor_user_id). NULL for a browser session, a CLI
-- task, and every row written before this column existed. A soft reference
-- to api_keys.id with no foreign key, like reversed_audit_log_id: the audit
-- trail must outlive the key, and an audit insert must never fail because
-- a key row has gone. Added to the archive table too, so `./run audit
-- archive` carries it across.
ALTER TABLE audit_log ADD COLUMN actor_api_key_id INTEGER;
ALTER TABLE audit_log_archive ADD COLUMN actor_api_key_id INTEGER;
