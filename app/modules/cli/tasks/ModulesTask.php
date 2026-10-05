<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

use App_skeleton\ModuleDependencyException;

/**
 * Usage: ./run modules sync
 *        ./run modules list
 *        ./run modules enable <key>
 *        ./run modules disable <key>
 *
 * Reconciles Composer-discovered module packages (see
 * App_skeleton\ModuleManager::discover()) into the module_registry table —
 * the only place "installed but disabled" is representable, since
 * Composer's own installed.json has no concept of enablement. Run 'sync'
 * after any composer require/remove of a module package, before 'enable'.
 *
 * 'enable' is refused while a module the key declares in its module.json
 * 'dependsOn' isn't installed and enabled; 'disable' also disables every
 * enabled module that depends on the key. Both go through ModuleManager,
 * the same as the admin Configuration page.
 */
class ModulesTask extends \Phalcon\Cli\Task
{
    public function mainAction(): void
    {
        echo 'Usage: ./run modules sync | list | enable <key> | disable <key>' . PHP_EOL;
    }

    public function syncAction(): void
    {
        $discovered = $this->moduleManager->discover();

        foreach ($discovered as $key => $manifest) {
            $row = \ModuleRegistry::findFirst([
                'conditions' => 'module_key = :key:',
                'bind'       => ['key' => $key],
            ]);

            $isNew = !$row;

            if ($isNew) {
                $row                = new \ModuleRegistry();
                $row->module_key    = $key;
                $row->enabled       = false;
                $row->discovered_at = date('Y-m-d H:i:s');
            }

            $row->code         = $manifest['code'] ?? null;
            $row->tier         = $manifest['tier'];
            $row->package_name = $manifest['packageName'];
            $row->version      = $manifest['version'];
            $row->updated_at   = date('Y-m-d H:i:s');

            if ($row->save()) {
                echo '  ' . ($isNew ? 'discovered' : 'updated') . ": {$key} ({$manifest['tier']})" . PHP_EOL;
            } else {
                echo "  FAILED to sync {$key}: " . implode(', ', $row->getMessages()) . PHP_EOL;
            }
        }

        if (!$discovered) {
            echo '  no module packages discovered (vendor/ has no module.json manifests)' . PHP_EOL;
        }

        echo 'Sync complete.' . PHP_EOL;
    }

    /**
     * @return void
     */
    public function listAction()
    {
        $rows = \ModuleRegistry::find(['order' => 'module_key']);

        if (!$rows->count()) {
            echo "No modules registered — run './run modules sync' after installing a module package." . PHP_EOL;

            return;
        }

        foreach ($rows as $row) {
            $marker = $row->enabled ? '[enabled] ' : '[disabled]';
            echo "  {$marker} {$row->module_key} ({$row->tier}, {$row->code}, v{$row->version})" . PHP_EOL;

            $requires = $this->moduleManager->dependenciesOf($row->module_key);
            $unmet    = $this->moduleManager->unmetDependencies($row->module_key);
            $error    = $this->moduleManager->manifestError($row->module_key);

            if ($requires) {
                $labels = array_map(fn ($key) => isset($unmet[$key]) ? "{$key} ({$unmet[$key]})" : $key, $requires);
                echo '             requires: ' . implode(', ', $labels) . PHP_EOL;
            }

            if ($error !== null) {
                echo "             module.json error: {$error}" . PHP_EOL;
            }

            if ($row->enabled && ($unmet || $error !== null)) {
                echo '             not loaded until that is resolved' . PHP_EOL;
            }
        }
    }

    /**
     * Also enables whatever $key bundles via its own composer.json
     * 'require' (see ModuleManager::enableModule()) — installing
     * ai-ssa-application, for example, brings its Chat/Phone/Email
     * plugins online too, not just itself.
     */
    public function enableAction($key = null): void
    {
        if (!$key) {
            echo 'Usage: ./run modules enable <key>' . PHP_EOL;

            return;
        }

        try {
            $changed = $this->moduleManager->enableModule($key);
        } catch (ModuleDependencyException $e) {
            echo '  ' . $e->getMessage() . PHP_EOL;

            return;
        }

        if (!$changed) {
            echo "  '{$key}' is not registered — run './run modules sync' first." . PHP_EOL;

            return;
        }

        foreach ($changed as $enabledKey) {
            echo '  ' . $enabledKey . ': enabled' . ($enabledKey === $key ? '' : ' (bundled with ' . $key . ')') . PHP_EOL;
        }
    }

    public function disableAction($key = null): void
    {
        if (!$key) {
            echo 'Usage: ./run modules disable <key>' . PHP_EOL;

            return;
        }

        $changed = $this->moduleManager->disableModule($key);

        if (!$changed) {
            echo "  '{$key}' is not registered — run './run modules sync' first." . PHP_EOL;

            return;
        }

        foreach ($changed as $disabledKey) {
            echo '  ' . $disabledKey . ': disabled' . ($disabledKey === $key ? '' : ' (depends on ' . $key . ')') . PHP_EOL;
        }
    }
}
