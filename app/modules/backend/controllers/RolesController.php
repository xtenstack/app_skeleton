<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class RolesController extends ControllerBase
{
    protected ?array $allowedRoles = [1];

    public function indexAction()
    {
        $this->view->roles = \Roles::find(['order' => 'name']);
    }

    public function newAction()
    {
        $this->view->role = new \Roles();
    }

    public function editAction($id)
    {
        $role = \Roles::findFirstById($id);

        if (!$role) {
            $this->flash->error('Role was not found');

            return $this->dispatcher->forward(['controller' => 'roles', 'action' => 'index']);
        }

        $this->view->role = $role;
    }

    public function createAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'roles', 'action' => 'index']);
        }

        $role       = new \Roles();
        $role->name = (string) $this->request->getPost('name', 'string');

        if (!$role->save()) {
            foreach ($role->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward(['controller' => 'roles', 'action' => 'new']);
        }

        $this->flash->success('Role was created successfully');

        return $this->dispatcher->forward(['controller' => 'roles', 'action' => 'index']);
    }

    public function saveAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'roles', 'action' => 'index']);
        }

        $id   = $this->request->getPost('id', 'int');
        $role = \Roles::findFirstById($id);

        if (!$role) {
            $this->flash->error('Role does not exist ' . $id);

            return $this->dispatcher->forward(['controller' => 'roles', 'action' => 'index']);
        }

        $role->name = (string) $this->request->getPost('name', 'string');

        if (!$role->save()) {
            foreach ($role->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward(['controller' => 'roles', 'action' => 'edit', 'params' => [$role->id]]);
        }

        $this->flash->success('Role was updated successfully');

        return $this->dispatcher->forward(['controller' => 'roles', 'action' => 'index']);
    }

    public function deleteAction($id)
    {
        $role = \Roles::findFirstById($id);

        if (!$role) {
            $this->flash->error('Role was not found');

            return $this->dispatcher->forward(['controller' => 'roles', 'action' => 'index']);
        }

        $usersWithRole = \Users::count(['role_id = :role_id:', 'bind' => ['role_id' => $role->id]]);

        if ($usersWithRole > 0) {
            $this->flash->error("Cannot delete a role that is still assigned to {$usersWithRole} user(s)");

            return $this->dispatcher->forward(['controller' => 'roles', 'action' => 'index']);
        }

        if (!$role->delete()) {
            foreach ($role->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('Role was deleted successfully');
        }

        return $this->dispatcher->forward(['controller' => 'roles', 'action' => 'index']);
    }
}
