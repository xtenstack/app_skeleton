<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class SettingsController extends ControllerBase
{
    protected ?array $allowedRoles = [1];

    public function indexAction(): void
    {
        // Search/sort/pagination (list-view convention, RB-03). No bulk
        // operations here, deliberately — each row is a distinct
        // key/value pair with no field that makes sense applied
        // identically across a batch (unlike Tickets' severity/status),
        // and a bulk-delete across arbitrary app-wide config keys is
        // more likely to be a footgun than a convenience.
        $list = \App_skeleton\ListView::paginate(
            $this->request,
            \Settings::class,
            ['setting_key', 'setting_value'],
            ['key' => 'setting_key', 'created' => 'id'],
            [],
            [],
            25,
            'asc'
        );

        $this->view->settings     = $list['results'];
        $this->view->listState    = $list;
        $this->view->preserveQuery = $list['preserve'];
    }

    public function newAction(): void
    {
        $this->view->setting = new \Settings();
    }

    public function editAction($id)
    {
        $setting = \Settings::findFirstById($id);

        if (!$setting) {
            $this->flash->error('Setting was not found');

            return $this->dispatcher->forward(['controller' => 'settings', 'action' => 'index']);
        }

        $this->view->setting = $setting;
    }

    public function createAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'settings', 'action' => 'index']);
        }

        $setting                = new \Settings();
        $setting->setting_key   = (string) $this->request->getPost('setting_key');
        $setting->setting_value = (string) $this->request->getPost('setting_value');

        if (!$setting->save()) {
            foreach ($setting->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward(['controller' => 'settings', 'action' => 'new']);
        }

        $this->flash->success('Setting was created successfully');

        return $this->dispatcher->forward(['controller' => 'settings', 'action' => 'index']);
    }

    public function saveAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'settings', 'action' => 'index']);
        }

        $id      = $this->request->getPost('id', 'int');
        $setting = \Settings::findFirstById($id);

        if (!$setting) {
            $this->flash->error('Setting does not exist ' . $id);

            return $this->dispatcher->forward(['controller' => 'settings', 'action' => 'index']);
        }

        $setting->setting_value = (string) $this->request->getPost('setting_value');

        if (!$setting->save()) {
            foreach ($setting->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward(['controller' => 'settings', 'action' => 'edit', 'params' => [$setting->id]]);
        }

        $this->flash->success('Setting was updated successfully');

        return $this->dispatcher->forward(['controller' => 'settings', 'action' => 'index']);
    }

    public function deleteAction($id)
    {
        $setting = \Settings::findFirstById($id);

        if (!$setting) {
            $this->flash->error('Setting was not found');

            return $this->dispatcher->forward(['controller' => 'settings', 'action' => 'index']);
        }

        if (!$setting->delete()) {
            foreach ($setting->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('Setting was deleted successfully');
        }

        return $this->dispatcher->forward(['controller' => 'settings', 'action' => 'index']);
    }
}
