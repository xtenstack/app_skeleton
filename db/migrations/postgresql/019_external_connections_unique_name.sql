-- external_connections.name is the lookup key every module is now meant
-- to use (see MODULE-SPEC.md "External Credentials") -- without this,
-- two rows named e.g. "Resend" and "resend" would make that lookup
-- ambiguous. Case-insensitive, and scoped to live rows only so a name
-- can be reused after the row that held it was soft-deleted.
CREATE UNIQUE INDEX external_connections_name_unique
    ON external_connections (LOWER(name))
    WHERE deleted_at IS NULL;
