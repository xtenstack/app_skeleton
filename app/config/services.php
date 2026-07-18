<?php
declare(strict_types=1);

use Phalcon\Events\Manager as EventsManager;
use Phalcon\Mvc\Model\Manager as ModelsManager;
use Phalcon\Mvc\Model\Metadata\Memory as MetaDataAdapter;
use Phalcon\Mvc\View\Engine\Volt as VoltEngine;
use Phalcon\Session\Manager as SessionManager;
use Phalcon\Session\Adapter\Stream as SessionStream;
use App_skeleton\Audit;
use App_skeleton\Auth;

$di->setShared('session', function () {
    $session = new SessionManager();
    $files = new SessionStream(['savePath' => sys_get_temp_dir()]);
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
 * Database connection is created based in the parameters defined in the configuration file
 */
$di->setShared('db', function () {
    $config = $this->getConfig();

    $class = 'Phalcon\Db\Adapter\Pdo\\' . $config->database->adapter;
    $params = [
        'dbname'   => $config->database->dbname,
    ];

    if ($config->database->adapter == 'Postgresql') {
        unset($params['charset']);
    }

    return new $class($params);
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
