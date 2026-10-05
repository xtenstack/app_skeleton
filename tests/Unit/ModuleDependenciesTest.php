<?php
declare(strict_types=1);

use App_skeleton\ModuleDependencyException;
use App_skeleton\ModuleManager;
use Phalcon\Di\FactoryDefault;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * module.json 'dependsOn' (docs/MODULE-SPEC.md, Dependencies): a module
 * can't be enabled until everything it declares is installed and enabled,
 * disabling a module takes its dependents with it, and a module_registry
 * that is already inconsistent (a hand-edited row, a removed package)
 * holds the dependent back for the request instead of letting it run
 * against something that isn't there.
 *
 * Same "real thing" rule as SoftDeleteTest: the real ModuleManager reads
 * real module.json files (written to a temp directory per test, since CI
 * and a public clone have no module package installed) and the real
 * module_registry table through the real ModuleRegistry model. Only the
 * list of installed Composer packages is stubbed. Every fixture key
 * starts 'depfix_' and its row is removed again in tearDown().
 */
final class ModuleDependenciesTest extends TestCase
{
    private string $packagesDir;

    private string $errorLog;

    private string $previousErrorLog;

    public static function setUpBeforeClass(): void
    {
        $di = new FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';

        \Phalcon\Di\Di::setDefault($di);
    }

    protected function setUp(): void
    {
        $this->packagesDir = sys_get_temp_dir() . '/app_skeleton_depfix_' . bin2hex(random_bytes(4));
        mkdir($this->packagesDir);

        $this->errorLog         = $this->packagesDir . '/error.log';
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);

        foreach (\ModuleRegistry::find(['conditions' => "module_key LIKE 'depfix_%'"]) as $row) {
            $row->delete();
        }

        foreach (glob($this->packagesDir . '/*/*') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($this->packagesDir . '/*') ?: [] as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }

        rmdir($this->packagesDir);
    }

    public function testEnableIsRefusedWhileADependencyIsDisabledOrNotInstalled(): void
    {
        $moduleManager = $this->moduleManager([
            'depfix_acc' => [],
            'depfix_ap'  => ['depfix_acc', 'depfix_ledger'],
        ]);
        $this->register(['depfix_acc' => false, 'depfix_ap' => false]);

        try {
            $moduleManager->enableModule('depfix_ap');
            self::fail('enabling a module above a disabled dependency should have been refused');
        } catch (ModuleDependencyException $e) {
            self::assertStringContainsString('depfix_acc (not enabled)', $e->getMessage());
            self::assertStringContainsString('depfix_ledger (not installed)', $e->getMessage());
        }

        self::assertSame([], $this->enabledFixtureKeys(), 'a refused enable must not change module_registry');
    }

    public function testEnableIsAllowedOnceEveryDependencyIsEnabled(): void
    {
        $moduleManager = $this->moduleManager([
            'depfix_acc' => [],
            'depfix_ap'  => ['depfix_acc'],
        ]);
        $this->register(['depfix_acc' => false, 'depfix_ap' => false]);

        self::assertSame(['depfix_acc'], $moduleManager->enableModule('depfix_acc'));
        self::assertSame(['depfix_ap'], $moduleManager->enableModule('depfix_ap'));
        self::assertSame(['depfix_acc', 'depfix_ap'], $this->enabledFixtureKeys());
    }

    public function testDisableCascadesThroughATransitiveChain(): void
    {
        $moduleManager = $this->moduleManager([
            'depfix_acc'   => [],
            'depfix_ap'    => ['depfix_acc'],
            'depfix_pay'   => ['depfix_ap'],
            'depfix_other' => [],
        ]);
        $this->register(['depfix_acc' => true, 'depfix_ap' => true, 'depfix_pay' => true, 'depfix_other' => true]);

        self::assertSame(['depfix_ap', 'depfix_pay'], $moduleManager->enabledDependentsOf('depfix_acc'));
        self::assertSame(['depfix_acc', 'depfix_ap', 'depfix_pay'], $moduleManager->disableModule('depfix_acc'));
        self::assertSame(['depfix_other'], $this->enabledFixtureKeys());

        self::assertSame(['depfix_acc'], $moduleManager->enableModule('depfix_acc'));
        self::assertSame(
            ['depfix_acc', 'depfix_other'],
            $this->enabledFixtureKeys(),
            're-enabling a dependency must not bring back what was cascaded off it'
        );
    }

    public function testDisablingADependentLeavesItsDependenciesEnabled(): void
    {
        $moduleManager = $this->moduleManager([
            'depfix_acc' => [],
            'depfix_ap'  => ['depfix_acc'],
        ]);
        $this->register(['depfix_acc' => true, 'depfix_ap' => true]);

        self::assertSame(['depfix_ap'], $moduleManager->disableModule('depfix_ap'));
        self::assertSame(['depfix_acc'], $this->enabledFixtureKeys());
    }

    public function testDisablingAnUnregisteredKeyChangesNothing(): void
    {
        $moduleManager = $this->moduleManager([
            'depfix_acc' => [],
            'depfix_ap'  => ['depfix_acc'],
        ]);
        $this->register(['depfix_ap' => true]);

        self::assertSame([], $moduleManager->disableModule('depfix_acc'));
        self::assertSame(['depfix_ap'], $this->enabledFixtureKeys());
    }

    public function testADependsOnCycleIsAManifestErrorNotALoop(): void
    {
        $moduleManager = $this->moduleManager([
            'depfix_a'    => ['depfix_b'],
            'depfix_b'    => ['depfix_c'],
            'depfix_c'    => ['depfix_a'],
            'depfix_self' => ['depfix_self'],
            'depfix_solo' => [],
        ]);
        $this->register([
            'depfix_a'    => true,
            'depfix_b'    => true,
            'depfix_c'    => true,
            'depfix_self' => true,
            'depfix_solo' => true,
        ]);

        foreach (['depfix_a', 'depfix_b', 'depfix_c', 'depfix_self'] as $key) {
            self::assertStringContainsString('cycle', (string) $moduleManager->manifestError($key), $key);
        }

        self::assertStringContainsString('depfix_a -> depfix_b -> depfix_c -> depfix_a', (string) $moduleManager->manifestError('depfix_a'));
        self::assertNull($moduleManager->manifestError('depfix_solo'));
        self::assertSame(['depfix_solo'], $moduleManager->loadableModuleKeys());

        $this->expectException(ModuleDependencyException::class);
        $this->expectExceptionMessage('cycle');
        $moduleManager->enableModule('depfix_a');
    }

    #[DataProvider('malformedDependsOn')]
    public function testAMalformedDependsOnIsAnErrorForThatModuleOnly(mixed $dependsOn): void
    {
        $moduleManager = $this->moduleManager([
            'depfix_bad' => $dependsOn,
            'depfix_ok'  => [],
        ]);
        $this->register(['depfix_bad' => true, 'depfix_ok' => true]);

        self::assertSame(['depfix_bad', 'depfix_ok'], array_keys($moduleManager->discover()), 'the module must stay discovered');
        self::assertStringContainsString('dependsOn', (string) $moduleManager->manifestError('depfix_bad'));
        self::assertNull($moduleManager->manifestError('depfix_ok'));
        self::assertSame(['depfix_ok'], $moduleManager->loadableModuleKeys());
        self::assertStringContainsString('depfix/depfix_bad', (string) file_get_contents($this->errorLog));

        $this->expectException(ModuleDependencyException::class);
        $this->expectExceptionMessage('dependsOn');
        $moduleManager->enableModule('depfix_bad');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedDependsOn(): array
    {
        return [
            'a bare string'           => ['depfix_ok'],
            'a non-string entry'      => [['depfix_ok', 7]],
            'an empty key'            => [['']],
            'a key => constraint map' => [['depfix_ok' => '^1.0']],
            'objects'                 => [[['key' => 'depfix_ok', 'version' => '^1.0']]],
        ];
    }

    public function testAModuleEnabledAboveAMissingDependencyIsNotLoadedAndIsLoggedOnce(): void
    {
        $moduleManager = $this->moduleManager([
            'depfix_acc'    => [],
            'depfix_ap'     => ['depfix_acc'],
            'depfix_pay'    => ['depfix_ap'],
            'depfix_orphan' => ['depfix_gone'],
            'depfix_solo'   => [],
        ]);
        // What a hand-edited row or a removed package leaves behind:
        // enableModule()/disableModule() would never produce this.
        $this->register([
            'depfix_acc'    => false,
            'depfix_ap'     => true,
            'depfix_pay'    => true,
            'depfix_orphan' => true,
            'depfix_solo'   => true,
        ]);

        self::assertSame(['depfix_solo'], $moduleManager->loadableModuleKeys());
        self::assertSame(['depfix_solo'], array_keys($moduleManager->registeredPhalconModules()));
        self::assertSame(['depfix_solo'], $moduleManager->registerSharedServices(new FactoryDefault()));

        self::assertSame(['depfix_acc' => 'not enabled'], $moduleManager->unmetDependencies('depfix_ap'));
        self::assertSame(['depfix_ap' => 'not loaded'], $moduleManager->unmetDependencies('depfix_pay'));
        self::assertSame(['depfix_gone' => 'not installed'], $moduleManager->unmetDependencies('depfix_orphan'));

        $log = (string) file_get_contents($this->errorLog);

        foreach (['depfix_ap', 'depfix_pay', 'depfix_orphan'] as $key) {
            self::assertSame(1, substr_count($log, "{$key} is enabled but was not loaded"), $key . ' should be logged exactly once');
        }

        self::assertStringNotContainsString('depfix_solo', $log);
    }

    public function testDependenciesAreLoadedBeforeTheirDependents(): void
    {
        // Discovered dependents-first, the opposite of the order needed.
        $moduleManager = $this->moduleManager([
            'depfix_pay'  => ['depfix_ap'],
            'depfix_solo' => [],
            'depfix_ap'   => ['depfix_acc'],
            'depfix_acc'  => [],
        ]);
        $this->register(['depfix_pay' => true, 'depfix_solo' => true, 'depfix_ap' => true, 'depfix_acc' => true]);

        $expected = ['depfix_acc', 'depfix_ap', 'depfix_pay', 'depfix_solo'];

        self::assertSame($expected, $moduleManager->loadableModuleKeys());
        self::assertSame($expected, array_keys($moduleManager->registeredPhalconModules()));
        self::assertSame($expected, $moduleManager->registerSharedServices(new FactoryDefault()));
        self::assertSame('', (string) @file_get_contents($this->errorLog), 'a consistent registry has nothing to log');
    }

    public function testABundledModuleWithAnUnmetDependencyIsLeftDisabled(): void
    {
        $moduleManager = $this->moduleManager(
            [
                'depfix_app'   => [],
                'depfix_extra' => ['depfix_gone'],
                'depfix_chat'  => [],
            ],
            ['depfix_app' => ['depfix_extra', 'depfix_chat']]
        );
        $this->register(['depfix_app' => false, 'depfix_extra' => false, 'depfix_chat' => false]);

        self::assertSame(['depfix_app', 'depfix_chat'], $moduleManager->enableModule('depfix_app'));
        self::assertSame(['depfix_app', 'depfix_chat'], $this->enabledFixtureKeys());
    }

    /**
     * Writes one fixture package per module — a real module.json, plus a
     * composer.json when the module bundles others — and returns a real
     * ModuleManager that discovers exactly those packages.
     *
     * @param array<string, mixed>    $dependsOn module key => its 'dependsOn', written as given
     * @param array<string, string[]> $bundles   module key => keys of the modules its composer.json requires
     */
    private function moduleManager(array $dependsOn, array $bundles = []): ModuleManager
    {
        $packages = [];

        foreach ($dependsOn as $key => $declared) {
            $dir = $this->packagesDir . '/' . $key;
            mkdir($dir);

            file_put_contents($dir . '/module.json', json_encode([
                'key'       => $key,
                'tier'      => 'plugin',
                'className' => DependencyFixtureModule::class,
                'routes'    => false,
                'dependsOn' => $declared,
            ], JSON_THROW_ON_ERROR));

            if (isset($bundles[$key])) {
                file_put_contents($dir . '/composer.json', json_encode([
                    'require' => array_fill_keys(array_map(fn (string $bundled) => 'depfix/' . $bundled, $bundles[$key]), '*'),
                ], JSON_THROW_ON_ERROR));
            }

            $packages['depfix/' . $key] = $dir;
        }

        return new class ($packages) extends ModuleManager {
            /**
             * @param array<string, string> $packages
             */
            public function __construct(private array $packages)
            {
            }

            protected function installedPackages(): array
            {
                return $this->packages;
            }
        };
    }

    /**
     * @param array<string, bool> $enabledByKey
     */
    private function register(array $enabledByKey): void
    {
        foreach ($enabledByKey as $key => $enabled) {
            $row                = new \ModuleRegistry();
            $row->module_key    = $key;
            $row->tier          = 'plugin';
            $row->enabled       = $enabled;
            $row->discovered_at = date('Y-m-d H:i:s');
            $row->updated_at    = date('Y-m-d H:i:s');

            self::assertTrue($row->save(), 'fixture module_registry row failed to save: ' . implode('; ', $row->getMessages()));
        }
    }

    /**
     * @return string[]
     */
    private function enabledFixtureKeys(): array
    {
        $keys = [];

        foreach (\ModuleRegistry::find(['conditions' => "module_key LIKE 'depfix_%' AND enabled = true", 'order' => 'module_key']) as $row) {
            $keys[] = $row->module_key;
        }

        return $keys;
    }
}

final class DependencyFixtureModule
{
    public function registerSharedServices(\Phalcon\Di\DiInterface $di): void
    {
    }
}
