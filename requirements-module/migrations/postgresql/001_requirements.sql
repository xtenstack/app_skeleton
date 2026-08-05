-- One row per REQ-NNN entry. display_id is the generated "REQ-042"
-- string (sprintf('REQ-%03d', $n), unpadded past 3 digits) — stored, not
-- computed on read, so historical ids never shift even if generation
-- logic changes later. origin_session is free text (e.g. "Session 12")
-- matching the existing Requirements-List.md "Originated" column.
--
-- changelogs is created first since requirements.changelog_id's FK needs
-- it to exist already.
CREATE TABLE changelogs (
    id           SERIAL PRIMARY KEY,
    version       VARCHAR(50) NOT NULL UNIQUE,
    released_at   TIMESTAMP,
    deleted_at    TIMESTAMP,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE requirements (
    id                  SERIAL PRIMARY KEY,
    display_id          VARCHAR(20) NOT NULL UNIQUE,
    title               VARCHAR(200) NOT NULL,
    description         TEXT,
    status              VARCHAR(40) NOT NULL DEFAULT 'open',
    changelog_decision  VARCHAR(30),
    changelog_id        INTEGER,
    changelog_note      TEXT,
    origin_session      VARCHAR(100),
    deleted_at          TIMESTAMP,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (changelog_id) REFERENCES changelogs(id)
);
