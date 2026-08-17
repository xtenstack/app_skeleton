<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli;

use Phalcon\Di\DiInterface;
use Phalcon\Autoload\Loader;
use Phalcon\Mvc\ModuleDefinitionInterface;

class Module implements ModuleDefinitionInterface
{
    /**
     * Registers an autoloader related to the module
     *
     * @param DiInterface $di
     *
     * @return void
     */
    public function registerAutoloaders(?DiInterface $di = null)
    {
        $loader = new Loader();

        $loader->setNamespaces([
            'App_skeleton\Modules\Cli\Tasks' => __DIR__ . '/tasks/',
        ]);

        $loader->register();
    }

    /**
     * Registers services related to the module
     *
     * @param DiInterface $di
     *
     * @return void
     */
    public function registerServices(DiInterface $di)
    {
    }
}
