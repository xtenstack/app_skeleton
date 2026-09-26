-- MySQL/MariaDB port of postgresql/010_module_registry.sql; keep the two in step.
-- Thin orchestration table: what ModuleManager has discovered in vendor/ and
-- whether an admin has turned it on for this instance. No FKs into any
-- module's own domain tables — Composer's installed.json already says
-- what's physically present, this only adds the "installed but disabled"
-- state and a fast per-request enablement check. See App_skeleton\ModuleManager.
CREATE TABLE module_registry (
    id             INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    module_key     VARCHAR(50) UNIQUE NOT NULL,
    code           VARCHAR(10),
    tier           VARCHAR(20) NOT NULL,
    package_name   VARCHAR(150),
    version        VARCHAR(50),
    enabled        TINYINT(1) NOT NULL DEFAULT 0,
    discovered_at  DATETIME,
    updated_at     DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
