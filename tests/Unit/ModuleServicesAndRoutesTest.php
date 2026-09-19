<?php
declare(strict_types=1);

use App_skeleton\ModuleManager;
use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Application;
use Phalcon\Mvc\Router;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../fixtures/modules/probe-plugin/src/Module.php';
require_once __DIR__ . '/../fixtures/modules/probe-plugin/src/Controllers/ProbeController.php';
require_once __DIR__ . '/../fixtures/modules/headless-plugin/src/Module.php';
require_once __DIR__ . '/../fixtures/modules/unflagged-headless-plugin/src/Module.php';
require_once APP_PATH . '/common/library/ModuleManager.php';

/**
 * Two skeleton gaps a service-only ("headless") plugin exposes, first hit
 * by Profile Assurance in the game platform (MAA-20260919-004, R1/R2):
 *
 *   R2. Phalcon runs a module's registerServices() only for the module a
 *       request is dispatched to, so a plugin's services were invisible
 *       to every other module. The first test pins that framework
 *       behaviour; the rest cover ModuleManager::registerSharedServices().
 *   R1. Every enabled plugin got generic /<key>/... routes even with no
 *       controllers, so its URLs returned 500 instead of 404.
 *
 * In-process on purpose, unlike the Feature suite: CI and a public clone
 * have no module package installed, so there is no running stack that
 * could show this. The app below is real Phalcon (Application, Router,
 * Dispatcher) wired the way bootstrap_web.php wires it, including the
 * real app/config/routes.php; only module discovery is stubbed.
 */
final class ModuleServicesAndRoutesTest extends TestCase
{
    public function testAServiceRegisteredInRegisterServicesIsInvisibleToARequestHandledByAnotherModule(): void
    {
        $seen = $this->probe($this->buildApplication());

        self::assertFalse(
            $seen['dispatchOnly'],
            'registerServices() of a module that is not handling the request should not have run'
        );
    }

    public function testRegisterSharedServicesMakesAPluginServiceVisibleToAnotherModule(): void
    {
        $built = $this->buildApplication();

        self::assertFalse($this->probe($built)['shared'], 'nothing should be shared before the hook runs');

        $di  = $built[0]->getDI();
        $ran = $di->getShared('moduleManager')->registerSharedServices($di);

        self::assertSame(['fixture_headless'], $ran, 'only modules that define the hook should be reported');
        self::assertTrue($this->probe($built)['shared']);
    }

    public function testADisabledModulesSharedServicesAreNotRegistered(): void
    {
        $built = $this->buildApplication(['fixture_probe']);
        $di    = $built[0]->getDI();

        self::assertSame([], $di->getShared('moduleManager')->registerSharedServices($di));
        self::assertFalse($di->has('fixtureSharedService'));
    }

    public function testABrokenSharedServicesHookIsSkippedNotFatal(): void
    {
        $built = $this->buildApplication(null, [
            'fixture_broken' => [
                'key'       => 'fixture_broken',
                'tier'      => 'plugin',
                'className' => BrokenSharedServicesFixtureModule::class,
                'routes'    => false,
            ],
        ]);
        $di  = $built[0]->getDI();
        $log = ini_set('error_log', '/dev/null');

        try {
            $ran = $di->getShared('moduleManager')->registerSharedServices($di);
        } finally {
            ini_set('error_log', (string) $log);
        }

        self::assertSame(['fixture_headless'], $ran);
        self::assertTrue($di->has('fixtureSharedService'), 'a later module must still get its hook');
    }

    public function testAHeadlessPluginGetsNoGenericRoutes(): void
    {
        [, $router] = $this->buildApplication();

        foreach (['/fixture_headless', '/fixture_headless/index', '/fixture_headless/index/index'] as $uri) {
            $router->handle($uri);
            self::assertNotSame('fixture_headless', $router->getModuleName(), $uri . ' must not dispatch into the headless plugin');
        }

        $router->handle('/fixture_probe/probe');
        self::assertSame('fixture_probe', $router->getModuleName(), 'an ordinary plugin keeps its routes');
        self::assertSame('probe', $router->getControllerName());
    }

