-- Thin orchestration table: what ModuleManager has discovered in vendor/ and
-- whether an admin has turned it on for this instance. No FKs into any
-- module's own domain tables — Composer's installed.json already says
-- what's physically present, this only adds the "installed but disabled"
-- state and a fast per-request enablement check. See App_skeleton\ModuleManager.
CREATE TABLE module_registry (
    id             SERIAL PRIMARY KEY,
    module_key     VARCHAR(50) UNIQUE NOT NULL,
    code           VARCHAR(10),
    tier           VARCHAR(20) NOT NULL,
    package_name   VARCHAR(150),
    version        VARCHAR(50),
    enabled        BOOLEAN NOT NULL DEFAULT false,
    discovered_at  TIMESTAMP,
    updated_at     TIMESTAMP
);
