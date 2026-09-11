-- Generic operator code field on users, per Travis (2026-09-12): Charter
-- Agent sales operator codes (CA-01..CA-10, per DE-26-onboard-a-charter-
-- agent-v1.0-FINAL.md Step 5 and Lead-to-Close-Process's operator_codes
-- registry) don't get their own dedicated table/column -- they're just a
-- value in this generic field, manually prefixed by whoever assigns it
-- (CA- today, other operator-code schemes later without a schema
-- change). Nullable and unvalidated by design: most users never get one.
ALTER TABLE users ADD COLUMN operator_code TEXT;
