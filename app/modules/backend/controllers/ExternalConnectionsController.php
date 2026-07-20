<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class ExternalConnectionsController extends ControllerBase
{
    protected ?array $allowedRoles = [1];

    public function indexAction()
    {
        $this->view->connections = \ExternalConnections::find(['order' => 'name']);
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
        $connection->name      = (string) $this->request->getPost('name', 'string');
        $connection->base_url  = (string) $this->request->getPost('base_url', 'string') ?: null;
        $connection->auth_type = (string) $this->request->getPost('auth_type', 'string');
        $connection->config    = (string) $this->request->getPost('config', 'string') ?: null;
        $connection->is_active = $this->request->getPost('is_active') ? 1 : 0;

        $connection->setCredential((string) $this->request->getPost('credential', 'string'));
    }
}
