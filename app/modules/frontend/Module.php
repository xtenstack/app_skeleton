<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Frontend;

use Phalcon\Di\DiInterface;
use Phalcon\Autoload\Loader;
use Phalcon\Mvc\View;
use Phalcon\Mvc\View\Engine\Php as PhpEngine;
use Phalcon\Mvc\ModuleDefinitionInterface;
use Phalcon\Config\Config;

/**
 * Public-facing side of the app: the guest landing page and the
 * non-admin member dashboard (REQ-020/031) — separate from `backend`'s
 * AdminLTE admin UI on purpose, same "application-defining core module"
 * status as `backend`/`api`/`cli` (always on, never listed on the
 * Configuration page). See docs/user-guide.md.
 */
class Module implements ModuleDefinitionInterface
{
    /**
     * @return void
     */
    public function registerAutoloaders(?DiInterface $di = null)
    {
        $loader = new Loader();

        $loader->setNamespaces([
            'App_skeleton\Modules\Frontend\Controllers' => __DIR__ . '/controllers/',
        ]);

        $loader->register();
    }

    /**
     * @return void
     */
    public function registerServices(DiInterface $di)
    {
        if (file_exists(__DIR__ . '/config/config.php')) {
            $config   = $di['config'];
            $override = new Config(include __DIR__ . '/config/config.php');

            if ($config instanceof Config) {
                $config->merge($override);
            } else {
                $config = $override;
            }
        }

        $di['view'] = function (): \Phalcon\Mvc\View {
            $config = $this->getConfig();

            $view = new View();
            $view->setViewsDir($config->get('application')->viewsDir);

            $view->registerEngines([
                '.volt'  => 'voltShared',
                '.phtml' => PhpEngine::class,
            ]);

            return $view;
        };
    }
}
