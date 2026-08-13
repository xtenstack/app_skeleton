<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

use Phalcon\Mvc\Application;
use Phalcon\Mvc\Router;

/**
 * Usage: ./run routes list
 *
 * Lists concrete, dispatchable endpoints (one row per real
 * controller/action), not the generic `:controller/:action/:params`
 * pattern rules the router is actually built from (session request,
 * 2026-08-13 — the raw pattern dump read as "?::1::?"-style placeholders,
 * not useful for "what routes does this app actually have"). Built by
 * reflecting every *Controller class found under each routable module's
 * controllers directory for public *Action methods, converting
 * StudlyCase segments to the kebab-case the router's default
 * uncamelize-on-dispatch behavior actually expects (confirmed against
 * real existing routes: ExternalConnectionsController -> external-
 * connections, AuditLogController -> audit-log, ApiKeysController ->
 * api-keys). Trait-provided actions (e.g. TicketTriageActions mixed
 * into TicketsController) resolve correctly without special-casing --
 * PHP reflection reports a trait method's declaring class as the
 * consuming class, not the trait itself.
 *
 * One known simplification: index actions render as e.g. `/backend/
 * index` rather than the bare root alias some of them also answer to
 * (frontend/index specifically is also reachable at literal `/`,
 * hardcoded in routes.php) -- both paths work, this lists the
 * always-true one.
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
        // the comment there). Not actually used for output here (see
        // above), but routes.php still expects a $router in scope.
        $router = new Router();
        $router->setDefaultModule('api');

        $application = new Application($di);

        $builtInModules = [
            'api'      => ['className' => 'App_skeleton\Modules\Api\Module'],
            'backend'  => ['className' => 'App_skeleton\Modules\Backend\Module'],
            'frontend' => ['className' => 'App_skeleton\Modules\Frontend\Module'],
        ];

        try {
            $manifests         = $di->getShared('moduleManager')->discover();
            $discoveredModules = $di->getShared('moduleManager')->registeredPhalconModules();
        } catch (\Throwable $e) {
            $manifests         = [];
            $discoveredModules = [];
        }

        $allModules = $builtInModules + $discoveredModules;
        $application->registerModules($allModules);

        require APP_PATH . '/config/routes.php';

        // registerModules() alone doesn't run each module's own
        // registerAutoloaders() -- that normally happens during a real
        // handle() call, which this task deliberately never makes (see
        // the original docblock's own reasoning, preserved above).
        // Reflection below needs the classes actually loadable, so wire
        // autoloaders up explicitly here instead.
        foreach ($allModules as $module) {
            if (!empty($module['className']) && method_exists($module['className'], 'registerAutoloaders')) {
                (new $module['className']())->registerAutoloaders($di);
            }
        }

        $rows = $this->concreteEndpoints($manifests);

        usort($rows, fn ($a, $b) => $a[0] <=> $b[0]);

        $widths = [0, 0];
        foreach ($rows as $row) {
            $widths[0] = max($widths[0], strlen($row[0]));
        }

        foreach ($rows as $row) {
            echo str_pad($row[0], $widths[0] + 2) . $row[1] . PHP_EOL;
        }

        echo PHP_EOL . count($rows) . ' endpoint(s).' . PHP_EOL;
    }

    /**
     * @param array<string, array<string, mixed>> $manifests
     * @return array<int, array{0: string, 1: string}>
     */
    private function concreteEndpoints(array $manifests): array
    {
        $rows = [
            ...$this->endpointsFromDir('api', APP_PATH . '/modules/api/controllers', 'App_skeleton\Modules\Api\Controllers'),
            ...$this->endpointsFromDir('backend', APP_PATH . '/modules/backend/controllers', 'App_skeleton\Modules\Backend\Controllers'),
            ...$this->endpointsFromDir('frontend', APP_PATH . '/modules/frontend/controllers', 'App_skeleton\Modules\Frontend\Controllers'),
        ];

        // Discovered feature modules (e.g. requirements-module) — same
        // src/controllers/ layout docs/MODULE-SPEC.md documents as the
        // reference shape.
        foreach ($manifests as $moduleKey => $manifest) {
            if (($manifest['tier'] ?? null) !== 'application' || empty($manifest['className']) || empty($manifest['installPath'])) {
                continue;
            }

            $dir       = rtrim((string) $manifest['installPath'], '/\\') . '/src/controllers';
            $namespace = preg_replace('/Module$/', 'Controllers', (string) $manifest['className']);

            $rows = [...$rows, ...$this->endpointsFromDir((string) $moduleKey, $dir, $namespace)];
        }

        return $rows;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function endpointsFromDir(string $moduleKey, string $dir, string $namespace): array
    {
        $rows = [];

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $baseName = basename($file, '.php');

            // ControllerBase and trait files (TicketAttachmentActions,
            // TicketTriageActions, ApiControllerBase...) don't end in
            // "Controller" -- naturally excluded, no extra filter needed.
            if (!str_ends_with($baseName, 'Controller')) {
                continue;
            }

            $class = $namespace . '\\' . $baseName;

            if (!class_exists($class)) {
                continue;
            }

            $controllerSegment = $this->kebab(preg_replace('/Controller$/', '', $baseName));
            $reflection        = new \ReflectionClass($class);

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->class !== $class || !str_ends_with($method->getName(), 'Action')) {
                    continue;
                }

                $actionName    = preg_replace('/Action$/', '', $method->getName());
                $actionSegment = $actionName === 'index' ? '' : $this->kebab($actionName);

                $path = '/' . $moduleKey . '/' . $controllerSegment;

                if ($actionSegment !== '') {
                    $path .= '/' . $actionSegment;
                }

                foreach ($method->getParameters() as $param) {
                    $path .= '/{' . $param->getName() . '}';
                }

                $rows[] = [$path, $class . '::' . $method->getName() . '()'];
            }
        }

        return $rows;
    }

    /**
     * StudlyCase -> kebab-case, matching Phalcon's own default
     * uncamelize-on-dispatch segment resolution (confirmed against real
     * existing routes — see class docblock).
     */
    private function kebab(string $studly): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $studly));
    }
}
