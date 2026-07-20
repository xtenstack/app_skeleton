-- Generic soft deletes: deleted_at is a nullable timestamp meaning "hidden
-- and recoverable", distinct from users.is_active (a business toggle a user
-- can flip themselves). Applied to the two existing user-managed entities;
-- new tables (e.g. external_connections) should include deleted_at from the
-- start rather than being retrofitted here.
ALTER TABLE users ADD COLUMN deleted_at DATETIME;
ALTER TABLE items ADD COLUMN deleted_at DATETIME;
