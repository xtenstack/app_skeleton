<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api;

use Phalcon\Di\DiInterface;
use Phalcon\Autoload\Loader;
use Phalcon\Mvc\ModuleDefinitionInterface;
use Phalcon\Mvc\View;

class Module implements ModuleDefinitionInterface
{
    /**
     * Registers an autoloader related to the module
     *
     * @param DiInterface $di
     */
    public function registerAutoloaders(DiInterface $di = null)
    {
        $loader = new Loader();

        $loader->setNamespaces([
            'App_skeleton\Modules\Api\Controllers' => __DIR__ . '/controllers/',
        ]);

        $loader->register();
    }

    /**
     * Registers services related to the module. This module is JSON-only,
     * but Phalcon\Mvc\Application unconditionally fetches the 'view' service
     * after dispatch to render — so it still needs to exist, just disabled.
     *
     * @param DiInterface $di
     */
    public function registerServices(DiInterface $di)
    {
        $di->set('view', function () {
            $view = new View();
            $view->disable();

            return $view;
        });
    }
}
