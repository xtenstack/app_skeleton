<?php
declare(strict_types=1);

/**
 * Run automatically via composer's post-install-cmd/post-update-cmd.
 * Idempotent: a fresh install with no db/app_skeleton.sqlite gets one
 * created and every db/patches/*.sql applied in order, then default
 * roles/settings/cron jobs are seeded. An existing database is left
 * alone — this only ever bootstraps a brand new install, it's not a
 * migration runner (patches are still applied by hand during development,
 * see db/patches/ and the dev-workflow notes).
 */

$root   = dirname(__DIR__);
$dbDir  = $root . '/db';
$dbFile = $dbDir . '/app_skeleton.sqlite';

if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

if (is_file($dbFile)) {
    echo "app_skeleton: database already exists, skipping schema setup.\n";
    exit(0);
}

echo "app_skeleton: creating database and applying schema patches...\n";

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$patches = glob($root . '/db/patches/*.sql');
sort($patches);

foreach ($patches as $patch) {
    echo '  applying ' . basename($patch) . "\n";
    $pdo->exec((string) file_get_contents($patch));
}

$pdo = null;

echo "app_skeleton: schema applied. Seeding defaults...\n";

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/run') . ' seed run', $seedStatus);

if ($seedStatus !== 0) {
    fwrite(STDERR, "app_skeleton: seeding failed (exit {$seedStatus}) — schema is in place but default data wasn't seeded. Run './run seed run' by hand.\n");
    exit($seedStatus);
}

echo "app_skeleton: install complete.\n";
