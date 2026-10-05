<?php
declare(strict_types=1);

/**
 * A stand-in for the licence server (xtenstack/internal's licensing-module,
 * POST /api/lice/checkin), for tests only. Run under PHP's built-in server:
 *
 *   FAKE_LICENSE_DIR=/some/tmp/dir php -S 127.0.0.1:<port> tests/fixtures/license-server/router.php
 *
 * Answers exactly as the real endpoint does: 200 {"valid": true} when the
 * key is active and covers the module, and the same 403 {"valid": false}
 * for an unknown key, a revoked key and a module the key does not cover.
 *
 * FAKE_LICENSE_DIR/state.json, rewritten by the test between requests:
 *   {"mode": "normal", "keys": {"<key>": {"status": "active", "modules": ["a", "b"]}}}
 * mode is one of normal | error500 | garbage | redirect | slow | valid-with-500.
 * Every request received is appended to FAKE_LICENSE_DIR/requests.log as
 * one JSON line, so a test can assert what was (and was not) sent.
 */
$dir   = (string) getenv('FAKE_LICENSE_DIR');
$state = json_decode((string) @file_get_contents($dir . '/state.json'), true) ?: [];
$path  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body  = json_decode((string) file_get_contents('php://input'), true);
$body  = is_array($body) ? $body : [];

if ($path === '/idle-probe') {
    return;
}

file_put_contents($dir . '/requests.log', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path'   => $path,
    'key'    => $body['key'] ?? null,
    'module' => $body['module'] ?? null,
]) . "\n", FILE_APPEND | LOCK_EX);

$json = static function (int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
};

if ($path !== '/api/lice/checkin') {
    $json(404, ['error' => 'Not found']);

    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $json(405, ['error' => 'POST required']);

    return;
}

switch ($state['mode'] ?? 'normal') {
    case 'error500':
        $json(500, ['error' => 'Internal server error']);

        return;

    case 'garbage':
        http_response_code(200);
        header('Content-Type: text/html');
        echo '<html><body>502 Bad Gateway</body></html>';

        return;

    case 'redirect':
        http_response_code(302);
        header('Location: /api/lice/elsewhere');

        return;

    case 'slow':
        // Longer than the timeout the tests give their client.
        sleep(4);

        break;

    case 'valid-with-500':
        // A body that says yes on a status that says the server failed.
        $json(500, ['valid' => true]);

        return;
}

$key     = (string) ($body['key'] ?? '');
$module  = (string) ($body['module'] ?? '');
$record  = $state['keys'][$key] ?? null;
$covered = is_array($record)
    && ($record['status'] ?? '') === 'active'
    && in_array($module, $record['modules'] ?? [], true);

$expiresOn = is_array($record) ? ($record['expires_on'] ?? null) : null;

// As the real endpoint: past its last day, a module answers like an unknown key.
if ($covered && $expiresOn !== null && $expiresOn < ($state['today'] ?? gmdate('Y-m-d'))) {
    $covered = false;
}

if ($key === '' || $module === '' || !$covered) {
    $json(403, ['valid' => false]);

    return;
}

$json(200, ['valid' => true, 'expires_at' => $expiresOn]);
