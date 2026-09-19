<?php
declare(strict_types=1);

namespace App_skeleton;

use Phalcon\Di\DiInterface;
use Phalcon\Di\Injectable;

/**
 * Discovers Composer-installed module packages (see
 * docs/module-system-design-brief.md) by scanning for a module.json
 * manifest at each installed package's root, and cross-references the
 * module_registry table for enable/disable state — Composer's own
 * installed.json says what's physically present, not what an admin has
 * turned on for this instance.
 */
class ModuleManager extends Injectable
{
    private const MANIFEST_FILENAME = 'module.json';

    private const REQUIRED_FIELDS = ['key', 'tier'];

    /**
     * Tiers that get real Phalcon module registration (own route
     * namespace) and a menu contribution — everything except the
     * top-nav application switcher, which stays 'application'-only (see
     * module-system-design-brief.md "v1.2 direction"). Plugin-tier
     * modules are reachable via the left-nav "Modules >" list alongside
     * application-tier ones; they just never appear in the top-nav
     * switcher.
     */
    private const ROUTABLE_TIERS = ['application', 'plugin'];

    private ?array $discovered = null;

    /**
     * All installed module packages with a valid module.json, keyed by
     * module key. Pure filesystem/JSON, no DB access — safe to call before
     * migrations have run (MigrateTask itself depends on this).
     */
    public function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $this->discovered = [];

        if (!class_exists(\Composer\InstalledVersions::class)) {
            return $this->discovered;
        }

        foreach (\Composer\InstalledVersions::getInstalledPackages() as $packageName) {
            $installPath = \Composer\InstalledVersions::getInstallPath($packageName);

            if ($installPath === null) {
                continue;
            }

            $installPath  = rtrim($installPath, '/\\');
            $manifestPath = $installPath . '/' . self::MANIFEST_FILENAME;

            if (!is_file($manifestPath)) {
                continue;
            }

            $manifest = $this->parseManifest($manifestPath, $packageName);

            if ($manifest === null) {
                continue;
            }

            $manifest['packageName'] = $packageName;
            $manifest['installPath'] = $installPath;

            try {
                $manifest['version'] = \Composer\InstalledVersions::getPrettyVersion($packageName);
            } catch (\Throwable $e) {
                $manifest['version'] = null;
            }

            $this->discovered[$manifest['key']] = $manifest;
        }

