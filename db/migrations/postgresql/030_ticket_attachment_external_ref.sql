-- Idempotent attachment uploads over the API (stack-internal ticket 99,
-- 31 Oct 2026). A client that relays files (XWar Legacy's in-game
-- tickets) may time out after the server has already stored the file;
-- external_ref lets it retry with the same reference and get the original
-- attachment back instead of a second copy. Scoped per ticket like
-- ticket_messages.external_ref. NULL never collides (every dialect allows
-- many NULLs in a unique index), so backend/frontend uploads, which send
-- no reference, are unaffected.
ALTER TABLE ticket_attachments ADD COLUMN external_ref VARCHAR(100);

CREATE UNIQUE INDEX idx_ticket_attachments_external_ref ON ticket_attachments (ticket_id, external_ref);
