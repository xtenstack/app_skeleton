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

    /** @var string[]|null */
    private ?array $dependencyOrder = null;

    /** @var array<string, string> module key => why its manifest can't be honoured */
    private array $manifestErrors = [];

    /** @var array<string, true> */
    private array $reportedNotLoaded = [];

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

        foreach ($this->installedPackages() as $packageName => $installPath) {
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
     * Installed Composer packages as [package name => install path].
     * Protected, not private, so tests can point discovery at fixture
     * packages with real module.json files — CI and a public clone have
     * no module package installed.
     *
     * @return array<string, string>
     */
    protected function installedPackages(): array
    {
        if (!class_exists(\Composer\InstalledVersions::class)) {
            return [];
        }

        $packages = [];

        foreach (\Composer\InstalledVersions::getInstalledPackages() as $packageName) {
            $installPath = \Composer\InstalledVersions::getInstallPath($packageName);

            if ($installPath !== null) {
                $packages[$packageName] = $installPath;
            }
        }

        return $packages;
    }

    /**
     * Application- and plugin-tier modules that are discovered, enabled in
     * module_registry and loadable (loadableModuleKeys()), dependencies
     * first, in the ['key' => ['className' => ...]] shape
     * Phalcon\Mvc\Application::registerModules() expects. Both tiers get
     * a real route namespace — see ROUTABLE_TIERS — unless the module is
     * headless (hasGenericRoutes()), which app/config/routes.php reads
     * back from the extra 'routes' key (Phalcon keeps unknown keys and
     * hands them back from getModules()).
     */
    public function registeredPhalconModules(): array
    {
        $manifests = $this->discover();
        $modules   = [];

        foreach ($this->loadableModuleKeys() as $key) {
            $manifest = $manifests[$key];

            if (!in_array($manifest['tier'], self::ROUTABLE_TIERS, true)) {
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
     * Runs every loaded module's optional registerSharedServices($di),
     * on every web and CLI request, before dispatch, dependencies before
     * the modules that declare them (so a service a dependency registers
     * exists by the time its dependent's hook runs). Phalcon itself only
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
        $manifests = $this->discover();
        $ran       = [];

        foreach ($this->loadableModuleKeys() as $key) {
            $manifest = $manifests[$key];

            if (!in_array($manifest['tier'], self::ROUTABLE_TIERS, true)) {
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
     * each loaded application- or plugin-tier module's own menu file, in
     * the same {label, icon, controller, url, roles} shape sidenav.phtml
     * already expects. $surface matches a manifest's declared 'surface'
     * ('backend', 'frontend', or 'both').
     */
    public function mergedMenu(string $surface): array
    {
        $menu      = include APP_PATH . '/modules/backend/config/menu.php';
        $manifests = $this->discover();

        foreach ($this->loadableModuleKeys() as $key) {
            $manifest = $manifests[$key];

            if (!in_array($manifest['tier'], self::ROUTABLE_TIERS, true)) {
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

        $dependsOn = $raw['dependsOn'] ?? [];

        $isListOfKeys = is_array($dependsOn)
            && array_is_list($dependsOn)
            && $dependsOn === array_filter($dependsOn, static fn ($dependency): bool => is_string($dependency) && $dependency !== '');

        if (!$isListOfKeys) {
            // Not skipped like the cases above: the module stays discovered
            // (its migrations and encrypted_columns still matter) but is
            // never loaded, since what it needs running first is unknown.
            error_log("ModuleManager: {$packageName}'s module.json has a malformed 'dependsOn' (expected an array of module keys), module will not load");

            $this->manifestErrors[$raw['key']] = "'dependsOn' must be an array of module keys";
            $dependsOn                         = [];
        }

        $raw['dependsOn'] = array_values(array_unique($dependsOn));

        return $raw;
    }

    /**
     * Keys $key declares in its module.json 'dependsOn' — the modules
     * that must be installed and enabled before it can be.
     *
     * @return string[]
     */
    public function dependenciesOf(string $key): array
    {
        return $this->discover()[$key]['dependsOn'] ?? [];
    }

    /**
     * Discovered modules that name $key in their own 'dependsOn'. Direct
     * only; enabledDependentsOf() follows the chain.
     *
     * @return string[]
     */
    public function dependentsOf(string $key): array
    {
        $dependents = [];

        foreach ($this->discover() as $otherKey => $manifest) {
            if (in_array($key, $manifest['dependsOn'] ?? [], true)) {
                $dependents[] = (string) $otherKey;
            }
        }

        return $dependents;
    }

    /**
     * Why $key's manifest can't be honoured (a malformed 'dependsOn', or
     * one that is part of a cycle), or null. A module with a manifest
     * error is never loaded and can't be enabled.
     */
    public function manifestError(string $key): ?string
    {
        $this->dependencyOrder();

        return $this->manifestErrors[$key] ?? null;
    }

    /**
     * $key's declared dependencies that aren't running, as
     * [dependency key => 'not installed' | 'not enabled' | 'not loaded'].
     * 'not loaded' is a dependency that is enabled but held back itself
     * (see loadableModuleKeys()).
     *
     * @return array<string, string>
     */
    public function unmetDependencies(string $key): array
    {
        $enabled = $this->enabledModuleKeys();

        return $this->unmetAmong($key, $enabled, $this->resolveLoadable($enabled));
    }

    /**
     * Enabled modules that depend on $key, directly or through another
     * module — what disableModule($key) turns off along with it. Nearest
     * dependents first.
     *
     * @return string[]
     */
    public function enabledDependentsOf(string $key): array
    {
        $enabled    = $this->enabledModuleKeys();
        $chain      = [$key];
        $dependents = [];

        // Dependencies come before their dependents in dependencyOrder(),
        // so one pass follows the chain however long it is.
        foreach ($this->dependencyOrder() as $candidate) {
            if ($candidate === $key || !array_intersect($this->dependenciesOf($candidate), $chain)) {
                continue;
            }

            $chain[] = $candidate;

            if (in_array($candidate, $enabled, true)) {
                $dependents[] = $candidate;
            }
        }

        return $dependents;
    }

    /**
     * Enabled modules that can actually run this request, dependencies
     * before the modules that declare them (otherwise discovery order).
     * Enabling and disabling keep module_registry consistent, but a
     * hand-edited row or a removed package can still leave a module
     * enabled above a dependency that is disabled or gone. Such a module
     * is left out here — so it gets no routes, menu or shared services —
     * and the reason is logged once, rather than letting it run against
     * something that isn't there.
     *
     * @return string[]
     */
    public function loadableModuleKeys(): array
    {
        return $this->resolveLoadable($this->enabledModuleKeys());
    }

    /**
     * @param string[] $enabled
     *
     * @return string[]
     */
    private function resolveLoadable(array $enabled): array
    {
        $loadable = [];

        foreach ($this->dependencyOrder() as $key) {
            // A manifest error was already logged where it was found.
            if (!in_array($key, $enabled, true) || isset($this->manifestErrors[$key])) {
                continue;
            }

            $unmet = $this->unmetAmong($key, $enabled, $loadable);

            if (!$unmet) {
                $loadable[] = $key;

                continue;
            }

            if (!isset($this->reportedNotLoaded[$key])) {
                $this->reportedNotLoaded[$key] = true;

                error_log("ModuleManager: {$key} is enabled but was not loaded, it requires " . self::describeUnmet($unmet));
            }
        }

        return $loadable;
    }

    /**
     * @param string[] $enabled
     * @param string[] $loadable
     *
     * @return array<string, string>
     */
    private function unmetAmong(string $key, array $enabled, array $loadable): array
    {
        $manifests = $this->discover();
        $unmet     = [];

        foreach ($this->dependenciesOf($key) as $dependency) {
            if (in_array($dependency, $loadable, true)) {
                continue;
            }

            if (!isset($manifests[$dependency])) {
                $unmet[$dependency] = 'not installed';
            } else {
                $unmet[$dependency] = in_array($dependency, $enabled, true) ? 'not loaded' : 'not enabled';
            }
        }

        return $unmet;
    }

    /**
     * @param array<string, string> $unmet
     */
    private static function describeUnmet(array $unmet): string
    {
        $parts = [];

        foreach ($unmet as $dependency => $reason) {
            $parts[] = "{$dependency} ({$reason})";
        }

        return implode(', ', $parts);
    }

    /**
     * Every discovered module key, dependencies before the modules that
     * declare them and otherwise in discovery order. A 'dependsOn' cycle
     * has no such order, so each module in one is given a manifest error
     * instead (and so never loads) rather than being followed forever.
     *
     * @return string[]
     */
    private function dependencyOrder(): array
    {
        if ($this->dependencyOrder === null) {
            $this->dependencyOrder = [];

            foreach (array_keys($this->discover()) as $key) {
                // Cast: PHP stores an all-digits module key as an int array key.
                $this->placeAfterDependencies((string) $key, []);
            }
        }

        return $this->dependencyOrder;
    }

    /**
     * @param string[] $path the modules whose dependencies are being
     *                       followed to reach $key, outermost first
     */
    private function placeAfterDependencies(string $key, array $path): void
    {
        if (in_array($key, $this->dependencyOrder ?? [], true)) {
            return;
        }

        $loopStart = array_search($key, $path, true);

        if ($loopStart !== false) {
            $cycle = implode(' -> ', [...array_slice($path, $loopStart), $key]);

            error_log("ModuleManager: 'dependsOn' cycle ({$cycle}), none of these modules will load");

            foreach (array_slice($path, $loopStart) as $member) {
                $this->manifestErrors[$member] = "'dependsOn' cycle: {$cycle}";
            }

            return;
        }

        $path[]    = $key;
        $manifests = $this->discover();

        foreach ($this->dependenciesOf($key) as $dependency) {
            if (isset($manifests[$dependency])) {
                $this->placeAfterDependencies($dependency, $path);
            }
        }

        $this->dependencyOrder[] = $key;
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
     * module_registry.enabled directly. Bundling deliberately does NOT
     * cascade the other direction: disabling the application module
     * doesn't disable its plugins, since a plugin is meant to keep
     * working standalone even after the module that originally brought
     * it in is turned off. (A declared 'dependsOn' is the opposite
     * relationship and does cascade — see disableModule().)
     *
     * Refuses while anything $key declares in 'dependsOn' isn't already
     * installed and enabled. A bundled module in the same position is
     * left disabled rather than failing the whole call.
     *
     * @return string[] Every module_key actually flipped to enabled
     *                   (the requested one first, then any bundled ones)
     *                   — callers use this to report what happened.
     *                   A key not yet registered (needs 'modules sync'
     *                   first) is silently skipped, not an error, same
     *                   as the pre-existing single-module enable did.
     *
     * @throws ModuleDependencyException naming what $key is waiting on
     */
    public function enableModule(string $key): array
    {
        $blocker = $this->enableBlocker($key);

        if ($blocker !== null) {
            throw new ModuleDependencyException("{$key} cannot be enabled: {$blocker}.");
        }

        $changed = [];

        if ($this->setModuleEnabled($key, true)) {
            $changed[] = $key;
        }

        // In dependency order, so a bundled module that depends on another
        // bundled one finds it already enabled.
        foreach (array_intersect($this->dependencyOrder(), $this->bundledModuleKeys($key)) as $bundledKey) {
            if ($this->enableBlocker($bundledKey) === null && $this->setModuleEnabled($bundledKey, true)) {
                $changed[] = $bundledKey;
            }
        }

        return $changed;
    }

    /**
     * The one sanctioned path to disable a module — also disables every
     * enabled module that depends on it (enabledDependentsOf()), so a
     * dependent is never left running against a dependency that is off.
     * Re-enabling $key later does not bring those back; that stays a
     * deliberate admin action.
     *
     * @return string[] Every module_key actually flipped to disabled
     *                   (the requested one first, then its dependents).
     *                   Empty if $key isn't registered.
     */
    public function disableModule(string $key): array
    {
        if ($this->registryRow($key) === null) {
            return [];
        }

        $dependents = $this->enabledDependentsOf($key);

        // Furthest dependents first and $key last, so stopping part-way
        // never leaves a module enabled above a disabled dependency.
        foreach (array_reverse($dependents) as $dependent) {
            if (!$this->setModuleEnabled($dependent, false)) {
                throw new \RuntimeException("Could not disable {$dependent}, which depends on {$key}; {$key} was left enabled.");
            }
        }

        return $this->setModuleEnabled($key, false) ? [$key, ...$dependents] : [];
    }

    private function enableBlocker(string $key): ?string
    {
        $error = $this->manifestError($key);

        if ($error !== null) {
            return "its module.json is invalid ({$error})";
        }

        $unmet = $this->unmetDependencies($key);

        return $unmet ? 'it requires ' . self::describeUnmet($unmet) : null;
    }

    private function registryRow(string $key): ?\ModuleRegistry
    {
        $row = \ModuleRegistry::findFirst([
            'conditions' => 'module_key = :key:',
            'bind'       => ['key' => $key],
        ]);

        // instanceof rather than a bare falsy check: findFirst()'s declared
        // return type is ModelInterface|Row|null, and an interface has no
        // properties to write to (Psalm NoInterfaceProperties) — narrow
        // properly here rather than growing psalm-baseline.xml.
        return $row instanceof \ModuleRegistry ? $row : null;
    }

    private function setModuleEnabled(string $key, bool $enabled): bool
    {
        $row = $this->registryRow($key);

        if ($row === null) {
            return false;
        }

        $row->enabled    = $enabled;
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
