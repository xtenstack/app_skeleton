<?php
declare(strict_types=1);

namespace App_skeleton;

use Phalcon\Di\Injectable;

class Auth extends Injectable
{
    /**
     * Verifies credentials against the users table and, on success, stores
     * the authenticated user's id/email/role_id in the session.
     */
    public function check(string $email, string $password): bool
    {
        $user = \Users::findFirst([
            'conditions' => 'email = :email: AND is_active = 1',
            'bind'       => ['email' => $email],
        ]);

        if (!$user || !password_verify($password, $user->password_hash)) {
            return false;
        }

        $this->session->set('auth', [
            'id'      => $user->id,
            'email'   => $user->email,
            'role_id' => $user->role_id,
        ]);

        return true;
    }

    public function isLoggedIn(): bool
    {
        return $this->session->has('auth');
    }

    public function logout(): void
    {
        $this->session->remove('auth');
    }
}
