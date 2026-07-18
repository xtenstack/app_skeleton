<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api\Controllers;

use Phalcon\Mvc\Controller;

class ControllerBase extends Controller
{
    protected function onConstruct()
    {
        $this->response->setContentType('application/json', 'UTF-8');

        if ($this->dispatcher->getControllerName() === 'session') {
            return;
        }

        if (!$this->auth->isLoggedIn()) {
            $this->response->setStatusCode(401, 'Unauthorized');
            $this->response->setJsonContent(['error' => 'Not authenticated']);
            $this->response->send();
            exit;
        }
    }

    protected function currentUserPayload(): array
    {
        $auth = $this->session->get('auth');

        return [
            'id'      => $auth['id'] ?? null,
            'email'   => $auth['email'] ?? null,
            'role_id' => $auth['role_id'] ?? null,
        ];
    }
}
