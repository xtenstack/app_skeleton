-- MySQL/MariaDB port of postgresql/023_charter_agent_role.sql; keep the two in step.
-- Charter Agents (human sales contractors) get their own role instead of
-- `operator` (Travis, 27 Sep 2026, MAA-20260927-001). SeedTask seeds it on
-- fresh installs; this covers existing ones, which only run migrations.
-- FROM DUAL: MySQL doesn't accept a WHERE on a SELECT with no FROM.
INSERT INTO roles (name) SELECT 'charter_agent' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name = 'charter_agent');
