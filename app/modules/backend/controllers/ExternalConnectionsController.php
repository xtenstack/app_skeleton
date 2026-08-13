<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class ExternalConnectionsController extends ControllerBase
{
    protected ?array $allowedRoles = [1];

    public function indexAction()
    {
        // Search/sort/pagination (list-view convention, RB-03).
        $list = \App_skeleton\ListView::paginate(
            $this->request,
            \ExternalConnections::class,
            ['name', 'base_url'],
            ['name' => 'name', 'auth_type' => 'auth_type', 'created' => 'id'],
            [],
            [],
            25,
            'asc'
        );

        $this->view->connections  = $list['results'];
        $this->view->listState    = $list;
        $this->view->preserveQuery = [];
    }

    public function newAction()
    {
        $this->view->connection = new \ExternalConnections();
        $this->view->authTypes  = \ExternalConnections::AUTH_TYPES;
    }

    public function editAction($id)
    {
        $connection = \ExternalConnections::findFirstById($id);

        if (!$connection) {
            $this->flash->error('External connection was not found');

            return $this->dispatcher->forward(['controller' => 'external-connections', 'action' => 'index']);
        }

        $this->view->connection = $connection;
        $this->view->authTypes  = \ExternalConnections::AUTH_TYPES;
    }

    public function createAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'external-connections', 'action' => 'index']);
        }

        $connection = new \ExternalConnections();
        $this->applyPost($connection);

        if (!$connection->save()) {
            foreach ($connection->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            $this->view->connection = $connection;
            $this->view->authTypes  = \ExternalConnections::AUTH_TYPES;

            return $this->dispatcher->forward(['controller' => 'external-connections', 'action' => 'new']);
        }

        $this->flash->success('External connection was created successfully');

        return $this->dispatcher->forward(['controller' => 'external-connections', 'action' => 'index']);
    }

    public function saveAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'external-connections', 'action' => 'index']);
        }

        $id         = $this->request->getPost('id', 'int');
        $connection = \ExternalConnections::findFirstById($id);

        if (!$connection) {
            $this->flash->error('External connection was not found');

            return $this->dispatcher->forward(['controller' => 'external-connections', 'action' => 'index']);
        }

        $this->applyPost($connection);

        if (!$connection->save()) {
            foreach ($connection->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward(['controller' => 'external-connections', 'action' => 'edit', 'params' => [$connection->id]]);
        }

        $this->flash->success('External connection was updated successfully');

        return $this->dispatcher->forward(['controller' => 'external-connections', 'action' => 'index']);
    }

    public function deleteAction($id)
    {
        $connection = \ExternalConnections::findFirstById($id);

        if (!$connection) {
            $this->flash->error('External connection was not found');

            return $this->dispatcher->forward(['controller' => 'external-connections', 'action' => 'index']);
        }

        if (!$connection->softDelete()) {
            foreach ($connection->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('External connection was deleted successfully');
        }

        return $this->dispatcher->forward(['controller' => 'external-connections', 'action' => 'index']);
    }

    /**
     * "With selected" bulk delete/active-toggle from the list view
     * (list-view convention, RB-03). `is_active` is the only field worth
     * batch-applying — name/base_url/auth_type/credential all need a
     * per-record value.
     */
    public function bulkAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'external-connections', 'action' => 'index']);
        }

        $ids = array_filter(array_map('intval', (array) $this->request->getPost('external_connection_ids', null, [])));

        if (!$ids) {
            $this->flash->error('No external connections were selected');

            return $this->dispatcher->forward(['controller' => 'external-connections', 'action' => 'index']);
        }

        $connections = \ExternalConnections::find([
            'conditions' => 'id IN ({ids:array})',
            'bind'       => ['ids' => $ids],
        ]);

        $bulkAction = (string) $this->request->getPost('bulk_action');

        if ($bulkAction === 'delete') {
            $count = 0;

            foreach ($connections as $connection) {
                if ($connection->softDelete()) {
                    $count++;
                }
            }

            $this->flash->success($count . ' external connection(s) deleted');

            return $this->dispatcher->forward(['controller' => 'external-connections', 'action' => 'index']);
        }

        $isActive = (string) $this->request->getPost('is_active');

        if ($isActive === '') {
            $this->flash->error('Choose Active or Inactive to bulk-update to');

            return $this->dispatcher->forward(['controller' => 'external-connections', 'action' => 'index']);
        }

        $count = 0;

        foreach ($connections as $connection) {
            $connection->is_active = $isActive === '1' ? 1 : 0;

            if ($connection->save()) {
                $count++;
            }
        }

        $this->flash->success($count . ' external connection(s) updated');

        return $this->dispatcher->forward(['controller' => 'external-connections', 'action' => 'index']);
    }

    /**
     * Decrypts and returns the credential for one request only — never
     * cached, never logged. Used by an admin-only "Reveal" button.
     */
    public function revealAction($id)
    {
        $this->view->disable();

        $connection = \ExternalConnections::findFirstById($id);

        if (!$connection) {
            return $this->response->setStatusCode(404)->setJsonContent(['error' => 'Not found']);
        }

        return $this->response->setJsonContent(['credential' => $connection->revealCredential() ?? '']);
    }

    private function applyPost(\ExternalConnections $connection): void
    {
        $connection->name      = (string) $this->request->getPost('name');
        $connection->base_url  = (string) $this->request->getPost('base_url') ?: null;
        $connection->auth_type = (string) $this->request->getPost('auth_type');
        $connection->config    = (string) $this->request->getPost('config') ?: null;
        $connection->is_active = $this->request->getPost('is_active') ? 1 : 0;

        $connection->setCredential((string) $this->request->getPost('credential'));
    }
}
