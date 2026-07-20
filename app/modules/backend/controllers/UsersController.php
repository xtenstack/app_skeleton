<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class UsersController extends ControllerBase
{
    protected ?array $allowedRoles = [1];

    public function indexAction()
    {
        $this->view->users = \Users::find(['order' => 'email']);
    }

    public function newAction()
    {
        $this->view->user  = new \Users();
        $this->view->roles = \Roles::find(['order' => 'name']);
    }

    public function editAction($id)
    {
        $user = \Users::findFirstById($id);

        if (!$user) {
            $this->flash->error('User was not found');

            return $this->dispatcher->forward(['controller' => 'users', 'action' => 'index']);
        }

        $this->view->user  = $user;
        $this->view->roles = \Roles::find(['order' => 'name']);
    }

    public function createAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'users', 'action' => 'index']);
        }

        $password = (string) $this->request->getPost('password', 'string');

        if (strlen($password) < 8) {
            $this->flash->error('Password must be at least 8 characters');

            return $this->dispatcher->forward(['controller' => 'users', 'action' => 'new']);
        }

        $user                = new \Users();
        $user->email         = (string) $this->request->getPost('email', 'email');
        $user->first_name    = (string) $this->request->getPost('first_name', 'string');
        $user->last_name     = (string) $this->request->getPost('last_name', 'string');
        $user->role_id       = (int) $this->request->getPost('role_id', 'int');
        $user->is_active     = $this->request->getPost('is_active') ? 1 : 0;
        $user->password_hash = password_hash($password, PASSWORD_DEFAULT);

        if (!$user->save()) {
            foreach ($user->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward(['controller' => 'users', 'action' => 'new']);
        }

        $this->flash->success('User was created successfully');

        return $this->dispatcher->forward(['controller' => 'users', 'action' => 'index']);
    }

    public function saveAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'users', 'action' => 'index']);
        }

        $id   = $this->request->getPost('id', 'int');
        $user = \Users::findFirstById($id);

        if (!$user) {
            $this->flash->error('User does not exist ' . $id);

            return $this->dispatcher->forward(['controller' => 'users', 'action' => 'index']);
        }

        $user->email      = (string) $this->request->getPost('email', 'email');
        $user->first_name = (string) $this->request->getPost('first_name', 'string');
        $user->last_name  = (string) $this->request->getPost('last_name', 'string');
        $user->role_id    = (int) $this->request->getPost('role_id', 'int');
        $user->is_active  = $this->request->getPost('is_active') ? 1 : 0;

        $password = (string) $this->request->getPost('password', 'string');

        if ($password !== '') {
            if (strlen($password) < 8) {
                $this->flash->error('Password must be at least 8 characters');

                return $this->dispatcher->forward(['controller' => 'users', 'action' => 'edit', 'params' => [$id]]);
            }

            $user->password_hash = password_hash($password, PASSWORD_DEFAULT);
        }

        if (!$user->save()) {
            foreach ($user->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward(['controller' => 'users', 'action' => 'edit', 'params' => [$id]]);
        }

        $this->flash->success('User was updated successfully');

        return $this->dispatcher->forward(['controller' => 'users', 'action' => 'index']);
    }

    public function profileAction($userId)
    {
        $user = \Users::findFirstById($userId);

        if (!$user) {
            $this->flash->error('User was not found');

            return $this->dispatcher->forward(['controller' => 'users', 'action' => 'index']);
        }

        $profile = \UserProfiles::findFirst([
            'conditions' => 'user_id = :user_id:',
            'bind'       => ['user_id' => $user->id],
        ]);

        if ($this->request->isPost()) {
            if (!$profile) {
                $profile          = new \UserProfiles();
                $profile->user_id = $user->id;
            }

            $profile->phone    = (string) $this->request->getPost('phone', 'string');
            $profile->bio      = (string) $this->request->getPost('bio', 'string');
            $profile->timezone = (string) $this->request->getPost('timezone', 'string') ?: 'UTC';
            $profile->locale   = (string) $this->request->getPost('locale', 'string') ?: 'en-AU';

            $ageVerified = (bool) $this->request->getPost('age_verified');

            if ($ageVerified && !$profile->age_verified_at) {
                $profile->age_verified_at = date('Y-m-d H:i:s');
            } elseif (!$ageVerified) {
                $profile->age_verified_at = null;
            }

            if (!$profile->save()) {
                foreach ($profile->getMessages() as $message) {
                    $this->flash->error((string) $message);
                }
            } else {
                $this->flash->success('Profile was updated successfully');
            }

            // A real redirect here, not dispatcher->forward() — forwarding
            // to this same action would re-check isPost() on the original
            // request and loop (Phalcon's cyclic-routing guard catches it).
            return $this->response->redirect($this->url->get('backend/users/profile/' . $user->id));
        }

        $this->view->targetUser = $user;
        $this->view->profile    = $profile ?: new \UserProfiles();
    }

    public function settingsAction($userId)
    {
        $user = \Users::findFirstById($userId);

        if (!$user) {
            $this->flash->error('User was not found');

            return $this->dispatcher->forward(['controller' => 'users', 'action' => 'index']);
        }

        $this->view->targetUser = $user;
        $this->view->settings   = \UserSettings::find([
            'conditions' => 'user_id = :user_id:',
            'bind'       => ['user_id' => $user->id],
            'order'      => 'setting_key',
        ]);
    }

    public function addSettingAction($userId)
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'users', 'action' => 'settings', 'params' => [$userId]]);
        }

        $key = (string) $this->request->getPost('setting_key', 'string');

        if ($key === '') {
            $this->flash->error('Key is required');

            return $this->dispatcher->forward(['controller' => 'users', 'action' => 'settings', 'params' => [$userId]]);
        }

        $setting = \UserSettings::findFirst([
            'conditions' => 'user_id = :user_id: AND setting_key = :key:',
            'bind'       => ['user_id' => (int) $userId, 'key' => $key],
        ]) ?: new \UserSettings();

        $setting->user_id       = (int) $userId;
        $setting->setting_key   = $key;
        $setting->setting_value = (string) $this->request->getPost('setting_value', 'string');

        if (!$setting->save()) {
            foreach ($setting->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('Setting saved');
        }

        return $this->dispatcher->forward(['controller' => 'users', 'action' => 'settings', 'params' => [$userId]]);
    }

    public function deleteSettingAction($userId, $settingId)
    {
        $setting = \UserSettings::findFirstById($settingId);

        if ($setting && (int) $setting->user_id === (int) $userId) {
            $setting->delete();
            $this->flash->success('Setting removed');
        }

        return $this->dispatcher->forward(['controller' => 'users', 'action' => 'settings', 'params' => [$userId]]);
    }
}
