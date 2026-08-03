<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class SessionController extends ControllerBase
{
    public function indexAction()
    {
    }

    public function loginAction()
    {
        if (!$this->request->isPost()) {
            return $this->response->redirect($this->url->get('backend/session'));
        }

        $email    = (string) $this->request->getPost('email', 'email');
        $password = (string) $this->request->getPost('password', 'string');

        if ($this->auth->check($email, $password)) {
            // Admin/operator/agent land in the backend admin UI; 'member'
            // lands in the frontend module's own dashboard (REQ-020/031)
            // — same role check frontend/IndexController uses for '/'.
            $roleId       = $this->session->get('auth')['role_id'] ?? null;
            $memberRoleId = \Roles::idsByNames(['member']);
            $home         = in_array($roleId, $memberRoleId, true) ? 'frontend/dashboard' : 'backend';

            return $this->response->redirect($this->url->get($home));
        }

        $this->view->error = 'Invalid email or password';
        $this->view->pick('session/index');
    }

    public function logoutAction()
    {
        $this->auth->logout();

        return $this->response->redirect($this->url->get('backend/session'));
    }
}
