-- MySQL/MariaDB port of postgresql/021_kb_articles.sql; keep the two in step.
-- REQ-225 (Knowledge Base, Knowledge-Base-Module-Plan.md v0.1 section 3):
-- article content, structured around the one hard requirement (plan
-- section 1) that every article carry an enquiry type, not just
-- freetext — enquiry_type_id is NOT NULL for that reason, FK to
-- kb_enquiry_types (020). body is markdown, rendered server-side on the
-- way out (league/commonmark, safe mode) rather than stored as HTML, so
-- the raw source stays editable and portable. summary is a short,
-- separate field for list/preview/match display rather than truncating
-- body ad hoc. product_ref is nullable free text (same "no Products
-- module to link to yet" reasoning as tickets.project, 015) — scopes an
-- article to a specific offering (e.g. DEP-RESTORE) when relevant, left
-- blank when an article applies generally. visibility/status are
-- app-validated short codes rather than a DB CHECK constraint, matching
-- how tickets.status/severity are handled. created_by_user_id/
-- updated_by_user_id are who authored/last touched it (not who's
-- allowed to see it — that's visibility) — both nullable since a
-- future non-interactive path (e.g. an agent-drafted article awaiting
-- review, plan section 5) may not always have a human author yet.
-- Shape otherwise follows 011_tickets.sql's conventions (SERIAL PK,
-- deleted_at, created_at/updated_at, no ON DELETE).
CREATE TABLE kb_articles (
    id                  INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title               VARCHAR(200) NOT NULL,
    body                TEXT NOT NULL,
    summary             VARCHAR(500),
    enquiry_type_id     INTEGER NOT NULL,
    product_ref         VARCHAR(100),
    visibility          VARCHAR(20) NOT NULL DEFAULT 'internal',
    status              VARCHAR(20) NOT NULL DEFAULT 'draft',
    published_at        DATETIME,
    created_by_user_id  INTEGER,
    updated_by_user_id  INTEGER,
    deleted_at          DATETIME,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (enquiry_type_id) REFERENCES kb_enquiry_types(id),
    FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
