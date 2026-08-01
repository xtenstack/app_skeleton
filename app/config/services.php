<?php
declare(strict_types=1);

use Phalcon\Events\Manager as EventsManager;
use Phalcon\Mvc\Model\Manager as ModelsManager;
use Phalcon\Mvc\Model\Metadata\Memory as MetaDataAdapter;
use Phalcon\Mvc\View\Engine\Volt as VoltEngine;
use Phalcon\Session\Manager as SessionManager;
use Phalcon\Session\Adapter\Stream as SessionStream;
use App_skeleton\ApiKeyAuth;
use App_skeleton\Audit;
use App_skeleton\Auth;
use App_skeleton\CronRunner;
use App_skeleton\Mailer;
use App_skeleton\ModuleManager;
use App_skeleton\SettingsRegistry;

$di->setShared('session', function () {
    $session = new SessionManager();
    // Not sys_get_temp_dir() (was, until 2026-08-01) — that's the
    // container's own ephemeral filesystem, wiped on every recreate
    // (every `docker compose up -d --build`), silently logging everyone
    // out and invalidating any in-flight CSRF token on every deploy. See
    // BASE_PATH . '/sessions' bind-mounted in docker-compose.yml, same
    // reasoning as logs/public/files.
    $files = new SessionStream(['savePath' => BASE_PATH . '/sessions']);
    $session->setAdapter($files);
    $session->start();
    return $session;
});

/**
 * Shared configuration service
 */
$di->setShared('config', function () {
    return include APP_PATH . "/config/config.php";
});

/**
 * Query profiler for the debug bar — attached unconditionally (cheap: it
 * just records SQL + elapsed time per query) so debug_mode can be toggled
 * without needing to rebuild the db service. Only *displayed* when
 * debug_mode is on and the viewer is an admin (see index.phtml).
 */
$di->setShared('dbProfiler', function () {
    return new \Phalcon\Db\Profiler();
});

/**
 * Database connection is created based in the parameters defined in the configuration file
 */
$di->setShared('db', function () {
    $config = $this->getConfig();

    $class  = 'Phalcon\Db\Adapter\Pdo\\' . $config->database->adapter;
    $params = ['dbname' => $config->database->dbname];

    if ($config->database->adapter === 'Postgresql') {
        $params['host']     = $config->database->host;
        $params['port']     = $config->database->port;
        $params['username'] = $config->database->username;
        $params['password'] = $config->database->password;

        // libpq's default gssencmode=prefer probes system Kerberos config on
        // every new connection, which crashes (SIGSEGV in CFPreferences, a
        // known macOS bug) the first time it runs inside a freshly-forked
        // PHP dev-server worker. Not needed anyway — nothing here uses
        // Kerberos auth. See project_app_skeleton_postgres_gssapi_crash memory.
        $params['gssencmode'] = 'disable';
    }

    $connection = new $class($params);

    $profiler = $this->getShared('dbProfiler');
    $dbEventsManager = new EventsManager();
    $dbEventsManager->attach('db:beforeQuery', function ($event, $connection) use ($profiler) {
        $profiler->startProfile($connection->getSQLStatement());
    });
    $dbEventsManager->attach('db:afterQuery', function ($event, $connection) use ($profiler) {
        $profiler->stopProfile();
    });
    $connection->setEventsManager($dbEventsManager);

    return $connection;
});

/**
 * If the configuration specify the use of metadata adapter use it or use memory otherwise
 */
$di->setShared('modelsMetadata', function () {
    return new MetaDataAdapter();
});

/**
 * Auth service, shared so login state set by one module's SessionController
 * is visible to every other module via the same session.
 */
$di->setShared('auth', function () {
    $auth = new Auth();
    $auth->setDI($this);

    return $auth;
});

/**
 * Global app settings (the `settings` table), read-cached per request.
 * $this->settings->get('key', $default) / ->set('key', $value) from any
 * controller or view.
 */
$di->setShared('settings', function () {
    $settings = new SettingsRegistry();
    $settings->setDI($this);

    return $settings;
});

