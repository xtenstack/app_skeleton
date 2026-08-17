<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

/**
 * Admin hub for instance-level configuration that isn't a good fit for the
 * self-service Settings page — starts with module management (enable/
 * disable what ModuleManager discovered in vendor/), meant to grow with
 * other admin-only tools over time rather than each getting its own
 * top-level sidebar entry.
 */
class ConfigurationController extends ControllerBase
{
    protected ?array $allowedRoles = [1];

    public function indexAction(): void
    {
        $this->view->modules = \ModuleRegistry::find(['order' => 'module_key']);
    }

    public function enableAction($key = null)
    {
        $this->setEnabled($key, true);

        return $this->dispatcher->forward(['controller' => 'configuration', 'action' => 'index']);
    }

    public function disableAction($key = null)
    {
        $this->setEnabled($key, false);

        return $this->dispatcher->forward(['controller' => 'configuration', 'action' => 'index']);
    }

    private function setEnabled(?string $key, bool $enabled): void
    {
        $module = $key ? \ModuleRegistry::findFirst([
            'conditions' => 'module_key = :key:',
            'bind'       => ['key' => $key],
        ]) : null;

        if (!$module) {
            $this->flash->error("Module '{$key}' is not registered.");

            return;
        }

        $module->enabled    = $enabled;
        $module->updated_at = date('Y-m-d H:i:s');

        if ($module->save()) {
            $this->flash->success($module->module_key . ' ' . ($enabled ? 'enabled' : 'disabled') . '.');
        } else {
            $this->flash->error('Failed to update ' . $module->module_key . ': ' . implode(', ', $module->getMessages()));
        }
    }
}
