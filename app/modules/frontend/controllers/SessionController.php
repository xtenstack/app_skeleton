<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Frontend\Controllers;

class SessionController extends ControllerBase
{
    public function indexAction()
    {
    }

    public function loginAction()
    {
        if (!$this->request->isPost()) {
            return $this->response->redirect($this->url->get('frontend/session'));
        }

        $email    = (string) $this->request->getPost('email', 'email');
        $password = (string) $this->request->getPost('password', 'string');

        if ($this->auth->check($email, $password)) {
            return $this->response->redirect($this->url->get('frontend'));
        }

        $this->view->error = 'Invalid email or password';
        $this->view->pick('session/index');
    }

    public function logoutAction()
    {
        $this->auth->logout();

        return $this->response->redirect($this->url->get('frontend/session'));
    }
}
