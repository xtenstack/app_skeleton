<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api;

use Phalcon\Di\DiInterface;
use Phalcon\Autoload\Loader;
use Phalcon\Mvc\ModuleDefinitionInterface;
use Phalcon\Mvc\Router;
use Phalcon\Mvc\View;

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
     *
     * @return void
     */
    public function registerServices(DiInterface $di)
    {
        $di->set('view', function () {
            $view = new View();
            $view->disable();

            return $view;
        });
    }

    /**
     * Extension point app/config/routes.php calls for every module
     * (`if (method_exists($module['className'], 'registerRoutes'))`).
     * Used here the same way XTen.Marketing's Module::registerRoutes()
     * does for PublicLeadCaptureController — a clean, public URL with no
     * auth requirement, which the generic `/api/:controller/:action`
     * shape this module otherwise uses can't provide on its own (every
     * other controller in this module goes through ControllerBase's
     * principal requirement).
     */
    public function registerRoutes(Router $router)
    {
        $router->add('/api/intake/data-restore-audit', [
            'namespace'  => 'App_skeleton\Modules\Api\Controllers',
            'module'     => 'api',
            'controller' => 'public-intake',
            'action'     => 'dataRestoreAudit',
        ])->via(['POST', 'OPTIONS']);

        // REQ-225: public FAQ feed — same registerRoutes() extension point,
        // same reasoning as the intake route above (a clean public URL the
        // generic /api/:controller/:action shape can't itself express as
        // "no auth required", since every other controller in this module
        // goes through ControllerBase's principal requirement).
        $router->add('/api/public-kb/faq', [
            'namespace'  => 'App_skeleton\Modules\Api\Controllers',
            'module'     => 'api',
            'controller' => 'public-kb',
            'action'     => 'faq',
        ])->via(['GET', 'OPTIONS']);

        // MAA-20261027-008: provisioning API. Nested ids (users/{id}/api-key)
        // and the hyphenated api-keys path are not what the generic
        // /api/:controller/:action shape expresses, so they are routed here.
        // Every one still goes through ControllerBase's principal check, and
        // each action gates the admin role itself.
        $provision = ['namespace' => 'App_skeleton\Modules\Api\Controllers', 'module' => 'api', 'controller' => 'provision'];

        $router->add('/api/provision/roles', $provision + ['action' => 'roles'])->via(['GET']);
        $router->add('/api/provision/users', $provision + ['action' => 'users'])->via(['POST']);
        $router->add('/api/provision/users/([0-9]+)/api-key', $provision + ['action' => 'issueKey', 'params' => 1])->via(['POST']);
        $router->add('/api/provision/users/([0-9]+)/deactivate', $provision + ['action' => 'deactivate', 'params' => 1])->via(['POST']);
        $router->add('/api/provision/api-keys/rotate', $provision + ['action' => 'rotate'])->via(['POST']);
        $router->add('/api/provision/api-keys/confirm', $provision + ['action' => 'confirm'])->via(['POST']);
    }
}
