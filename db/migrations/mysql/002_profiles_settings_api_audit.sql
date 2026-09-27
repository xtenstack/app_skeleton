-- MySQL/MariaDB port of postgresql/002_profiles_settings_api_audit.sql; keep the two in step.
-- User profiles: one-to-one extension of users for non-auth fields.
-- locale defaults to en-AU per project owner's preference; the column exists
-- now so per-user language selection isn't blocked later even though the
-- actual translation-file mechanism isn't built yet.
CREATE TABLE user_profiles (
    id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     INTEGER NOT NULL UNIQUE,
    avatar_path VARCHAR(255),
    phone       VARCHAR(30),
    bio         TEXT,
    timezone    VARCHAR(50) NOT NULL DEFAULT 'UTC',
    locale      VARCHAR(10) NOT NULL DEFAULT 'en-AU',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user preferences, key/value so new settings don't need a migration.
CREATE TABLE user_settings (
    id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id         INTEGER NOT NULL,
    setting_key     VARCHAR(100) NOT NULL,
    setting_value   TEXT,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE (user_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Global, app-wide key/value settings (admin-only).
CREATE TABLE settings (
    id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setting_key     VARCHAR(100) NOT NULL UNIQUE,
    setting_value   TEXT,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API keys: only a hash of the token is ever stored. token_prefix is a short
-- non-secret slice shown in the UI so a key can be identified without
-- re-displaying the secret. Rate limiting / scopes deliberately deferred.
CREATE TABLE api_keys (
    id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id         INTEGER NOT NULL,
    name            VARCHAR(100) NOT NULL,
    token_hash      VARCHAR(255) NOT NULL,
    token_prefix    VARCHAR(12) NOT NULL,
    last_used_at    DATETIME,
    revoked_at      DATETIME,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Unified audit trail: data changes (entity_type = table name, action =
-- insert/update/delete, old_values/new_values as JSON) and process events
-- (entity_type = 'auth', action = login/login_failed/logout) in one table
-- so the backend only needs one screen to show both.
CREATE TABLE audit_log (
    id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type     VARCHAR(50) NOT NULL,
    entity_id       INTEGER,
    action          VARCHAR(20) NOT NULL,
    actor_user_id   INTEGER,
    old_values      TEXT,
    new_values      TEXT,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
