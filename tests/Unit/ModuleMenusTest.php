<?php
declare(strict_types=1);

use App_skeleton\ModuleManager;
use PHPUnit\Framework\TestCase;

require_once APP_PATH . '/common/library/ModuleManager.php';

/**
 * What the left-nav takeover, the dashboard tiles and the top-nav
 * application switcher are built from: each module's own menu, separately
 * (moduleMenus) and cut down to what one user may see (visibleModuleMenus).
 * In-process with stubbed discovery, like ModuleServicesAndRoutesTest: CI has
 * no module package installed. mergedMenu() is the one menu every module's own
 * sidebar copy renders, so the takeover is tested there.
 */
final class ModuleMenusTest extends TestCase
{
    /** @var string[] */
    private array $files = [];

    public static function setUpBeforeClass(): void
    {
        // The core menu asks Roles for ids, so this needs the ORM and the real database, like UserRolesTest.
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function menuFile(array $items): string
    {
        $path = tempnam(sys_get_temp_dir(), 'menu') . '.php';
        file_put_contents($path, '<?php return ' . var_export($items, true) . ';');
        $this->files[] = $path;

        return $path;
    }

    private function manager(array $manifests): ModuleManager
    {
        return new class ($manifests) extends ModuleManager {
            public function __construct(private array $stub)
            {
            }

            public function discover(): array
            {
                return $this->stub;
            }

            public function loadableModuleKeys(): array
            {
                return array_keys($this->stub);
            }
        };
    }

    private function item(string $label, string $url, ?array $roles = null): array
    {
        return ['label' => $label, 'icon' => 'fas fa-cube', 'controller' => basename($url), 'url' => $url, 'roles' => $roles];
    }

    private function manifest(string $key, string $tier, array $items, array $extra = []): array
    {
        $path = $this->menuFile($items);

        return ['key' => $key, 'tier' => $tier, 'installPath' => dirname($path), 'menu' => basename($path)] + $extra;
    }

    public function testEachModuleKeepsItsOwnMenuWithALabelAndIcon(): void
    {
        $mm = $this->manager([
            'lms'  => $this->manifest('lms', 'application', [$this->item('Courses', 'lms/courses'), $this->item('Units', 'lms/units')], ['code' => 'LMS']),
            'forum' => $this->manifest('forum', 'plugin', [$this->item('Events', 'forum/events')], ['label' => 'Community forum', 'icon' => 'fas fa-comments']),
        ]);

        $menus = $mm->moduleMenus('backend');

        self::assertSame(['lms', 'forum'], array_keys($menus));
        self::assertSame('LMS', $menus['lms']['label'], 'the manifest code is the label when there is no label');
        self::assertSame('Community forum', $menus['forum']['label']);
        self::assertSame('fas fa-comments', $menus['forum']['icon']);
        self::assertSame('fas fa-cube', $menus['lms']['icon'], 'the first menu item supplies the icon when the manifest has none');
        self::assertCount(2, $menus['lms']['items']);
    }

    public function testAModuleWithNoMenuOrOnTheOtherSurfaceIsLeftOut(): void
    {
        $noMenu = $this->manifest('quiet', 'application', [$this->item('X', 'quiet/x')]);
        unset($noMenu['menu']);

        $mm = $this->manager([
            'quiet'  => $noMenu,
            'empty'  => $this->manifest('empty', 'application', []),
            'front'  => $this->manifest('front', 'application', [$this->item('Home', 'front/home')], ['surface' => 'frontend']),
            'both'   => $this->manifest('both', 'application', [$this->item('Home', 'both/home')], ['surface' => 'both']),
            'tooly'  => $this->manifest('tooly', 'library', [$this->item('Nope', 'tooly/nope')]),
        ]);

        self::assertSame(['both'], array_keys($mm->moduleMenus('backend')));
        self::assertSame(['front', 'both'], array_keys($mm->moduleMenus('frontend')));
    }

    public function testAUserSeesOnlyTheItemsTheirRolesAllowAndAModuleWithNoneIsDropped(): void
    {
        $mm = $this->manager([
            'lms'    => $this->manifest('lms', 'application', [$this->item('Library', 'lms/library'), $this->item('Offerings', 'lms/offerings', [3])]),
            'secret' => $this->manifest('secret', 'application', [$this->item('Admin only', 'secret/admin', [1])]),
        ]);
        $member = new class () {
            public function hasAnyRole(array $roles): bool
            {
                return array_intersect($roles, [2]) !== [];
            }
        };
        $staff = new class () {
            public function hasAnyRole(array $roles): bool
            {
                return array_intersect($roles, [3]) !== [];
            }
        };

        $forMember = $mm->visibleModuleMenus('backend', $member);
        self::assertSame(['lms'], array_keys($forMember), 'a module with nothing visible does not appear');
        self::assertSame(['Library'], array_column($forMember['lms']['items'], 'label'));
        self::assertSame('lms/library', $forMember['lms']['url'], 'the module link lands on its first visible item');

        $forStaff = $mm->visibleModuleMenus('backend', $staff);
        self::assertSame(['Library', 'Offerings'], array_column($forStaff['lms']['items'], 'label'));
    }

    private function serving(ModuleManager $mm, string $moduleName): ModuleManager
    {
        $di = new \Phalcon\Di\Di();
        $di->setShared('dispatcher', fn () => new class ($moduleName) {
            public function __construct(private string $name)
            {
            }

            public function getModuleName(): string
            {
                return $this->name;
            }
        });
        $mm->setDI($di);

        return $mm;
    }

    private function twoModules(): ModuleManager
    {
        $lmsItems = [
            $this->item('My Learning', 'lms/learn') + ['group' => 'LMS'],
            $this->item('Courses', 'lms/courses', [3]) + ['group' => 'LMS'],
        ];

        return $this->manager([
            'lms'  => $this->manifest('lms', 'application', $lmsItems, ['code' => 'LMS']),
            'only' => $this->manifest('only', 'plugin', [$this->item('Staff only', 'only/x', [1]), $this->item('Staff too', 'only/y', [3])]),
        ]);
    }

    public function testOutsideAModuleTheMenuIsTheCoreMenuPlusAModulesList(): void
    {
        $menu = $this->serving($this->twoModules(), 'backend')->mergedMenu('backend');

        self::assertSame($this->twoModules()->coreMenu(), array_slice($menu, 0, count($this->twoModules()->coreMenu())), 'the core menu is untouched');

        $entries = array_values(array_filter($menu, fn ($item) => ($item['group'] ?? null) === 'Modules'));
        self::assertSame(['LMS', 'only'], array_column($entries, 'label'));
        self::assertSame('lms/learn', $entries[0]['url']);
        self::assertNull($entries[0]['roles'], 'open to everyone because one of its items is');
        self::assertSame([1, 3], $entries[1]['roles'], 'otherwise the union of its items\' roles');
        self::assertSame('only/x', $entries[1]['url']);
    }

    public function testInsideAModuleTheMenuIsTheDashboardPlusThatModulesOwnItems(): void
    {
        $menu = $this->serving($this->twoModules(), 'lms')->mergedMenu('backend');

        self::assertSame(['Dashboard', 'My Learning', 'Courses'], array_column($menu, 'label'));
        self::assertSame('index', $menu[0]['controller'], 'the Dashboard link is kept');
        self::assertArrayNotHasKey('group', $menu[1], 'a module whose items share one group is shown flat');
    }

    public function testAModuleThatKeepsSeveralGroupsKeepsThem(): void
    {
        $mm = $this->manager([
            'm' => $this->manifest('m', 'application', [
                $this->item('A', 'm/a') + ['group' => 'One'],
                $this->item('B', 'm/b') + ['group' => 'Two'],
            ]),
        ]);

        $menu = $this->serving($mm, 'm')->mergedMenu('backend');

        self::assertSame(['One', 'Two'], array_column(array_slice($menu, 1), 'group'));
    }

    public function testWithNoDispatcherTheMenuIsTheOrdinaryOne(): void
    {
        // CLI tasks and tests have no dispatcher: no takeover, no error.
        $menu = $this->twoModules()->mergedMenu('backend');

        self::assertNotEmpty(array_filter($menu, fn ($item) => ($item['group'] ?? null) === 'Modules'));
    }
}
