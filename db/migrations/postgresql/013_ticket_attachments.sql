-- Files attached to a ticket (screenshots, phpinfo() dumps, etc). Stored
-- outside public/ — unlike avatars, attachment contents can be sensitive
-- (phpinfo() output routinely includes server paths/config), so they're
-- served through an authenticated controller action rather than Caddy's
-- static file_server. filename is the randomized on-disk name;
-- original_filename is what the uploader saw, kept for display/download
-- only, never used as a path.
CREATE TABLE ticket_attachments (
    id                  SERIAL PRIMARY KEY,
    ticket_id           INTEGER NOT NULL,
    filename            VARCHAR(255) NOT NULL,
    original_filename   VARCHAR(255) NOT NULL,
    mime_type           VARCHAR(100) NOT NULL,
    size_bytes          INTEGER NOT NULL,
    uploaded_by_user_id INTEGER,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id),
    FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)
);
