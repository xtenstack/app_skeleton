-- Charter Agents (human sales contractors) get their own role instead of
-- `operator` (Travis, 27 Sep 2026, MAA-20260927-001): they read the KB,
-- lodge tickets and see only their own, and work their own XTMK lead
-- reservations. Staff-only surfaces (KB authoring, ticket triage,
-- announcements marked staff) stay closed to them. SeedTask seeds it on
-- fresh installs; this covers existing ones, which only run migrations.
INSERT INTO roles (name) SELECT 'charter_agent' WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name = 'charter_agent');
