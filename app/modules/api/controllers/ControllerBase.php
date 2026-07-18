<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api\Controllers;

use Phalcon\Mvc\Controller;

class ControllerBase extends Controller
{
    /**
     * Role ids allowed to use this controller, or null for "any
     * authenticated user". Set per-subclass to restrict further.
     */
    protected ?array $allowedRoles = null;

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

        if ($this->allowedRoles !== null) {
            $roleId = $this->session->get('auth')['role_id'] ?? null;

            if (!in_array($roleId, $this->allowedRoles, true)) {
                $this->response->setStatusCode(403, 'Forbidden');
                $this->response->setJsonContent(['error' => 'Forbidden']);
                $this->response->send();
                exit;
            }
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