    public function testAPluginWithNoControllersDirectoryIsHeadlessEvenWithoutTheFlag(): void
    {
        [, $router] = $this->buildApplication();

        $router->handle('/fixture_unflagged/index/index');

        self::assertNotSame('fixture_unflagged', $router->getModuleName());
    }

    public function testTheManifestFlagWinsOverTheDirectoryCheck(): void
    {
        $moduleManager = new ModuleManager();
        $fixtures      = dirname(__DIR__) . '/fixtures/modules';

        self::assertTrue($moduleManager->hasGenericRoutes(['installPath' => $fixtures . '/probe-plugin']));
        self::assertFalse($moduleManager->hasGenericRoutes(['installPath' => $fixtures . '/probe-plugin', 'routes' => false]));
        self::assertFalse($moduleManager->hasGenericRoutes(['installPath' => $fixtures . '/headless-plugin']));
        self::assertTrue($moduleManager->hasGenericRoutes(['installPath' => $fixtures . '/headless-plugin', 'routes' => true]));
    }

    /**
     * @param string[]|null                        $enabled null = every fixture
     * @param array<string, array<string, mixed>>  $extra   manifests listed before the standard fixtures
     *
     * @return array{0: Application, 1: Router}
     */
    private function buildApplication(?array $enabled = null, array $extra = []): array
    {
        $di = new FactoryDefault();

        $moduleManager = new class ($enabled, $extra) extends ModuleManager {
            public function __construct(private ?array $enabledKeys, private array $extra)
            {
            }

            public function discover(): array
            {
                $fixtures = dirname(__DIR__) . '/fixtures/modules';

                return $this->extra + [
                    'fixture_probe' => [
                        'key'         => 'fixture_probe',
                        'tier'        => 'plugin',
                        'className'   => 'SkeletonFixtures\ProbePlugin\Module',
                        'installPath' => $fixtures . '/probe-plugin',
                    ],
                    'fixture_headless' => [
                        'key'         => 'fixture_headless',
                        'tier'        => 'plugin',
                        'className'   => 'SkeletonFixtures\HeadlessPlugin\Module',
                        'installPath' => $fixtures . '/headless-plugin',
                        'routes'      => false,
                    ],
                    'fixture_unflagged' => [
                        'key'         => 'fixture_unflagged',
                        'tier'        => 'plugin',
                        'className'   => 'SkeletonFixtures\UnflaggedHeadlessPlugin\Module',
                        'installPath' => $fixtures . '/unflagged-headless-plugin',
                    ],
                ];
            }

            protected function enabledModuleKeys(): array
            {
                return $this->enabledKeys ?? array_keys($this->discover());
            }
        };
        $moduleManager->setDI($di);
        $di->setShared('moduleManager', $moduleManager);

        // Same defaults as app/config/services_web.php's router service.
        $router = new Router();
        $router->setDefaultModule('fixture_probe');
        $router->setDefaultNamespace('SkeletonFixtures\ProbePlugin\Controllers');
        $router->setDefaultController('probe');
        $router->setDefaultAction('index');
        $router->setDI($di);
        $di->setShared('router', $router);

        $application = new Application($di);
        $application->useImplicitView(false);
        $application->registerModules($moduleManager->registeredPhalconModules());

        require APP_PATH . '/config/routes.php';

        return [$application, $router];
    }

    /**
     * @param array{0: Application, 1: Router} $built
     *
     * @return array{dispatchOnly: bool, shared: bool}
     */
    private function probe(array $built): array
    {
        $response = $built[0]->handle('/fixture_probe/probe');

        self::assertInstanceOf(\Phalcon\Http\ResponseInterface::class, $response);

        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}

final class BrokenSharedServicesFixtureModule
{
    public function registerSharedServices(\Phalcon\Di\DiInterface $di): void
    {
        throw new \RuntimeException('fixture: this hook always fails');
    }
}