/**
 * API-key authentication (Authorization: Bearer / X-Api-Key header ->
 * users row), for api module controllers to fall back to when there's no
 * logged-in session — see ControllerBase::onConstruct().
 */
$di->setShared('apiKeyAuth', function () {
    $apiKeyAuth = new ApiKeyAuth();
    $apiKeyAuth->setDI($this);

    return $apiKeyAuth;
});

/**
 * Runs due cron_jobs — shared by the CLI runner and the manual "Run now"
 * button so both execute identical logic.
 */
$di->setShared('cronRunner', function () {
    $runner = new CronRunner();
    $runner->setDI($this);

    return $runner;
});

/**
 * Sends signup verification / password reset emails via PHP's native
 * mail() — see App_skeleton\Mailer for why no SMTP client.
 */
$di->setShared('mailer', function () {
    $mailer = new Mailer();
    $mailer->setDI($this);

    return $mailer;
});

/**
 * Audit listener, attached to the models manager below so any model that
 * opts into keepSnapshots(true) gets its inserts/updates/deletes logged to
 * audit_log automatically.
 */
$di->setShared('audit', function () {
    $audit = new Audit();
    $audit->setDI($this);

    return $audit;
});

/**
 * Discovers Composer-installed module packages and their module_registry
 * enable/disable state — see App_skeleton\ModuleManager. Used by
 * bootstrap_web.php to build the registerModules() array, by routes.php's
 * registerRoutes() hook, by sidenav.phtml/IndexController for the merged
 * menu, and by MigrateTask/ModulesTask from the CLI.
 */
$di->setShared('moduleManager', function () {
    $moduleManager = new ModuleManager();
    $moduleManager->setDI($this);

    return $moduleManager;
});

/**
 * Shared event bus for modules to react to each other without direct
 * coupling (e.g. 'payment:completed', 'user:created') — modelled on how
 * the audit listener below is attached to the models manager, but for
 * app-level events rather than model lifecycle ones. Modules attach their
 * own listeners inside their own Module::registerServices($di). Event
 * names are colon-namespaced ('type:event'), matching the db:beforeQuery /
 * db:afterQuery convention already used for the db service above and
 * Phalcon's own EventsManager wildcard-attach semantics.
 */
$di->setShared('eventsBus', function () {
    return new EventsManager();
});

/**
 * Override the default modelsManager to wire up the audit events listener
 * for every model, rather than each model having to attach it itself.
 */
$di->setShared('modelsManager', function () {
    $eventsManager = new EventsManager();
    $eventsManager->attach('model', $this->getShared('audit'));

    $modelsManager = new ModelsManager();
    $modelsManager->setEventsManager($eventsManager);

    return $modelsManager;
});

/**
 * Configure the Volt service for rendering .volt templates
 */
$di->setShared('voltShared', function ($view) {
    $config = $this->getConfig();

    $volt = new VoltEngine($view, $this);
    $volt->setOptions([
        'path' => function($templatePath) use ($config) {
            $basePath = $config->application->appDir;
            if ($basePath && substr($basePath, 0, 2) == '..') {
                $basePath = dirname(__DIR__);
            }

            $basePath = realpath($basePath);
            $templatePath = trim(substr($templatePath, strlen($basePath)), '\\/');

            $filename = basename(str_replace(['\\', '/'], '_', $templatePath), '.volt') . '.php';

            $cacheDir = $config->application->cacheDir;
            if ($cacheDir && substr($cacheDir, 0, 2) == '..') {
                $cacheDir = __DIR__ . DIRECTORY_SEPARATOR . $cacheDir;
            }

            $cacheDir = realpath($cacheDir);

            if (!$cacheDir) {
                $cacheDir = sys_get_temp_dir();
            }

            if (!is_dir($cacheDir . DIRECTORY_SEPARATOR . 'volt')) {
                @mkdir($cacheDir . DIRECTORY_SEPARATOR . 'volt' , 0755, true);
            }

            return $cacheDir . DIRECTORY_SEPARATOR . 'volt' . DIRECTORY_SEPARATOR . $filename;
        }
    ]);

    return $volt;
});
