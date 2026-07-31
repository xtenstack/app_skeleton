<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

use Phalcon\Mvc\Application;
use Phalcon\Mvc\Router;

/**
 * Usage: ./run routes list
 *
 * The router is built at request time from app/config/routes.php (see the
 * per-module :controller/:action pattern registered there), not cached
 * anywhere — so listing it means reconstructing the same module
 * registration + routes.php include that bootstrap_web.php does per
 * request, minus the actual handle() call.
 */
class RoutesTask extends \Phalcon\Cli\Task
{
    public function mainAction()
    {
        echo 'Usage: ./run routes list' . PHP_EOL;
    }

    public function listAction()
    {
        $di = $this->getDI();

        // Built directly rather than via $di->setShared('router', ...) —
        // Phalcon\Di\FactoryDefault\Cli's getRouter() doesn't honor a
        // service override once a Cli\Console dispatch is under way, so
        // this local instance is handed to routes.php below instead (see
        // the comment there).
        $router = new Router();
        $router->setDefaultModule('api');

        $application = new Application($di);

        $builtInModules = [
            'api'     => ['className' => 'App_skeleton\Modules\Api\Module'],
            'backend' => ['className' => 'App_skeleton\Modules\Backend\Module'],
        ];

        try {
            $discoveredModules = $di->getShared('moduleManager')->registeredPhalconModules();
        } catch (\Throwable $e) {
            $discoveredModules = [];
        }

        $application->registerModules($builtInModules + $discoveredModules);

        require APP_PATH . '/config/routes.php';

        $rows = [];

        foreach ($router->getRoutes() as $route) {
            $paths  = $route->getPaths();
            $target = ($paths['module'] ?? '?') . '::'
                . ($paths['controller'] ?? '?') . '::'
                . ($paths['action'] ?? '?');

            $methods = $route->getHttpMethods();
            $methods = $methods ? implode('|', (array) $methods) : 'ANY';

            $rows[] = [$methods, $route->getPattern(), $target, $route->getName() ?: ''];
        }

        $widths = [7, 0, 0, 0];
        foreach ($rows as $row) {
            foreach ($row as $i => $col) {
                $widths[$i] = max($widths[$i], strlen((string) $col));
            }
        }

        foreach ($rows as $row) {
            $line = '';
            foreach ($row as $i => $col) {
                $line .= str_pad((string) $col, $widths[$i] + 2);
            }
            echo rtrim($line) . PHP_EOL;
        }

        echo PHP_EOL . count($rows) . ' route(s).' . PHP_EOL;
    }
}
