<?php

use Phalcon\Autoload\Loader;

$loader = new Loader();

/**
 * Register Namespaces
 */
$loader->setNamespaces([
    'App_skeleton\Models' => APP_PATH . '/common/models/',
    'App_skeleton'        => APP_PATH . '/common/library/',
]);

/**
 * Register module classes
 */
$loader->setClasses([
    'App_skeleton\Modules\Api\Module'      => APP_PATH . '/modules/api/Module.php',
    'App_skeleton\Modules\Backend\Module'  => APP_PATH . '/modules/backend/Module.php',
    'App_skeleton\Modules\Cli\Module'      => APP_PATH . '/modules/cli/Module.php',
    'App_skeleton\Modules\Frontend\Module' => APP_PATH . '/modules/frontend/Module.php',
]);

/**
 * Models (Users, Roles, Items, ...) are generated as bare/global classes with no
 * namespace, so they can't be matched by setNamespaces(). Register their directories
 * directly so autoloading works regardless of which module dispatches the request.
 */
$loader->setDirectories([
    APP_PATH . '/modules/backend/models/',
    APP_PATH . '/modules/api/models/',
]);

$loader->register();
