<?php
declare(strict_types=1);

use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Application;

error_reporting(E_ALL);

define('APP_START', microtime(true));
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

/**
 * Point PHP's own error_log at a project-local file instead of reimplementing
 * capture ourselves — PHP already logs warnings/notices/true fatals natively
 * (log_errors=On), the system default just points at a shared, noisy,
 * root-owned file mixing in every other project on the machine. This is set
 * before anything else runs, with no dependency on the DI/autoloader/db, so
 * it's still in effect for a crash caused by any of those.
 */
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/logs/app.log');

$logThrowable = function (\Throwable $e): string {
    $summary = sprintf('%s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());

    error_log($summary . "\n" . $e->getTraceAsString());

    return $summary;
};

$sendCrashResponse = function (string $summary) {
    http_response_code(500);

    $isApiRequest = preg_match('#^/api(/|$)#', $_SERVER['REQUEST_URI'] ?? '');
    $detail       = ini_get('display_errors') ? $summary : null;

    if ($isApiRequest) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Internal server error', 'detail' => $detail]);

        return;
    }

    echo '<h1>Something went wrong</h1>';
    echo '<p>The error has been logged. Please try again shortly.</p>';

    if ($detail) {
        echo '<pre>' . htmlspecialchars($detail) . '</pre>';
    }
};

set_exception_handler(function (\Throwable $e) use ($logThrowable, $sendCrashResponse): void {
    $sendCrashResponse($logThrowable($e));
});

register_shutdown_function(function () use ($logThrowable, $sendCrashResponse): void {
    $error = error_get_last();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $exception = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
        $sendCrashResponse($logThrowable($exception));
    }
});

try {
    /**
     * The FactoryDefault Dependency Injector automatically registers the services that
     * provide a full stack framework. These default services can be overidden with custom ones.
     */
    $di = new FactoryDefault();

    /**
     * Include general services
     */
    require APP_PATH . '/config/services.php';

    /**
     * Include web environment specific services
     */
    require APP_PATH . '/config/services_web.php';

    /**
     * Get config service for use in inline setup below
     */
    $config = $di->getConfig();

    /**
     * Include Autoloader
     */
    include APP_PATH . '/config/loader.php';

    /**
     * Debug mode is read once per request here (not inside services.php)
     * since it needs the db+models autoloader already registered. Wrapped
     * in try/catch so a fresh install (settings table not migrated yet)
     * doesn't crash the whole request — debug bar just stays off.
     */
    try {
        \App_skeleton\Debug::configure($di->getShared('settings')->get('debug_mode', '0') === '1');
    } catch (\Throwable $e) {
        \App_skeleton\Debug::configure(false);
    }

    /**
     * Handle the request
     */
    $application = new Application($di);

    /**
     * Register application modules
     */
    $application->registerModules([
        'api'     => ['className' => 'App_skeleton\Modules\Api\Module'],
        'backend' => ['className' => 'App_skeleton\Modules\Backend\Module'],
    ]);

    /**
     * Include routes
     */
    require APP_PATH . '/config/routes.php';

    echo $application->handle($_SERVER['REQUEST_URI'])->getContent();
} catch (\Throwable $e) {
    $sendCrashResponse($logThrowable($e));
}
