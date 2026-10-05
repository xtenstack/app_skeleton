<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

use App_skeleton\LicenseManager;

/**
 * Admin screen for paid modules' licence keys: what each module's licence
 * state is, the keys stored on this instance, and "check now". All of the
 * logic is App_skeleton\LicenseManager's; this only turns form posts into
 * calls on it. Reached from the Configuration page.
 */
class LicensesController extends ControllerBase
{
    protected ?array $allowedRoles = [1];

    public function indexAction(): void
    {
        $this->view->entitlements = $this->licenseManager->entitlements();
        $this->view->licenseKeys  = \LicenseKeys::find(['order' => 'id']);
        $this->view->assignments  = $this->licenseManager->assignments();
        $this->view->serverUrl    = $this->licenseManager->serverUrl();
        $this->view->graceDays    = LicenseManager::GRACE_DAYS;
    }

    public function addKeyAction()
    {
        if (!$this->request->isPost()) {
            return $this->toIndex();
        }

        try {
            $licenseKey = $this->licenseManager->addKey(
                (string) $this->request->getPost('license_key'),
                $this->request->getPost('label'),
                (array) $this->request->getPost('modules')
            );
        } catch (\InvalidArgumentException $e) {
            $this->flash->error($e->getMessage());

            return $this->toIndex();
        }

        $this->flash->success('Licence key saved.');
        $this->checkKeyAndReport((int) $licenseKey->id);

        return $this->toIndex();
    }

    public function editKeyAction($id = null)
    {
        $licenseKey = \LicenseKeys::findFirst(['conditions' => 'id = :id:', 'bind' => ['id' => (int) $id]]);

        if (!$licenseKey instanceof \LicenseKeys) {
            $this->flash->error('That licence key was not found.');

            return $this->toIndex();
        }

        $this->view->pick('licenses/edit-key');
        $this->view->licenseKey   = $licenseKey;
        $this->view->assigned     = $this->licenseManager->assignments()[(int) $licenseKey->id] ?? [];
        $this->view->entitlements = $this->licenseManager->entitlements();
    }

    public function saveKeyAction($id = null)
    {
        if (!$this->request->isPost()) {
            return $this->toIndex();
        }

        try {
            $licenseKey = $this->licenseManager->updateKey(
                (int) $id,
                $this->request->getPost('license_key'),
                $this->request->getPost('label'),
                (array) $this->request->getPost('modules')
            );
        } catch (\InvalidArgumentException $e) {
            $this->flash->error($e->getMessage());

            return $this->toIndex();
        }

        $this->flash->success('Licence key updated.');
        $this->checkKeyAndReport((int) $licenseKey->id);

        return $this->toIndex();
    }

    public function removeKeyAction($id = null)
    {
        if (!$this->request->isPost()) {
            return $this->toIndex();
        }

        if ($this->licenseManager->removeKey((int) $id)) {
            $this->flash->success('Licence key removed from this instance.');
        } else {
            $this->flash->error('That licence key was not found.');
        }

        return $this->toIndex();
    }

    public function checkAction($moduleKey = null)
    {
        if (!$this->request->isPost()) {
            return $this->toIndex();
        }

        $moduleKey = (string) $moduleKey;

        if (!in_array($moduleKey, $this->licenseManager->modulesNeedingAKey(), true)) {
            $this->flash->error('That module is not installed, or needs no licence key.');

            return $this->toIndex();
        }

        $this->report($this->licenseManager->checkInModules([$moduleKey]));

        return $this->toIndex();
    }

    public function checkAllAction()
    {
        if (!$this->request->isPost()) {
            return $this->toIndex();
        }

        $this->licenseManager->checkInAll();
        $this->flash->success('Checked in with the licence server. The result for each module is below.');

        return $this->toIndex();
    }

    /**
     * A key is checked the moment it is entered, so the admin sees
     * whether it was accepted without waiting for the next day's
     * check-in.
     */
    private function checkKeyAndReport(int $licenseKeyId): void
    {
        $moduleKeys = $this->licenseManager->modulesTriedWithKey($licenseKeyId);

        if (!$moduleKeys) {
            $this->flash->warning('This key is not being tried for any module yet. Edit it and tick the modules it was issued for.');

            return;
        }

        $this->report($this->licenseManager->checkInModules($moduleKeys));
    }

    /**
     * @param array<string, array<string, mixed>> $entitlements
     */
    private function report(array $entitlements): void
    {
        $confirmed   = [];
        $rejected    = [];
        $unreachable = [];
        $noKey       = [];

        foreach ($entitlements as $moduleKey => $entitlement) {
            if (!$entitlement['hasKey']) {
                $noKey[] = $moduleKey;
            } elseif ($entitlement['lastResult'] === 'valid') {
                $confirmed[] = $moduleKey;
            } elseif ($entitlement['lastResult'] === 'rejected') {
                $rejected[] = $moduleKey;
            } else {
                $unreachable[] = $moduleKey;
            }
        }

        if ($confirmed) {
            $this->flash->success(sprintf('The licence server confirmed: %s.', implode(', ', $confirmed)));
        }

        if ($rejected) {
            $this->flash->error(sprintf('The licence server did not accept the key for: %s.', implode(', ', $rejected)));
        }

        if ($unreachable) {
            $this->flash->warning(sprintf('The licence server could not be reached, so nothing changed for: %s.', implode(', ', $unreachable)));
        }

        if ($noKey) {
            $this->flash->warning(sprintf('No licence key is stored for: %s.', implode(', ', $noKey)));
        }
    }

    private function toIndex()
    {
        return $this->dispatcher->forward(['controller' => 'licenses', 'action' => 'index']);
    }
}
