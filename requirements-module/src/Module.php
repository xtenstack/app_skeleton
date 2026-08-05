<?php
declare(strict_types=1);

namespace XtenRequirements;

use Phalcon\Di\DiInterface;
use Phalcon\Autoload\Loader;
use Phalcon\Mvc\View;
use Phalcon\Mvc\View\Engine\Php as PhpEngine;
use Phalcon\Mvc\ModuleDefinitionInterface;

/**
 * Application-tier module (see ModuleManager/module.json) — routed under
 * /requirements/... by app/config/routes.php once enabled. Everything
 * else it needs (db, session, auth, flash, moduleManager, audit...) comes
 * from the shared DI services app/config/services*.php already register
 * globally; this only needs to add its own controllers/models to the
 * autoloader and point the view at its own templates.
 */
class Module implements ModuleDefinitionInterface
{
    public function registerAutoloaders(?DiInterface $di = null)
    {
        $loader = new Loader();

        $loader->setNamespaces([
            'XtenRequirements\Controllers' => __DIR__ . '/controllers/',
        ]);

        // Requirement/Changelog are bare/global classes (see src/models/),
        // matching how app/config/loader.php loads the built-in modules'
        // own models — can't be matched by setNamespaces().
        $loader->setDirectories([
            __DIR__ . '/models/',
        ]);

        $loader->register();
    }

    public function registerServices(DiInterface $di)
    {
        $di['view'] = function () {
            $view = new View();
            $view->setViewsDir(__DIR__ . '/../views/');

            $view->registerEngines([
                '.volt'  => 'voltShared',
                '.phtml' => PhpEngine::class,
            ]);

            return $view;
        };
    }
}
