<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class ApiKeysController extends ControllerBase
{
    public function indexAction()
    {
        $userId = $this->session->get('auth')['id'];

        $this->view->apiKeys = \ApiKeys::find([
            'conditions' => 'user_id = :user_id:',
            'bind'       => ['user_id' => $userId],
            'order'      => 'id DESC',
        ]);
    }

    public function createAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'api-keys', 'action' => 'index']);
        }

        $name = (string) $this->request->getPost('name');

        if ($name === '') {
            $this->flash->error('Name is required');

            return $this->dispatcher->forward(['controller' => 'api-keys', 'action' => 'index']);
        }

        $userId = $this->session->get('auth')['id'];
        $raw    = 'sk_' . bin2hex(random_bytes(24));

        $apiKey               = new \ApiKeys();
        $apiKey->user_id      = $userId;
        $apiKey->name         = $name;
        $apiKey->token_hash   = hash('sha256', $raw);
        $apiKey->token_prefix = substr($raw, 0, 10);

        if (!$apiKey->save()) {
            foreach ($apiKey->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward(['controller' => 'api-keys', 'action' => 'index']);
        }

        // Shown once — only the hash is ever stored, so this is the only
        // chance to see the raw key.
        $this->view->newToken = $raw;
        $this->view->apiKeys  = \ApiKeys::find([
            'conditions' => 'user_id = :user_id:',
            'bind'       => ['user_id' => $userId],
            'order'      => 'id DESC',
        ]);
        $this->view->pick('api-keys/index');
    }

    public function revokeAction($id)
    {
        $userId = $this->session->get('auth')['id'];
        $apiKey = \ApiKeys::findFirstById($id);

        if (!$apiKey || (int) $apiKey->user_id !== (int) $userId) {
            $this->flash->error('API key was not found');

            return $this->dispatcher->forward(['controller' => 'api-keys', 'action' => 'index']);
        }

        $apiKey->revoked_at = date('Y-m-d H:i:s');

        if (!$apiKey->save()) {
            foreach ($apiKey->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('API key revoked');
        }

        return $this->dispatcher->forward(['controller' => 'api-keys', 'action' => 'index']);
    }
}