        return $this->discovered;
    }

    /**
     * Application- and plugin-tier modules that are both discovered and
     * enabled in module_registry, in the ['key' => ['className' => ...]]
     * shape Phalcon\Mvc\Application::registerModules() expects. Both
     * tiers get a real route namespace — see ROUTABLE_TIERS — unless the
     * module is headless (hasGenericRoutes()), which app/config/routes.php
     * reads back from the extra 'routes' key (Phalcon keeps unknown keys
     * and hands them back from getModules()).
     */
    public function registeredPhalconModules(): array
    {
        $enabled = $this->enabledModuleKeys();
        $modules = [];

        foreach ($this->discover() as $key => $manifest) {
            if (!in_array($manifest['tier'], self::ROUTABLE_TIERS, true) || !in_array($key, $enabled, true)) {
                continue;
            }

            $modules[$key] = [
                'className' => $manifest['className'],
                'routes'    => $this->hasGenericRoutes($manifest),
            ];
        }

        return $modules;
    }

    /**
     * Whether a module gets the generic /<key>/:controller/:action routes.
     * A headless (service-only) plugin has no controllers and registers no
     * 'view', so those routes used to dispatch into it and crash with
     * "Service 'view' is not registered" — a 500 on a URL that should
     * simply not exist. Explicit "routes": true|false in module.json
     * wins; when the field is absent, a module with no controllers
     * directory is treated as headless, so forgetting the flag fails
     * safe (404) rather than loud (500).
     */
    public function hasGenericRoutes(array $manifest): bool
    {
        if (array_key_exists('routes', $manifest)) {
            return $manifest['routes'] !== false;
        }

        $installPath = $manifest['installPath'] ?? null;

        if (!is_string($installPath)) {
            return true;
        }

        foreach (['src/controllers', 'src/Controllers', 'controllers', 'Controllers'] as $dir) {
            if (is_dir($installPath . '/' . $dir)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Runs every enabled module's optional registerSharedServices($di),
     * on every web and CLI request, before dispatch. Phalcon itself only
     * calls a module's registerServices() for the ONE module a request is
     * dispatched to, so anything registered there (a service another
     * module is meant to consume, an eventsBus listener) does not exist
     * during any other module's requests. registerServices() stays the
     * place for per-module things like 'view'; this hook is for what the
     * rest of the app must be able to see.
     *
     * One module's broken hook is logged and skipped rather than taking
     * every request on the instance down with it, same tolerance as
     * discovery itself.
     *
     * @return string[] keys of the modules whose hook ran
     */
    public function registerSharedServices(DiInterface $di): array
    {
        $enabled = $this->enabledModuleKeys();
        $ran     = [];

        foreach ($this->discover() as $key => $manifest) {
            if (!in_array($manifest['tier'], self::ROUTABLE_TIERS, true) || !in_array($key, $enabled, true)) {
                continue;
            }

            $className = $manifest['className'] ?? null;

            if (!is_string($className) || !method_exists($className, 'registerSharedServices')) {
                continue;
            }

            try {
                (new $className())->registerSharedServices($di);
                $ran[] = $key;
            } catch (\Throwable $e) {
                error_log("ModuleManager: {$key}'s registerSharedServices() failed, skipping: " . $e->getMessage());
            }
        }

        return $ran;
    }

    /**
     * Backend sidebar menu: the built-in menu.php contribution followed by
     * each enabled application- or plugin-tier module's own menu file, in
     * the same {label, icon, controller, url, roles} shape sidenav.phtml
     * already expects. $surface matches a manifest's declared 'surface'
     * ('backend', 'frontend', or 'both').
     */
    public function mergedMenu(string $surface): array
    {
        $menu    = include APP_PATH . '/modules/backend/config/menu.php';
        $enabled = $this->enabledModuleKeys();

        foreach ($this->discover() as $key => $manifest) {
            if (!in_array($manifest['tier'], self::ROUTABLE_TIERS, true) || !in_array($key, $enabled, true)) {
                continue;
            }

            $moduleSurface = $manifest['surface'] ?? 'backend';

            if ($moduleSurface !== $surface && $moduleSurface !== 'both') {
                continue;
            }

            if (empty($manifest['menu'])) {
                continue;
            }

            $menuPath = $manifest['installPath'] . '/' . ltrim($manifest['menu'], '/\\');

            if (!is_file($menuPath)) {
                continue;
            }

            $moduleMenu = include $menuPath;

            if (is_array($moduleMenu)) {
                $menu = array_merge($menu, $moduleMenu);
            }
        }

        return $menu;
    }

    private function parseManifest(string $path, string $packageName): ?array
    {
        $raw = json_decode((string) file_get_contents($path), true);

        if (!is_array($raw)) {
            error_log("ModuleManager: {$packageName} has an unparseable module.json, skipping");

            return null;
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (empty($raw[$field])) {
                error_log("ModuleManager: {$packageName}'s module.json is missing '{$field}', skipping");

                return null;
            }
        }

        if (in_array($raw['tier'], self::ROUTABLE_TIERS, true) && empty($raw['className'])) {
            error_log("ModuleManager: {$packageName} is a {$raw['tier']}-tier module but declares no className, skipping");

            return null;
        }

        return $raw;
    }

    /**
     * Other discovered modules that $key's own composer.json 'require'
     * section names as dependencies -- the sanctioned "bundle" signal
     * (Travis, 2026-09-13: "the application is supposed to be using the
     * plugins, i.e. install application mod, and it automatically
     * installs chat, phone, email"). Deliberately not a separate
     * module.json field: every module is already a real Composer
     * package, so the dependency is expressed exactly once, in the one
     * place Composer itself already reads it. Reads the installed
     * package's own composer.json directly (InstalledVersions doesn't
     * expose a package's own requires) rather than adding a second API
     * surface for the same data.
     */
    public function bundledModuleKeys(string $key): array
    {
        $manifest = $this->discover()[$key] ?? null;

        if ($manifest === null) {
            return [];
        }

        $composerPath = $manifest['installPath'] . '/composer.json';

        if (!is_file($composerPath)) {
            return [];
        }

        $composer = json_decode((string) file_get_contents($composerPath), true);
        $requires = is_array($composer['require'] ?? null) ? array_keys($composer['require']) : [];

        $bundled = [];

        foreach ($this->discover() as $otherKey => $otherManifest) {
            if ($otherKey !== $key && in_array($otherManifest['packageName'], $requires, true)) {
                $bundled[] = $otherKey;
            }
        }

        return $bundled;
    }

    /**
     * The one sanctioned path to enable a module — also enables whatever
     * it bundles (bundledModuleKeys()), so ModulesTask (CLI) and
     * ConfigurationController (web) both get this behavior from one
     * place rather than one of them risking a bypass by writing
     * module_registry.enabled directly. Deliberately does NOT cascade
     * the other direction: disabling the application module doesn't
     * disable its plugins, since a plugin is meant to keep working
     * standalone even after the module that originally brought it in is
     * turned off.
     *
     * @return string[] Every module_key actually flipped to enabled
     *                   (the requested one first, then any bundled ones)
     *                   — callers use this to report what happened.
     *                   A key not yet registered (needs 'modules sync'
     *                   first) is silently skipped, not an error, same
     *                   as the pre-existing single-module enable did.
     */
    public function enableModule(string $key): array
    {
        $changed = [];

        if ($this->setModuleEnabled($key)) {
            $changed[] = $key;
        }

        foreach ($this->bundledModuleKeys($key) as $bundledKey) {
            if ($this->setModuleEnabled($bundledKey)) {
                $changed[] = $bundledKey;
            }
        }

        return $changed;
    }

    private function setModuleEnabled(string $key): bool
    {
        $row = \ModuleRegistry::findFirst([
            'conditions' => 'module_key = :key:',
            'bind'       => ['key' => $key],
        ]);

        // instanceof rather than a bare falsy check: findFirst()'s declared
        // return type is ModelInterface|Row|null, and an interface has no
        // properties to write to (Psalm NoInterfaceProperties). The older
        // ConfigurationController does the same thing and is only green
        // because it sits in psalm-baseline.xml — narrow properly here
        // rather than growing that baseline.
        if (!$row instanceof \ModuleRegistry) {
            return false;
        }

        $row->enabled    = true;
        $row->updated_at = date('Y-m-d H:i:s');

        return (bool) $row->save();
    }

    /**
     * module_key values with enabled=true in module_registry. Returns []
     * rather than throwing if the table doesn't exist yet, so discovery
     * stays safe on a fresh install before migrations have run.
     * Protected, not private, so tests can stub enable state without a
     * database (tests/Unit/ModuleServicesAndRoutesTest.php).
     */
    protected function enabledModuleKeys(): array
    {
        try {
            $rows = \ModuleRegistry::find(['conditions' => 'enabled = true']);
        } catch (\Throwable $e) {
            return [];
        }

        $keys = [];

        foreach ($rows as $row) {
            $keys[] = $row->module_key;
        }

        return $keys;
    }
}
