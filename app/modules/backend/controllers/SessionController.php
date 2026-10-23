<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class SessionController extends ControllerBase
{
    public function indexAction(): void
    {
    }

    public function loginAction()
    {
        if (!$this->request->isPost()) {
            return $this->response->redirect($this->url->get('backend/session'));
        }

        $email    = (string) $this->request->getPost('email', 'email');
        $password = (string) $this->request->getPost('password');

        if ($this->auth->check($email, $password)) {
            // A member-only user lands in the frontend module's own dashboard
            // (REQ-020/031); anyone holding another role lands in the backend
            // — the one rule in Auth::homePath(), shared with '/' and My Profile.
            return $this->response->redirect($this->url->get($this->auth->homePath()));
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
