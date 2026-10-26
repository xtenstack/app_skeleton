-- SQLite port of postgresql/028_ticket_messages.sql; keep the three in step.
-- Ticket conversation: staff replies and internal notes on a ticket
-- (GX-SPEC-05, 26 Oct 2026). visibility is 'public' (a game/service client
-- may read it back) or 'internal' (staff only, never returned to a client).
-- Kept apart from tickets.notes, which stays the single staff scratch
-- field. author_user_id/author_api_key_id attribute the writer;
-- external_ref lets a caller retry safely: the unique key makes a repeat
-- of (ticket, author, external_ref) return the original message. NULL
-- external_refs never collide (every dialect allows many NULLs in a unique
-- index). Messages are immutable once written.
CREATE TABLE ticket_messages (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id          INTEGER NOT NULL,
    author_user_id     INTEGER,
    author_api_key_id  INTEGER,
    visibility         VARCHAR(10) NOT NULL DEFAULT 'internal',
    body               TEXT NOT NULL,
    external_ref       VARCHAR(100),
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id),
    FOREIGN KEY (author_user_id) REFERENCES users(id),
    FOREIGN KEY (author_api_key_id) REFERENCES api_keys(id),
    CHECK (visibility IN ('public', 'internal')),
    UNIQUE (ticket_id, author_user_id, external_ref)
);

CREATE INDEX idx_ticket_messages_ticket_id ON ticket_messages (ticket_id, id);
