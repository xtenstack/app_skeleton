<?php
declare(strict_types=1);

namespace App_skeleton;

use Phalcon\Di\Injectable;

class Auth extends Injectable
{
    /**
     * Failed attempts allowed per email or per IP within RATE_LIMIT_WINDOW
     * before further attempts are blocked outright (password not even
     * checked). Deliberately simple — counts existing login_failed audit
     * rows rather than a dedicated attempts table.
     */
    private const RATE_LIMIT_MAX    = 5;
    private const RATE_LIMIT_WINDOW = '-15 minutes';

    /**
     * Verifies credentials against the users table and, on success, stores
     * the authenticated user's id/email/role_id in the session.
     */
    public function check(string $email, string $password): bool
    {
        $ip = $this->request->getClientAddress(true) ?: 'unknown';

        if ($this->isRateLimited($email, $ip)) {
            Audit::recordEvent('login_blocked', null, ['email' => $email, 'ip' => $ip]);

            return false;
        }

        $user = \Users::findFirst([
            'conditions' => 'email = :email: AND is_active = 1',
            'bind'       => ['email' => $email],
        ]);

        if (!$user || !password_verify($password, $user->password_hash)) {
            Audit::recordEvent('login_failed', $user?->id, ['email' => $email, 'ip' => $ip]);

            return false;
        }

        $this->session->set('auth', [
            'id'      => $user->id,
            'email'   => $user->email,
            'role_id' => $user->role_id,
        ]);

        $userSettings = [];

        foreach (\UserSettings::find(['conditions' => 'user_id = :id:', 'bind' => ['id' => $user->id]]) as $setting) {
            $userSettings[$setting->setting_key] = $setting->setting_value;
        }

        $this->session->set('user_settings', $userSettings);

        Audit::recordEvent('login', $user->id);

        return true;
    }

    public function isLoggedIn(): bool
    {
        return $this->session->has('auth');
    }

    public function logout(): void
    {
        $auth = $this->session->get('auth');
        Audit::recordEvent('logout', $auth['id'] ?? null);

        $this->session->remove('auth');
        $this->session->remove('user_settings');
    }

    private function isRateLimited(string $email, string $ip): bool
    {
        $since = date('Y-m-d H:i:s', strtotime(self::RATE_LIMIT_WINDOW));

        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM audit_log
             WHERE entity_type = 'auth' AND action = 'login_failed' AND created_at >= :since
             AND (new_values LIKE :emailLike ESCAPE '\\' OR new_values LIKE :ipLike ESCAPE '\\')",
            null,
            [
                'since'     => $since,
                'emailLike' => '%"email":"' . $this->escapeLike($email) . '"%',
                'ipLike'    => '%"ip":"' . $this->escapeLike($ip) . '"%',
            ]
        );

        return ((int) ($row['c'] ?? 0)) >= self::RATE_LIMIT_MAX;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
