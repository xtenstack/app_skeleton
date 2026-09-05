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
     */
    'mail' => [
        'resend_api_key' => '',
    ],

    'application' => [
        'appDir'         => APP_PATH . '/',
        'modelsDir'      => APP_PATH . '/common/models/',
        'migrationsDir'  => APP_PATH . '/migrations/',
        'cacheDir'       => BASE_PATH . '/cache/',
        'baseUri'        => '/',
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

$localConfigFile = __DIR__ . '/config.local.php';
$local = is_file($localConfigFile) ? (array) include $localConfigFile : [];

return new \Phalcon\Config\Config(array_replace_recursive($base, $local));
