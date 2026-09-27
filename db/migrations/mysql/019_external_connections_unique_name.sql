-- MySQL/MariaDB port of postgresql/019_external_connections_unique_name.sql; keep the two in step.
-- external_connections.name is the lookup key every module is now meant
-- to use (see MODULE-SPEC.md "External Credentials") -- without this,
-- two rows named e.g. "Resend" and "resend" would make that lookup
-- ambiguous. Case-insensitive, and scoped to live rows only so a name
-- can be reused after the row that held it was soft-deleted.
--
-- MySQL/MariaDB have no partial indexes, and MariaDB has no functional
-- ones. Same rule via a stored generated column that holds the name only
-- while the row is live (NULL once soft-deleted; a UNIQUE index allows any
-- number of NULLs), compared case-insensitively by the table's
-- utf8mb4_unicode_ci collation, so LOWER() isn't needed. The model sees
-- one extra read-only attribute, name_active; nothing writes it.
ALTER TABLE external_connections
    ADD COLUMN name_active VARCHAR(100) AS (CASE WHEN deleted_at IS NULL THEN name END) STORED;
CREATE UNIQUE INDEX external_connections_name_unique ON external_connections (name_active);
