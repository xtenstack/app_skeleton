-- External Connections: outbound integrations to third-party APIs, distinct
-- from api_keys (which are credentials *other* systems use to call us).
-- credential is encrypted at rest (see App_skeleton\Crypto) since, unlike
-- api_keys' one-way hash, this value has to be recoverable to actually call
-- the external API. config is freeform JSON for whatever a given API needs
-- (extra headers, scopes, endpoint paths, protocol notes).
CREATE TABLE external_connections (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            VARCHAR(100) NOT NULL,
    base_url        VARCHAR(255),
    auth_type       VARCHAR(30) NOT NULL DEFAULT 'api_key',
    credential      TEXT,
    config          TEXT,
    is_active       INTEGER NOT NULL DEFAULT 1,
    deleted_at      DATETIME,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
