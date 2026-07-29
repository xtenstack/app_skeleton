<?php
declare(strict_types=1);

/**
 * Run automatically via composer's post-install-cmd/post-update-cmd.
 * Idempotent: `migrate run` only applies migrations not already recorded in
 * schema_migrations, and `seed run` only inserts defaults that don't already
 * exist — so this is safe to run on a fresh database or an existing one.
 * Requires the target database (see app/config/config.php /
 * config.local.php) to already exist and be reachable; this only applies
 * schema and seed data to it, it doesn't create the database itself.
 */

$root = dirname(__DIR__);

echo "app_skeleton: applying migrations...\n";

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/run') . ' migrate run', $migrateStatus);

if ($migrateStatus !== 0) {
    fwrite(STDERR, "app_skeleton: migrations failed (exit {$migrateStatus}). Run './run migrate status' to see what's applied.\n");
    exit($migrateStatus);
}

echo "app_skeleton: seeding defaults...\n";

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/run') . ' seed run', $seedStatus);

if ($seedStatus !== 0) {
    fwrite(STDERR, "app_skeleton: seeding failed (exit {$seedStatus}) — schema is in place but default data wasn't seeded. Run './run seed run' by hand.\n");
    exit($seedStatus);
}

echo "app_skeleton: syncing module registry...\n";

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/run') . ' modules sync', $modulesStatus);

if ($modulesStatus !== 0) {
    fwrite(STDERR, "app_skeleton: module sync failed (exit {$modulesStatus}) — schema/seed data are in place, but discovered module packages weren't registered. Run './run modules sync' by hand.\n");
    exit($modulesStatus);
}

echo "app_skeleton: install complete.\n";
