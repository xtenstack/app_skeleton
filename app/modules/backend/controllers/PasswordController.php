<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class PasswordController extends ControllerBase
{
    public function forgotAction()
    {
    }

    public function sendAction()
    {
        if (!$this->request->isPost()) {
            return $this->response->redirect($this->url->get('backend/password/forgot'));
        }

        $email = (string) $this->request->getPost('email', 'email');
        $user  = \Users::findFirst(['conditions' => 'email = :email:', 'bind' => ['email' => $email]]);

        // Same response whether or not the address exists, so this can't be
        // used to enumerate accounts.
        if ($user) {
            $user->password_reset_token      = bin2hex(random_bytes(32));
            $user->password_reset_expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $user->save();

            $resetUrl = $this->request->getScheme() . '://' . $this->request->getHttpHost()
                . $this->url->get('backend/password/reset/' . $user->password_reset_token);

            $this->mailer->send(
                $user->email,
                'Reset your password',
                "A password reset was requested for your account.\n\nReset it here:\n\n{$resetUrl}\n\nThis link expires in 1 hour. If you didn't request this, ignore this email."
            );
        }

        $this->view->pick('password/sent');
    }

    public function resetAction($token)
    {
        $user = \Users::findFirst([
            'conditions' => 'password_reset_token = :token: AND password_reset_expires_at > :now:',
            'bind'       => ['token' => $token, 'now' => date('Y-m-d H:i:s')],
        ]);

        if (!$user) {
            $this->flash->error('That password reset link is invalid or has expired.');

            return $this->response->redirect($this->url->get('backend/password/forgot'));
        }

        $this->view->token = $token;
    }

    public function updateAction()
    {
        if (!$this->request->isPost()) {
            return $this->response->redirect($this->url->get('backend/session'));
        }

        $token    = (string) $this->request->getPost('token');
        $password = (string) $this->request->getPost('password');

        $user = \Users::findFirst([
            'conditions' => 'password_reset_token = :token: AND password_reset_expires_at > :now:',
            'bind'       => ['token' => $token, 'now' => date('Y-m-d H:i:s')],
        ]);

        if (!$user) {
            $this->flash->error('That password reset link is invalid or has expired.');

            return $this->response->redirect($this->url->get('backend/password/forgot'));
        }

        if (strlen($password) < 8) {
            $this->view->token = $token;
            $this->view->error = 'Password must be at least 8 characters';

            return $this->view->pick('password/reset');
        }

        $user->password_hash             = password_hash($password, PASSWORD_DEFAULT);
        $user->password_reset_token      = null;
        $user->password_reset_expires_at = null;
        $user->save();

        $this->flash->success('Password updated — you can now sign in.');

        return $this->response->redirect($this->url->get('backend/session'));
    }
}
