<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class ApiKeysController extends ControllerBase
{
    public function indexAction()
    {
        $userId = $this->session->get('auth')['id'];

        // Search/sort/pagination (list-view convention, RB-03) — still
        // scoped to the logged-in user's own keys, same as before.
        $list = \App_skeleton\ListView::paginate(
            $this->request,
            \ApiKeys::class,
            ['name'],
            ['created' => 'id', 'name' => 'name', 'last_used' => 'last_used_at'],
            ['user_id = :user_id:'],
            ['user_id' => $userId]
        );

        $this->view->apiKeys      = $list['results'];
        $this->view->listState    = $list;
        $this->view->preserveQuery = [];
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
        // chance to see the raw key. Re-run the same paginate() the index
        // view expects (listState/preserveQuery), since this forwards
        // straight to the index template rather than redirecting.
        $list = \App_skeleton\ListView::paginate(
            $this->request,
            \ApiKeys::class,
            ['name'],
            ['created' => 'id', 'name' => 'name', 'last_used' => 'last_used_at'],
            ['user_id = :user_id:'],
            ['user_id' => $userId]
        );

        $this->view->newToken     = $raw;
        $this->view->apiKeys      = $list['results'];
        $this->view->listState    = $list;
        $this->view->preserveQuery = [];
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

    /**
     * "With selected" bulk revoke from the list view (list-view
     * convention, RB-03). Revoke-only — there's no per-record delete for
     * API keys (revoked_at is the terminal state, keys are never removed
     * outright) and no other field worth batch-applying, so there's no
     * "Apply to selected" dropdown, just a single bulk action. Still
     * scoped to the logged-in user's own keys, same guard as
     * revokeAction.
     */
    public function bulkAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'api-keys', 'action' => 'index']);
        }

        $userId = $this->session->get('auth')['id'];
        $ids    = array_filter(array_map('intval', (array) $this->request->getPost('api_key_ids', null, [])));

        if (!$ids) {
            $this->flash->error('No API keys were selected');

            return $this->dispatcher->forward(['controller' => 'api-keys', 'action' => 'index']);
        }

        $apiKeys = \ApiKeys::find([
            'conditions' => 'id IN ({ids:array}) AND user_id = :user_id:',
            'bind'       => ['ids' => $ids, 'user_id' => $userId],
        ]);

        $count = 0;

        foreach ($apiKeys as $apiKey) {
            if ($apiKey->revoked_at) {
                continue;
            }

            $apiKey->revoked_at = date('Y-m-d H:i:s');

            if ($apiKey->save()) {
                $count++;
            }
        }

        $this->flash->success($count . ' API key(s) revoked');

        return $this->dispatcher->forward(['controller' => 'api-keys', 'action' => 'index']);
    }
}
