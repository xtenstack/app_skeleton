<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class SettingsController extends ControllerBase
{
    protected ?array $allowedRoles = [1];

    public function indexAction()
    {
        $this->view->settings = \Settings::find(['order' => 'setting_key']);
    }

    public function newAction()
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
        $setting->setting_key   = (string) $this->request->getPost('setting_key', 'string');
        $setting->setting_value = (string) $this->request->getPost('setting_value', 'string');

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

        $setting->setting_value = (string) $this->request->getPost('setting_value', 'string');

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
