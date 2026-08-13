<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class RolesController extends ControllerBase
{
    protected ?array $allowedRoles = [1];

    public function indexAction()
    {
        // Search/sort/pagination (list-view convention, RB-03).
        $list = \App_skeleton\ListView::paginate(
            $this->request,
            \Roles::class,
            ['name'],
            ['name' => 'name', 'created' => 'id'],
            [],
            [],
            25,
            'asc'
        );

        $this->view->roles       = $list['results'];
        $this->view->listState   = $list;
        $this->view->preserveQuery = [];
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
        $role->name = (string) $this->request->getPost('name');

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

        $role->name = (string) $this->request->getPost('name');

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

    /**
     * "With selected" bulk delete from the list view (list-view convention,
     * RB-03). Delete-only — a role's only other field is `name`, which
     * isn't something that makes sense applied identically across a
     * batch, so there's no "Apply to selected" half here. Roles has no
     * `deleted_at` column (004_soft_deletes.sql only retrofitted
     * users/items, and roles was never a "user-managed entity" in that
     * sense) so this goes through the same hard `delete()` + assigned-user
     * guard as the single-record deleteAction, not SoftDeletes.
     */
    public function bulkAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'roles', 'action' => 'index']);
        }

        $ids = array_filter(array_map('intval', (array) $this->request->getPost('role_ids', null, [])));

        if (!$ids) {
            $this->flash->error('No roles were selected');

            return $this->dispatcher->forward(['controller' => 'roles', 'action' => 'index']);
        }

        $roles = \Roles::find([
            'conditions' => 'id IN ({ids:array})',
            'bind'       => ['ids' => $ids],
        ]);

        $deleted = 0;
        $skipped = 0;

        foreach ($roles as $role) {
            $usersWithRole = \Users::count(['role_id = :role_id:', 'bind' => ['role_id' => $role->id]]);

            if ($usersWithRole > 0) {
                $skipped++;

                continue;
            }

            if ($role->delete()) {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->flash->success($deleted . ' role(s) deleted');
        }

        if ($skipped > 0) {
            $this->flash->error($skipped . ' role(s) skipped — still assigned to at least one user');
        }

        return $this->dispatcher->forward(['controller' => 'roles', 'action' => 'index']);
    }
}
