-- REQ-234: the licence server's check-in answer now carries the last day
-- a module is covered (expires_at). The instance keeps a copy so it can
-- say "expires in N days"; the server stays the authority (once the date
-- has passed it answers "not valid"). NULL = no expiry, or a server that
-- does not send one.

ALTER TABLE license_entitlements ADD COLUMN expires_on DATE;
