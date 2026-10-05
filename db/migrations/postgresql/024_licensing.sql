-- Client side of module licensing (docs/MODULE-SPEC.md, Licensing): the
-- keys an admin has entered on this instance, which modules each key is
-- tried for, and the last known answer from the licence server per module.
--
-- license_keys.key_encrypted is ciphertext (App_skeleton\Crypto), never
-- the key itself; key_hint is its last four characters, kept in the clear
-- only so the admin screen can tell two keys apart without decrypting
-- either. Removing a key blanks key_encrypted and soft-deletes the row.
--
-- license_entitlements is a cache, not a source of truth: one row per
-- module that needs a key, so a page load reads the licence state locally
-- and never waits on the network. last_successful_checkin_at only ever
-- moves forward on a "valid" answer; the 120-day grace period is counted
-- from it. license_key_id has no foreign key on purpose: it names the key
-- that last validated the module, and that key may since have been removed.
CREATE TABLE license_keys (
    id              SERIAL PRIMARY KEY,
    label           VARCHAR(100),
    key_encrypted   TEXT NOT NULL,
    key_hint        VARCHAR(8) NOT NULL DEFAULT '',
    deleted_at      TIMESTAMP,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE license_key_modules (
    id              SERIAL PRIMARY KEY,
    license_key_id  INTEGER NOT NULL,
    module_key      VARCHAR(50) NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (license_key_id) REFERENCES license_keys(id),
    UNIQUE (license_key_id, module_key)
);

CREATE TABLE license_entitlements (
    id                          SERIAL PRIMARY KEY,
    module_key                  VARCHAR(50) NOT NULL UNIQUE,
    state                       VARCHAR(20) NOT NULL,
    license_key_id              INTEGER,
    last_successful_checkin_at  TIMESTAMP,
    last_attempt_at             TIMESTAMP,
    last_result                 VARCHAR(20),
    created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
