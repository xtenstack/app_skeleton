<?php
/*
 * Modified: prepend directory path of current file, because of this file own different ENV under between Apache and command line.
 * NOTE: please remove this comment.
 */
defined('BASE_PATH') || define('BASE_PATH', getenv('BASE_PATH') ?: realpath(dirname(__FILE__) . '/../..'));
defined('APP_PATH') || define('APP_PATH', BASE_PATH . '/app');

/**
 * Base config, safe to commit — no secrets. Per-environment values (DB
 * password, anything else machine-specific) live in the gitignored
 * config.local.php, merged in below if present. See db/migrations/ for
 * schema changes — this file only says how to connect, not what's in it.
 */
$base = [
    'version' => '1.0',

    'database' => [
        'adapter'  => 'Postgresql',
        'host'     => 'localhost',
        'port'     => 5432,
        'dbname'   => 'app_skeleton',
        'username' => 'app_skeleton',
        'password' => '',
    ],

    /**
     * Resend's HTTP API is the only outbound-mail transport that works
     * from DigitalOcean droplets — every SMTP port (25/465/587) is
     * blocked regardless of credentials, confirmed on both stack-dev and
     * stack-prod. Set mail.resend_api_key in config.local.php per
     * environment (same mechanism as the DB password above); Mailer.php
     * logs and no-ops rather than failing the request if it's empty.
     * resend_webhook_secret is the whsec_... signing secret Resend hands
     * back when the bounce/complaint webhook endpoint is created
     * (WebhookController::resendAction()) — empty means that controller
     * refuses all webhook traffic rather than trusting an unsigned
     * request.
     */
    'mail' => [
        'resend_api_key'       => '',
        'resend_webhook_secret' => '',
    ],

    /**
     * Where paid modules' licence keys are checked (see
     * App_skeleton\LicenseManager). Empty means XTen's licence server,
     * LicenseCheckinClient::DEFAULT_SERVER_URL. Override the base URL in
     * config.local.php only to point at another licence server; it must
     * be https:// (plain http is accepted for localhost alone, for tests).
     */
    'licensing' => [
        'server_url' => '',
    ],

    'application' => [
        'appDir'         => APP_PATH . '/',
        'modelsDir'      => APP_PATH . '/common/models/',
        'migrationsDir'  => APP_PATH . '/migrations/',
        'cacheDir'       => BASE_PATH . '/cache/',
        'baseUri'        => '/',
    ],

    /**
     * Session files (BASE_PATH/sessions) not touched for this many seconds
     * are deleted by the daily "Session clean-up" cron job (./run session
     * gc, see App_skeleton\SessionGc). A session is rewritten on every
     * request that uses it, so this is how long someone can stay away
     * before they have to log in again. PHP's own garbage collection is
     * off in the Docker image (session.gc_probability = 0) and is not
     * relied on.
     */
    'session' => [
        'lifetime' => 7 * 24 * 60 * 60,
    ],

    /**
     * if true, then we print a new line at the end of each CLI execution
     *
     * If we dont print a new line,
     * then the next command prompt will be placed directly on the left of the output
     * and it is less readable.
     *
     * You can disable this behaviour if the output of your application needs to don't have a new line at end
     */
    'printNewLine' => true,
];

// APP_CONFIG_LOCAL points at an alternative local config (e.g. a throwaway
// SQLite one for tests) without touching the real config.local.php.
$localConfigFile = getenv('APP_CONFIG_LOCAL') ?: __DIR__ . '/config.local.php';
$local = is_file($localConfigFile) ? (array) include $localConfigFile : [];

return new \Phalcon\Config\Config(array_replace_recursive($base, $local));
