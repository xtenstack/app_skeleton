<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api\Controllers;

class SessionController extends ControllerBase
{
    public function loginAction()
    {
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'POST required']);
        }

        $body = $this->getJsonBody();

        $email    = (string) ($body['email'] ?? $this->request->getPost('email', 'email'));
        $password = (string) ($body['password'] ?? $this->request->getPost('password'));

        if (!$this->auth->check($email, $password)) {
            $this->response->setStatusCode(401, 'Unauthorized');

            return $this->response->setJsonContent(['error' => 'Invalid email or password']);
        }

        return $this->response->setJsonContent(['user' => $this->currentUserPayload()]);
    }

    public function meAction()
    {
        if (!$this->auth->isLoggedIn()) {
            $this->response->setStatusCode(401, 'Unauthorized');

            return $this->response->setJsonContent(['error' => 'Not authenticated']);
        }

        return $this->response->setJsonContent(['user' => $this->currentUserPayload()]);
    }

    public function logoutAction()
    {
        $this->auth->logout();

        return $this->response->setJsonContent(['ok' => true]);
    }
}
