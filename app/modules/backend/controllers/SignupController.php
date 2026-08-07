<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class SignupController extends ControllerBase
{
    public function indexAction()
    {
        $this->view->pick('signup/new');
    }

    public function newAction()
    {
        $this->view->pick('signup/new');
    }

    public function createAction()
    {
        if (!$this->request->isPost()) {
            return $this->response->redirect($this->url->get('backend/signup'));
        }

        $email     = (string) $this->request->getPost('email', 'email');
        $password  = (string) $this->request->getPost('password');
        $firstName = (string) $this->request->getPost('first_name');
        $lastName  = (string) $this->request->getPost('last_name');

        if (strlen($password) < 8) {
            $this->view->error = 'Password must be at least 8 characters';

            return $this->view->pick('signup/new');
        }

        $existing = \Users::findFirst(['conditions' => 'email = :email:', 'bind' => ['email' => $email]]);

        // Same "check your email" response whether or not the address is
        // already registered, so signup can't be used to enumerate accounts.
        if (!$existing) {
            $user                                = new \Users();
            $user->role_id                       = 2;
            $user->email                         = $email;
            $user->password_hash                 = password_hash($password, PASSWORD_DEFAULT);
            $user->first_name                    = $firstName;
            $user->last_name                     = $lastName;
            $user->is_active                     = 1;
            $user->verification_token            = bin2hex(random_bytes(32));
            $user->verification_token_expires_at = date('Y-m-d H:i:s', strtotime('+1 day'));

            if (!$user->save()) {
                $this->view->error = implode(', ', array_map('strval', $user->getMessages()));

                return $this->view->pick('signup/new');
            }

            $verifyUrl = $this->absoluteUrl('backend/signup/verify/' . $user->verification_token);

            $this->mailer->send(
                $user->email,
                'Verify your email',
                "Welcome to App Skeleton!\n\nVerify your email address:\n\n{$verifyUrl}\n\nThis link expires in 24 hours."
            );
        }

        $this->view->pick('signup/sent');
    }

    public function verifyAction($token)
    {
        $user = \Users::findFirst([
            'conditions' => 'verification_token = :token: AND verification_token_expires_at > :now:',
            'bind'       => ['token' => $token, 'now' => date('Y-m-d H:i:s')],
        ]);

        if (!$user) {
            $this->flash->error('That verification link is invalid or has expired.');

            return $this->response->redirect($this->url->get('backend/session'));
        }

        $user->email_verified_at             = date('Y-m-d H:i:s');
        $user->verification_token            = null;
        $user->verification_token_expires_at = null;
        $user->save();

        $this->flash->success('Email verified — you can now sign in.');

        return $this->response->redirect($this->url->get('backend/session'));
    }

    private function absoluteUrl(string $uri): string
    {
        return $this->request->getScheme() . '://' . $this->request->getHttpHost() . $this->url->get($uri);
    }
}
