-- REQ-225 (Knowledge Base, Knowledge-Base-Module-Plan.md v0.1 section 4):
-- an editable reference table, not a hardcoded enum column, because the
-- set of enquiry types is expected to keep changing as new campaigns/
-- FAQs are drafted (the plan itself cites a week producing several new
-- categories) — adding a type should be a data change (INSERT), not a
-- code change (migration + enum update + redeploy). Both kb_articles
-- (021) and tickets (022) reference this same table so the two systems
-- share one taxonomy instead of growing separate ones that would need
-- reconciling later (plan section 10). Shape follows 011_tickets.sql's
-- conventions (SERIAL PK, deleted_at, created_at/updated_at, no
-- ON DELETE). name is unique since it's looked up by value the same way
-- Roles::idsByNames() looks up roles by name.
CREATE TABLE kb_enquiry_types (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(50) NOT NULL,
    description TEXT,
    deleted_at  TIMESTAMP,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (name)
);

-- Seeded here, in the same migration, rather than via SeedTask — this
-- is the fixed starting taxonomy the plan itself specifies (section 4),
-- not idempotent-insert-if-missing app data like roles/settings. Any
-- further additions are an ordinary INSERT via the backend UI/API from
-- here on.
INSERT INTO kb_enquiry_types (name, description) VALUES
    ('qualifying-question', 'Whether the enquirer/situation qualifies for a product or service before anything else is discussed.'),
    ('scope-and-price', 'What is and isn''t included, and what it costs.'),
    ('trust-and-access', 'Why the enquirer should trust the process, and what access/credentials it requires.'),
    ('guarantee-and-credit', 'Guarantees, refunds, and service credits.'),
    ('scheduling', 'Booking, timing, and availability.'),
    ('payment-enquiry', 'Invoicing, payment methods, and billing questions.'),
    ('escalation-required', 'Not answerable from existing content — needs a human.');
