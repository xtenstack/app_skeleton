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
        $this->view->modules              = \ModuleRegistry::find(['order' => 'module_key']);
        $this->view->maintenanceMode      = $this->settings->get('maintenance_mode', '0') === '1';
        $this->view->maintenanceModeUntil = (string) $this->settings->get('maintenance_mode_until', '');
    }

    /**
     * REQ-172: admin toggle for site-wide maintenance mode. Same
     * $allowedRoles = [1] as the rest of this controller (no per-action
     * override needed — every action here is an admin-only,
     * instance-level config concern, maintenance mode included per the
     * rbac-decision skill's "module toggles" bucket) and the same CSRF
     * enforcement every backend POST gets for free from ControllerBase.
     *
     * Turning it ON requires an end date/time — an indefinite maintenance
     * window with no until value would leave the countdown/absolute-time
     * display REQ-172 asks for with nothing to show, and in practice a
     * maintenance window without a target end time is a bug waiting to
     * strand the site. Turning it OFF has no such requirement, and always
     * succeeds regardless of what's in maintenance_mode_until.
     */
    public function maintenanceAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'configuration', 'action' => 'index']);
        }

        $enabled = $this->request->getPost('maintenance_mode') === '1';
        $until   = trim((string) $this->request->getPost('maintenance_mode_until'));

        $untilTimestamp = '';

        if ($until !== '') {
            // <input type="datetime-local"> posts "Y-m-d\TH:i" (no seconds,
            // no timezone) — interpreted in the server's own timezone, same
            // as every other timestamp this app stores.
            $parsed = \DateTime::createFromFormat('Y-m-d\TH:i', $until);

            if (!$parsed) {
                $this->flash->error('Invalid maintenance end date/time.');

                return $this->dispatcher->forward(['controller' => 'configuration', 'action' => 'index']);
            }

            $untilTimestamp = $parsed->format('Y-m-d H:i:s');
        }

        if ($enabled && $untilTimestamp === '') {
            $this->flash->error('Set an end date/time before turning maintenance mode on.');

            return $this->dispatcher->forward(['controller' => 'configuration', 'action' => 'index']);
        }

        $this->settings->set('maintenance_mode', $enabled ? '1' : '0');
        $this->settings->set('maintenance_mode_until', $untilTimestamp);

        $this->flash->success('Maintenance mode ' . ($enabled ? 'enabled' : 'disabled') . '.');

        return $this->dispatcher->forward(['controller' => 'configuration', 'action' => 'index']);
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
