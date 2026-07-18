<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

use Phalcon\Mvc\Controller;

class ControllerBase extends Controller
{
    /**
     * Role ids allowed to use this controller, or null for "any
     * authenticated backend user". Set per-subclass to restrict further,
     * e.g. protected ?array $allowedRoles = [1]; for admin-only.
     */
    protected ?array $allowedRoles = null;

    protected function onConstruct()
    {
        if ($this->dispatcher->getControllerName() === 'session') {
            return;
        }

        if (!$this->auth->isLoggedIn()) {
            $this->response->redirect($this->url->get('backend/session'))->send();
            exit;
        }

        if ($this->allowedRoles !== null) {
            $roleId = $this->session->get('auth')['role_id'] ?? null;

            if (!in_array($roleId, $this->allowedRoles, true)) {
                $this->response->setStatusCode(403, 'Forbidden');
                $this->response->setContent('<h1>403 Forbidden</h1><p>You do not have access to this page.</p>');
                $this->response->send();
                exit;
            }
        }
    }
}
