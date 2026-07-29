<?php
declare(strict_types=1);

namespace App_skeleton;

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
     * Application-tier modules that are both discovered and enabled in
     * module_registry, in the ['key' => ['className' => ...]] shape
     * Phalcon\Mvc\Application::registerModules() expects.
     */
    public function registeredPhalconModules(): array
    {
        $enabled = $this->enabledModuleKeys();
        $modules = [];

        foreach ($this->discover() as $key => $manifest) {
            if ($manifest['tier'] !== 'application' || !in_array($key, $enabled, true)) {
                continue;
            }

            $modules[$key] = ['className' => $manifest['className']];
        }

        return $modules;
    }

    /**
     * Backend sidebar menu: the built-in menu.php contribution followed by
     * each enabled application module's own menu file, in the same
     * {label, icon, controller, url, roles} shape sidenav.phtml already
     * expects. $surface matches a manifest's declared 'surface' ('backend',
     * 'frontend', or 'both').
     */
    public function mergedMenu(string $surface): array
    {
        $menu    = include APP_PATH . '/modules/backend/config/menu.php';
        $enabled = $this->enabledModuleKeys();

        foreach ($this->discover() as $key => $manifest) {
            if ($manifest['tier'] !== 'application' || !in_array($key, $enabled, true)) {
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

        if ($raw['tier'] === 'application' && empty($raw['className'])) {
            error_log("ModuleManager: {$packageName} is an application-tier module but declares no className, skipping");

            return null;
        }

        return $raw;
    }

    /**
     * module_key values with enabled=true in module_registry. Returns []
     * rather than throwing if the table doesn't exist yet, so discovery
     * stays safe on a fresh install before migrations have run.
     */
    private function enabledModuleKeys(): array
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
