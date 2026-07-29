-- User profiles: one-to-one extension of users for non-auth fields.
-- locale defaults to en-AU per project owner's preference; the column exists
-- now so per-user language selection isn't blocked later even though the
-- actual translation-file mechanism isn't built yet.
CREATE TABLE user_profiles (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL UNIQUE,
    avatar_path VARCHAR(255),
    phone       VARCHAR(30),
    bio         TEXT,
    timezone    VARCHAR(50) NOT NULL DEFAULT 'UTC',
    locale      VARCHAR(10) NOT NULL DEFAULT 'en-AU',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Per-user preferences, key/value so new settings don't need a migration.
CREATE TABLE user_settings (
    id              SERIAL PRIMARY KEY,
    user_id         INTEGER NOT NULL,
    setting_key     VARCHAR(100) NOT NULL,
    setting_value   TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE (user_id, setting_key)
);

-- Global, app-wide key/value settings (admin-only).
CREATE TABLE settings (
    id              SERIAL PRIMARY KEY,
    setting_key     VARCHAR(100) NOT NULL UNIQUE,
    setting_value   TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- API keys: only a hash of the token is ever stored. token_prefix is a short
-- non-secret slice shown in the UI so a key can be identified without
-- re-displaying the secret. Rate limiting / scopes deliberately deferred.
CREATE TABLE api_keys (
    id              SERIAL PRIMARY KEY,
    user_id         INTEGER NOT NULL,
    name            VARCHAR(100) NOT NULL,
    token_hash      VARCHAR(255) NOT NULL,
    token_prefix    VARCHAR(12) NOT NULL,
    last_used_at    TIMESTAMP,
    revoked_at      TIMESTAMP,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Unified audit trail: data changes (entity_type = table name, action =
-- insert/update/delete, old_values/new_values as JSON) and process events
-- (entity_type = 'auth', action = login/login_failed/logout) in one table
-- so the backend only needs one screen to show both.
CREATE TABLE audit_log (
    id              SERIAL PRIMARY KEY,
    entity_type     VARCHAR(50) NOT NULL,
    entity_id       INTEGER,
    action          VARCHAR(20) NOT NULL,
    actor_user_id   INTEGER,
    old_values      TEXT,
    new_values      TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_user_id) REFERENCES users(id)
);
