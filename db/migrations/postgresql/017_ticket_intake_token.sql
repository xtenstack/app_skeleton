-- Live intake-form pipeline (SSA/Tim close/complete step, Deploy DRA
-- Agent Room, 2026-09-09): a customer who closes a DRA sale gets a link
-- to a public intake form carrying this REQ's id + a random token, so
-- the form's own unauthenticated submit endpoint (PublicIntakeController)
-- can prove it's writing into the right ticket without exposing a raw,
-- guessable sequential id in the URL (app_skeleton's own house rule
-- against an accidentally-public endpoint applies here — a raw REQNUM
-- would let anyone enumerate and inject notes into other clients'
-- tickets). intake_token is generated once at ticket-creation time and
-- returned to the caller only in that create response, never via
-- serialize() — same staff-only-field convention as `notes`/`project`
-- (012/015). intake_data holds the submitted form as JSON once
-- returned; intake_submitted_at records when.
ALTER TABLE tickets ADD COLUMN intake_token VARCHAR(64);
ALTER TABLE tickets ADD COLUMN intake_data TEXT;
ALTER TABLE tickets ADD COLUMN intake_submitted_at TIMESTAMP;

CREATE UNIQUE INDEX tickets_intake_token_idx ON tickets (intake_token) WHERE intake_token IS NOT NULL;
