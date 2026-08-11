-- Session 15: free-text project reference (currently the corresponding
-- Dolibarr project number/name — no Projects module exists yet to link
-- to), same shape as requirements-module's own project column added
-- this session. Staff-only, same convention as `notes` (012) — never
-- returned by the API module's TicketsController::serialize().
ALTER TABLE tickets ADD COLUMN project VARCHAR(100);
