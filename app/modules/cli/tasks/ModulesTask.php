<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

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
 */
class ModulesTask extends \Phalcon\Cli\Task
{
    public function mainAction()
    {
        echo "Usage: ./run modules sync | list | enable <key> | disable <key>" . PHP_EOL;
    }

    public function syncAction()
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
        }
    }

    public function enableAction($key = null)
    {
        $this->setEnabled($key, true);
    }

    public function disableAction($key = null)
    {
        $this->setEnabled($key, false);
    }

    private function setEnabled(?string $key, bool $enabled): void
    {
        if (!$key) {
            echo 'Usage: ./run modules ' . ($enabled ? 'enable' : 'disable') . ' <key>' . PHP_EOL;

            return;
        }

        $row = \ModuleRegistry::findFirst([
            'conditions' => 'module_key = :key:',
            'bind'       => ['key' => $key],
        ]);

        if (!$row) {
            echo "  '{$key}' is not registered — run './run modules sync' first." . PHP_EOL;

            return;
        }

        $row->enabled    = $enabled;
        $row->updated_at = date('Y-m-d H:i:s');

        if ($row->save()) {
            echo "  {$key}: " . ($enabled ? 'enabled' : 'disabled') . PHP_EOL;
        } else {
            echo "  FAILED to update {$key}: " . implode(', ', $row->getMessages()) . PHP_EOL;
        }
    }
}
