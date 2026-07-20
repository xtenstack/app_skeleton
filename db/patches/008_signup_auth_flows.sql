-- Signup / email verification / forgot-password support. Tokens live
-- directly on users (small scale, matches the rest of this schema's
-- extension-column style) rather than separate token tables.
ALTER TABLE users ADD COLUMN email_verified_at DATETIME;
ALTER TABLE users ADD COLUMN verification_token VARCHAR(64);
ALTER TABLE users ADD COLUMN verification_token_expires_at DATETIME;
ALTER TABLE users ADD COLUMN password_reset_token VARCHAR(64);
ALTER TABLE users ADD COLUMN password_reset_expires_at DATETIME;
