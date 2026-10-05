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
use App_skeleton\CurrentPrincipal;
use App_skeleton\LicenseManager;
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

    // A request that presents an API key is stateless: its session is
    // never started, so it gets no cookie, reads no identity from a
    // session cookie sent alongside the key, and has nothing to write one
    // to (set() on an unstarted session does nothing). A controller that
    // copies the key's user into the session hands back a PHPSESSID that
    // opens the backend as that user with no key at all; refusing here,
    // in the service, means no controller in this repo or in a module can
    // do that.
    if ($this->has('request') && $this->getShared('apiKeyAuth')->tokenFromRequest($this->getShared('request')) !== null) {
        return $session;
    }

    // PHP's defaults send the session cookie bare: "PHPSESSID=…; path=/",
    // with no HttpOnly, Secure or SameSite attribute (seen on the live
    // instance 2026-10-05). HttpOnly keeps page script away from it,
    // SameSite=Lax keeps it off cross-site POSTs, and Secure keeps it off
    // plain HTTP. Secure is only set when this request arrived over HTTPS
    // (directly, or as the reverse proxy reports it), so a local
    // http://localhost install can still log in.
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on'
            || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $session->start();
    return $session;
});

/**
 * Shared configuration service
 */
$di->setShared('config', function () {
    return include APP_PATH . '/config/config.php';
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

    // Sqlite's PDO DSN is just a file path (dbname above) — no
    // host/port/credentials to add. Every other adapter needs them.
    // This used to be `if adapter === 'Postgresql'`, which silently
    // dropped host/username/password for Mysql too — Mysql would build
    // a DSN with no host or credentials at all and fail to connect.
    // See docs/user-guide.md's install notes for the MySQL/SQLite path.
    if ($config->database->adapter !== 'Sqlite') {
        $params['host']     = $config->database->host;
        $params['port']     = $config->database->port;
        $params['username'] = $config->database->username;
        $params['password'] = $config->database->password;
    }

    if ($config->database->adapter === 'Postgresql') {
        // libpq's default gssencmode=prefer probes system Kerberos config on
        // every new connection, which crashes (SIGSEGV in CFPreferences, a
        // known macOS bug) the first time it runs inside a freshly-forked
        // PHP dev-server worker. Not needed anyway — nothing here uses
        // Kerberos auth. See project_app_skeleton_postgres_gssapi_crash memory.
        $params['gssencmode'] = 'disable';
    }

    if ($config->database->adapter === 'Sqlite') {
        // Postgres schemas as ATTACHed files, PG function shims and the
        // ILIKE/::cast translator. SQLite only; see App_skeleton\Db\SqliteAdapter.
        $class = \App_skeleton\Db\SqliteAdapter::class;

        if (isset($config->database->schemas)) {
            $params['schemas'] = $config->database->schemas->toArray();
        }
    }

    if ($config->database->adapter === 'Mysql') {
        // MySQL 8 / MariaDB: utf8mb4 + fixed session settings and the
        // ILIKE/::cast translator; see App_skeleton\Db\MysqlAdapter.
        // `socket` connects over a Unix socket instead of host/port (common
        // on local installs; shared hosts usually use host 'localhost').
        $class = \App_skeleton\Db\MysqlAdapter::class;

        if (!empty($config->database->socket)) {
            unset($params['host'], $params['port']);
            $params['unix_socket'] = $config->database->socket;
        }

        // The base config's default port is Postgres's; a MySQL
        // config.local.php that doesn't set one means MySQL's.
        if (isset($params['port']) && (int) $params['port'] === 5432) {
            $params['port'] = 3306;
        }

        if (empty($params['port'])) {
            unset($params['port']);
        }

        foreach (['charset', 'collation'] as $key) {
            if (!empty($config->database->$key)) {
                $params[$key] = $config->database->$key;
            }
        }
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
 * The acting user for this request, set by Auth (browser session) or
 * ApiKeyAuth (API key) and read by Audit — see App_skeleton\CurrentPrincipal.
 */
$di->setShared('currentPrincipal', function () {
    $currentPrincipal = new CurrentPrincipal();
    $currentPrincipal->setDI($this);

    return $currentPrincipal;
});

/**
 * API-key authentication (Authorization: Bearer / X-Api-Key header ->
 * users row) — see the api module's ControllerBase::onConstruct().
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
 * Licence state of installed paid modules, and the check-in against the
 * licence server — see App_skeleton\LicenseManager and docs/MODULE-SPEC.md
 * (Licensing). On web and CLI alike: a module asks
 * $di->getShared('licenseManager')->isLicensed('<its key>').
 */
$di->setShared('licenseManager', function () {
    $licenseManager = new LicenseManager();
    $licenseManager->setDI($this);

    return $licenseManager;
});

/**
 * Shared event bus for modules to react to each other without direct
 * coupling (e.g. 'payment:completed', 'user:created') — modelled on how
 * the audit listener below is attached to the models manager, but for
 * app-level events rather than model lifecycle ones. Modules attach their
 * own listeners inside their own Module::registerSharedServices($di) —
 * not registerServices(), which only runs while that module is itself
 * handling the request (docs/MODULE-SPEC.md, Shared services). Event
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
        'path' => function ($templatePath) use ($config) {
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
                @mkdir($cacheDir . DIRECTORY_SEPARATOR . 'volt', 0755, true);
            }

            return $cacheDir . DIRECTORY_SEPARATOR . 'volt' . DIRECTORY_SEPARATOR . $filename;
        },
    ]);

    return $volt;
});
