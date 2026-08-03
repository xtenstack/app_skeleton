<?php

// $router may already be set by the including scope (e.g. RoutesTask, which
// builds its own Router rather than going through the CLI DI's 'router'
// service — Phalcon\Di\FactoryDefault\Cli's getRouter() doesn't honor a
// setShared('router', ...) override once a Cli\Console dispatch is active).
$router = $router ?? $di->getRouter();

$router->add('/', [
    'namespace'  => 'App_skeleton\Modules\Frontend\Controllers',
    'module'     => 'frontend',
    'controller' => 'index',
    'action'     => 'index',
]);

foreach ($application->getModules() as $key => $module) {
    $namespace = preg_replace('/Module$/', 'Controllers', $module['className']);

    $router->add('/'.$key.'/:params', [
        'namespace' => $namespace,
        'module' => $key,
        'controller' => 'index',
        'action' => 'index',
        'params' => 1
    ])->setName($key);

    $router->add('/'.$key.'/:controller/:params', [
        'namespace' => $namespace,
        'module' => $key,
        'controller' => 1,
        'action' => 'index',
        'params' => 2
    ]);

    $router->add('/'.$key.'/:controller/:action/:params', [
        'namespace' => $namespace,
        'module' => $key,
        'controller' => 1,
        'action' => 2,
        'params' => 3
    ]);

    /**
     * Optional extension point: a module can define its own
     * registerRoutes(Router $router) to add routes beyond the generic
     * pattern above (e.g. a public certificate-validation URL with no
     * :controller/:action shape). No effect on modules that don't
     * implement it.
     */
    if (method_exists($module['className'], 'registerRoutes')) {
        (new $module['className']())->registerRoutes($router);
    }
}
