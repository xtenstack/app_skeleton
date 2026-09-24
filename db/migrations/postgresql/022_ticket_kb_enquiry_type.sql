-- REQ-225 (Knowledge-Base-Module-Plan.md v0.1 section 10, decided by
-- Travis, not just an open question): tickets get the same enquiry-type
-- taxonomy as kb_articles (020) rather than a separate one, so a typed
-- ticket can drive GET /api/kb-articles/match?enquiry_type=... directly
-- during triage instead of starting from a blank page. Depends on
-- kb_enquiry_types (020) existing first, hence this being a follow-on
-- migration rather than part of 011_tickets.sql. Nullable and, per
-- section 10's own "human-set initially" plus open question 4 (Code's
-- call): always set/confirmed by a human at triage — the backend ticket
-- edit form exposes this field, the API createAction does not, so an
-- agent creating a ticket can propose a type only via a note/description,
-- never write this column directly. No ON DELETE, same convention as
-- every other FK in this schema (011_tickets.sql).
ALTER TABLE tickets ADD COLUMN kb_enquiry_type_id INTEGER;
ALTER TABLE tickets ADD CONSTRAINT tickets_kb_enquiry_type_id_fkey FOREIGN KEY (kb_enquiry_type_id) REFERENCES kb_enquiry_types(id);
