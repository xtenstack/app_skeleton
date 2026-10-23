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
     * the authenticated user's id/email/role_id/role_ids in the session and
     * makes that user this request's principal.
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

        if (!$user instanceof \Users || !password_verify($password, $user->password_hash)) {
            Audit::recordEvent('login_failed', $user?->id, ['email' => $email, 'ip' => $ip]);

            return false;
        }

        $roleIds = $user->roleIds();

        $this->session->set('auth', [
            'id'         => $user->id,
            'email'      => $user->email,
            'role_id'    => $user->role_id,
            'role_ids'   => $roleIds,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
        ]);

        $userSettings = [];

        foreach (\UserSettings::find(['conditions' => 'user_id = :id:', 'bind' => ['id' => $user->id]]) as $setting) {
            $userSettings[$setting->setting_key] = $setting->setting_value;
        }

        $this->session->set('user_settings', $userSettings);
        $this->currentPrincipal->set((int) $user->id, (int) $user->role_id, null, $roleIds);

        Audit::recordEvent('login', (int) $user->id);

        return true;
    }

    /**
     * True when the session carries a logged-in user, who then becomes
     * this request's principal (see CurrentPrincipal). The session service
     * never starts for a request that presents an API key, so this is
     * always false there.
     */
    public function isLoggedIn(): bool
    {
        $auth = $this->session->get('auth');

        if (!is_array($auth) || !isset($auth['id'])) {
            return false;
        }

        $this->currentPrincipal->set((int) $auth['id'], (int) ($auth['role_id'] ?? 0), null, self::roleIdsFrom($auth));

        return true;
    }

    /**
     * The role set in a session's `auth` array. A session written before
     * role_ids existed has only role_id, and is read as that one role, so
     * nobody is logged out or refused by a deploy.
     *
     * @param array<string, mixed> $auth
     *
     * @return int[]
     */
    public static function roleIdsFrom(array $auth): array
    {
        $roleIds = $auth['role_ids'] ?? null;

        if (!is_array($roleIds) || $roleIds === []) {
            $roleIds = isset($auth['role_id']) ? [$auth['role_id']] : [];
        }

        return array_values(array_unique(array_map('intval', $roleIds)));
    }

    /**
     * Where the logged-in user lands: the member dashboard when `member` is
     * the only role they hold, the backend when they hold any other role
     * (whatever the primary is — the backend is where every staff,
     * instructor and agent role works). One rule for login, '/' and the
     * profile page's home link, so they cannot drift.
     */
    public function homePath(): string
    {
        $otherRoles = array_diff($this->currentPrincipal->roleIds(), \Roles::idsByNames(['member']));

        return $otherRoles === [] ? 'frontend/dashboard' : 'backend';
    }

    public function logout(): void
    {
        $auth = $this->session->get('auth');
        Audit::recordEvent('logout', isset($auth['id']) ? (int) $auth['id'] : null);

        $this->session->remove('auth');
        $this->session->remove('user_settings');
        $this->currentPrincipal->clear();
    }

    private function isRateLimited(string $email, string $ip): bool
    {
        $since = date('Y-m-d H:i:s', strtotime(self::RATE_LIMIT_WINDOW));

        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM audit_log
             WHERE entity_type = 'auth' AND action = 'login_failed' AND created_at >= :since
             AND (new_values LIKE :emailLike ESCAPE '!' OR new_values LIKE :ipLike ESCAPE '!')",
            null,
            [
                'since'     => $since,
                'emailLike' => '%"email":"' . $this->escapeLike($email) . '"%',
                'ipLike'    => '%"ip":"' . $this->escapeLike($ip) . '"%',
            ]
        );

        return ((int) ($row['c'] ?? 0)) >= self::RATE_LIMIT_MAX;
    }

    /**
     * '!' rather than the more conventional '\' — a literal backslash
     * immediately before the closing quote in `ESCAPE '\\'` (i.e. the SQL
     * text `ESCAPE '\'`) confuses PDO's own named-placeholder scanner, not
     * Postgres itself: it misreads the string-literal boundary and throws
     * "SQLSTATE[HY093]: Invalid parameter number" on whichever placeholder
     * comes after. Reproduced directly against PDO outside of Phalcon
     * entirely before changing this — not a Phalcon or app-specific bug.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
