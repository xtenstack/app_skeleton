<?php
declare(strict_types=1);

use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Application;

error_reporting(E_ALL);

define('APP_START', microtime(true));
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

/**
 * Composer-installed module packages (see App_skeleton\ModuleManager) are
 * resolved via Composer's own PSR-4 autoload, not our hand-rolled loader.
 * Guarded so a checkout that hasn't run `composer install` yet still boots
 * with just the built-in api/backend modules.
 */
if (file_exists(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
}

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

$sendCrashResponse = function (string $summary): void {
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
     * Register application modules — the two built-in ones plus whatever
     * Composer-installed module packages are discovered and enabled (see
     * App_skeleton\ModuleManager). Wrapped the same way the debug_mode
     * lookup above is: a fresh install (module_registry not migrated yet)
     * or an unreachable DB just falls back to the built-in modules only,
     * rather than crashing the whole request.
     */
    $builtInModules = [
        'api'      => ['className' => 'App_skeleton\Modules\Api\Module'],
        'backend'  => ['className' => 'App_skeleton\Modules\Backend\Module'],
        'frontend' => ['className' => 'App_skeleton\Modules\Frontend\Module'],
    ];

    try {
        $discoveredModules = $di->getShared('moduleManager')->registeredPhalconModules();
    } catch (\Throwable $e) {
        $discoveredModules = [];
    }

    $application->registerModules($builtInModules + $discoveredModules);

    /**
     * Include routes
     */
    require APP_PATH . '/config/routes.php';

    /**
     * Admin-settable maintenance mode (REQ-172). Checked once per request,
     * here rather than as a dispatcher event, so it applies uniformly
     * before ANY module's services are even registered — no risk of a
     * cross-module dispatcher forward leaving the wrong module's view/
     * services state loaded. Read the same DB-tolerant way debug_mode is
     * read above: a fresh/not-yet-migrated install just behaves as if
     * maintenance mode is off instead of crashing every request.
     *
     * Non-admin traffic gets silently rerouted to the frontend module's
     * own maintenance view (reusing its styling, per the spec) by
     * overriding the URI fed to Application::handle() — the module system
     * then boots frontend/IndexController normally, exactly as if the
     * visitor had requested that URL directly. The method is forced to
     * GET alongside it: a maintenance-page visit must never trip frontend
     * ControllerBase's CSRF check against whatever method the original,
     * now-abandoned request used (e.g. a guest's in-flight POST at the
     * moment maintenance mode flips on).
     *
     * Exemptions — both required for an admin to actually turn maintenance
     * back off: the login controller itself (backend/session/*, so an
     * admin can authenticate while maintenance is on), and any request
     * from an already-authenticated admin (role_id 1) — once logged in an
     * admin gets the whole site as normal, not just the toggle screen, so
     * they can confirm the fix before flipping maintenance back off. API
     * traffic has no session-based "admin" concept (API-key auth is
     * per-endpoint, not global), so it's treated as non-admin uniformly
     * and gets a JSON 503 instead of the HTML view.
     */
    $requestUri = $_SERVER['REQUEST_URI'];

    try {
        if ($di->getShared('settings')->get('maintenance_mode', '0') === '1') {
            $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

            $isLoginRoute = (bool) preg_match('#^/backend/session(/|$)#', $path);
            $isApiRequest = (bool) preg_match('#^/api(/|$)#', $path);

            $isAdmin = false;

            if ($di->getShared('auth')->isLoggedIn()) {
                $isAdmin = ((int) ($di->getShared('session')->get('auth')['role_id'] ?? 0)) === 1;
            }

            if (!$isLoginRoute && !$isAdmin) {
                if ($isApiRequest) {
                    http_response_code(503);
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode(['error' => 'Service temporarily unavailable for maintenance']);

                    exit;
                }

                $requestUri                = '/frontend/index/maintenance';
                $_SERVER['REQUEST_METHOD'] = 'GET';
            }
        }
    } catch (\Throwable $e) {
        // settings table not migrated yet, or DB unreachable — fall back
        // to "maintenance mode off", same as the debug_mode read above.
    }

    echo $application->handle($requestUri)->getContent();
} catch (\Throwable $e) {
    $sendCrashResponse($logThrowable($e));
}
