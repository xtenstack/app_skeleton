<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api\Controllers;

use App_skeleton\Provisioning;

/**
 * Admin-only provisioning API (MAA-20261027-008, plan section 14): create
 * or update users with role sets, issue and rotate API keys, deactivate.
 * Stack had no endpoints for any of this; users and keys were session-and-
 * form only, so role assignment could not be scripted. Built for the
 * xten-provision tool, but plain JSON for anyone holding an admin key.
 *
 * Gating is per action, not $allowedRoles: rotate and confirm are for the
 * agent's OWN key and must stay open to non-admin callers, while everything
 * else needs the admin role. Roles named in Provisioning::FORBIDDEN_ROLES
 * (admin) can never be granted here, and an admin-role user cannot be changed
 * or deactivated here: a new admin needs a human at the backend. Key policy
 * (Travis, 27 Oct 2026): everyone, admin or not, rotates their OWN key
 * (rotate/confirm are self-only); Code and Travis may rotate anybody's keys;
 * any other admin may rotate non-admins' keys (rotate-keys, api-key). Every write goes through a model with snapshots on,
 * so Audit attributes it to the calling key's user. The only secret ever
 * returned is a new key's token, once, with no-store; it is never logged.
 *
 *   GET  /api/provision/roles
 *   POST /api/provision/users                       create-or-update by email
 *   POST /api/provision/users/{id}/api-key          issue a key (token shown once)
 *   POST /api/provision/users/{id}/rotate-keys     new key for a user: Code/Travis any, other admins non-admins
 *   POST /api/provision/users/{id}/deactivate       disable user, revoke keys
 *   POST /api/provision/api-keys/rotate             caller's own key -> new key
 *   POST /api/provision/api-keys/confirm            kill the old key now
 */
class ProvisionController extends ControllerBase
{
    private function fail(int $status, string $text, string $message, array $extra = [])
    {
        $this->response->setStatusCode($status, $text);

        return $this->response->setJsonContent(['error' => $message] + $extra);
    }

    /** Null when the request may proceed, else the response to return. */
    private function gate(string $method, bool $adminOnly)
    {
        if ($this->request->getMethod() !== $method) {
            return $this->fail(405, 'Method Not Allowed', "{$method} required");
        }

        if ($adminOnly && !$this->currentPrincipal->hasAnyRole(\Roles::idsByNames(['admin']))) {
            return $this->fail(403, 'Forbidden', 'Forbidden');
        }

        return null;
    }

    private function noStore(): void
    {
        $this->response->setHeader('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function serializeKey(\ApiKeys $key): array
    {
        return [
            'id'         => (int) $key->id,
            'label'      => $key->name,
            'prefix'     => $key->token_prefix,
            'expires_at' => $key->expires_at,
            'created_at' => $key->created_at,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeUser(\Users $user): array
    {
        $names = [];

        foreach (\Roles::find(['conditions' => 'id IN ({ids:array})', 'bind' => ['ids' => $user->roleIds() ?: [0]]]) as $role) {
            $names[] = $role->name;
        }

        sort($names);

        return [
            'id'         => (int) $user->id,
            'email'      => $user->email,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'is_active'  => (int) $user->is_active === 1,
            'role_names' => $names,
        ];
    }

    private function holdsAdmin(\Users $user): bool
    {
        return array_intersect(\Roles::idsByNames(['admin']), $user->roleIds()) !== [];
    }

    /**
     * Policy (Travis, 27 Oct 2026): Code and Travis (Provisioning::rotateAnyEmails) manage anybody's
     * keys; any other administrator manages non-administrators' keys only; nobody else manages
     * anyone's keys (everyone rotates their OWN through rotate/confirm).
     */
    private function mayManageKeysOf(\Users $target): bool
    {
        $caller = \Users::findFirstById((int) ($this->principal['user_id'] ?? 0));

        if (!$caller instanceof \Users) {
            return false;
        }

        if (in_array(strtolower((string) $caller->email), Provisioning::rotateAnyEmails(), true)) {
            return true;
        }

        return $this->currentPrincipal->hasAnyRole(\Roles::idsByNames(['admin'])) && !$this->holdsAdmin($target);
    }

    private function issue(int $userId, string $label, ?string $expiresAt): array
    {
        $token = Provisioning::newToken();

        $key               = new \ApiKeys();
        $key->user_id      = $userId;
        $key->name         = $label;
        $key->token_hash   = hash('sha256', $token);
        $key->token_prefix = substr($token, 0, 10);
        $key->expires_at   = $expiresAt;

        if (!$key->save()) {
            throw new \RuntimeException('API key could not be saved: ' . implode('; ', array_map('strval', $key->getMessages())));
        }

        return [$key, $token];
    }

    public function rolesAction()
    {
        if ($r = $this->gate('GET', true)) {
            return $r;
        }

        $roles = [];

        foreach (\Roles::find(['order' => 'id']) as $role) {
            $roles[] = [
                'id'         => (int) $role->id,
                'name'       => $role->name,
                'assignable' => !in_array(strtolower((string) $role->name), Provisioning::FORBIDDEN_ROLES, true),
            ];
        }

        return $this->response->setJsonContent(['roles' => $roles]);
    }

    public function usersAction()
    {
        if ($r = $this->gate('POST', true)) {
            return $r;
        }

        $parsed = Provisioning::userPayload($this->getJsonBody());

        if ($parsed['errors'] !== []) {
            return $this->fail(422, 'Unprocessable Entity', 'Validation failed', ['errors' => $parsed['errors']]);
        }

        $data    = $parsed['data'];
        $roleIds = [];
        $found   = [];

        foreach (\Roles::find(['conditions' => 'name IN ({names:array})', 'bind' => ['names' => $data['role_names']]]) as $role) {
            $roleIds[]  = (int) $role->id;
            $found[]    = $role->name;
        }

        $unknown = array_values(array_diff($data['role_names'], $found));

        if ($unknown !== []) {
            return $this->fail(422, 'Unprocessable Entity', 'Unknown role', ['unknown_roles' => $unknown]);
        }

        // Keep the caller's order: the first named role is the primary one
        // (users.role_id), the rest sit in user_roles.
        $ordered = [];

        foreach ($data['role_names'] as $name) {
            foreach (\Roles::find(['conditions' => 'name = :n:', 'bind' => ['n' => $name]]) as $role) {
                $ordered[] = (int) $role->id;
            }
        }

        $existing = \Users::findFirst(['conditions' => 'LOWER(email) = :email:', 'bind' => ['email' => $data['email']]]);
        $user     = $existing instanceof \Users ? $existing : null;
        $created  = $user === null;

        if ($user === null) {
            $user                = new \Users();
            $user->email         = $data['email'];
            $user->password_hash = Provisioning::unusablePasswordHash();

            if ($data['kind'] === 'agent') {
                $user->email_verified_at = date('Y-m-d H:i:s');
            }
        } elseif ($this->holdsAdmin($user)) {
            return $this->fail(403, 'Forbidden', 'An admin user cannot be changed through the provisioning API');
        }

        $user->first_name = $data['first_name'];
        $user->last_name  = $data['last_name'];
        $user->is_active  = $data['active'] ? 1 : 0;
        $user->role_id    = $ordered[0];

        if (!$user->save()) {
            return $this->fail(422, 'Unprocessable Entity', 'User could not be saved', ['errors' => array_map('strval', $user->getMessages())]);
        }

        if (!\UserRoles::syncForUser((int) $user->id, $ordered)) {
            return $this->fail(500, 'Internal Server Error', 'Roles could not be saved');
        }

        $this->response->setStatusCode($created ? 201 : 200, $created ? 'Created' : 'OK');

        return $this->response->setJsonContent(['created' => $created, 'kind' => $data['kind'], 'user' => $this->serializeUser($user)]);
    }

    public function issueKeyAction($id)
    {
        if ($r = $this->gate('POST', true)) {
            return $r;
        }

        $user = \Users::findFirstById((int) $id);

        if (!$user instanceof \Users) {
            return $this->fail(404, 'Not Found', 'Not found');
        }

        if (!$this->mayManageKeysOf($user)) {
            return $this->fail(403, 'Forbidden', 'Only Code and Travis can manage an administrator\'s keys');
        }

        if ((int) $user->is_active !== 1) {
            return $this->fail(422, 'Unprocessable Entity', 'User is not active');
        }

        $body  = $this->getJsonBody();
        $label = trim((string) ($body['label'] ?? ''));

        if ($label === '' || strlen($label) > 100) {
            return $this->fail(422, 'Unprocessable Entity', 'label is required (at most 100 characters)');
        }

        $days = Provisioning::expiryDays($body['expires_in_days'] ?? null);

        if ($days === false) {
            return $this->fail(422, 'Unprocessable Entity', 'expires_in_days must be a whole number from 1 to ' . Provisioning::MAX_EXPIRY_DAYS);
        }

        [$key, $token] = $this->issue((int) $user->id, $label, $days === null ? null : date('Y-m-d H:i:s', time() + $days * 86400));

        $this->noStore();
        $this->response->setStatusCode(201, 'Created');

        return $this->response->setJsonContent(['api_key' => $this->serializeKey($key), 'token' => $token, 'note' => 'The token is shown once and cannot be retrieved again.']);
    }

    /**
     * An admin rotates ANY active user's keys: issues a new key (token shown
     * once) and schedules every other live key of that user to expire after a
     * grace window, so an interrupted rotation never locks the user out.
     */
    public function rotateKeysAction($id)
    {
        if ($r = $this->gate('POST', false)) {
            return $r;
        }

        $user = \Users::findFirstById((int) $id);

        if (!$user instanceof \Users) {
            return $this->fail(404, 'Not Found', 'Not found');
        }

        if (!$this->mayManageKeysOf($user)) {
            return $this->fail(403, 'Forbidden', 'Only Code and Travis can rotate an administrator\'s keys; administrators may rotate non-administrators\' keys and their own');
        }

        if ((int) $user->is_active !== 1) {
            return $this->fail(422, 'Unprocessable Entity', 'User is not active');
        }

        $body  = $this->getJsonBody();
        $grace = Provisioning::graceSeconds($body['grace_seconds'] ?? null);

        if ($grace === null) {
            return $this->fail(422, 'Unprocessable Entity', 'grace_seconds must be a whole number from ' . Provisioning::GRACE_MIN . ' to ' . Provisioning::GRACE_MAX);
        }

        $label = trim((string) ($body['label'] ?? ''));
        $label = $label === '' ? 'rotated by admin' : $label;

        if (strlen($label) > 100) {
            return $this->fail(422, 'Unprocessable Entity', 'label must be at most 100 characters');
        }

        $liveBefore = [];

        foreach (\ApiKeys::find([
            'conditions' => 'user_id = :u: AND revoked_at IS NULL',
            'bind'       => ['u' => (int) $user->id],
        ]) as $old) {
            $liveBefore[] = $old;
        }

        [$new, $token] = $this->issue((int) $user->id, $label, null);

        $deadline = date('Y-m-d H:i:s', time() + $grace);
        $expiring = [];

        foreach ($liveBefore as $old) {
            if ($old->expires_at === null || $old->expires_at > $deadline) {
                $old->expires_at = $deadline;

                if (!$old->save()) {
                    return $this->fail(500, 'Internal Server Error', 'An old key could not be scheduled to expire');
                }
            }

            $expiring[] = ['id' => (int) $old->id, 'expires_at' => $old->expires_at];
        }

        $this->noStore();
        $this->response->setStatusCode(201, 'Created');

        return $this->response->setJsonContent([
            'api_key'      => $this->serializeKey($new),
            'token'        => $token,
            'expiring_keys' => $expiring,
            'note'         => 'Hand the new token to the user, then the old keys die at their expires_at.',
        ]);
    }

    public function deactivateAction($id)
    {
        if ($r = $this->gate('POST', true)) {
            return $r;
        }

        $user = \Users::findFirstById((int) $id);

        if (!$user instanceof \Users) {
            return $this->fail(404, 'Not Found', 'Not found');
        }

        if ((int) $user->id === (int) ($this->principal['user_id'] ?? 0)) {
            return $this->fail(422, 'Unprocessable Entity', 'You cannot deactivate yourself');
        }

        if ($this->holdsAdmin($user)) {
            return $this->fail(403, 'Forbidden', 'An admin user cannot be deactivated through the provisioning API');
        }

        $user->is_active = 0;

        if (!$user->save()) {
            return $this->fail(422, 'Unprocessable Entity', 'User could not be saved');
        }

        $revoked = 0;

        foreach (\ApiKeys::find(['conditions' => 'user_id = :u: AND revoked_at IS NULL', 'bind' => ['u' => (int) $user->id]]) as $key) {
            $key->revoked_at = date('Y-m-d H:i:s');

            if ($key->save()) {
                $revoked++;
            }
        }

        return $this->response->setJsonContent(['user' => $this->serializeUser($user), 'keys_revoked' => $revoked]);
    }

    /** The caller's own live key, and its user, or a response to return. */
    private function ownKey()
    {
        $keyId = $this->principal['api_key_id'] ?? null;

        if ($keyId === null) {
            return $this->fail(403, 'Forbidden', 'This endpoint needs an API key, not a session');
        }

        $key = \ApiKeys::findFirstById((int) $keyId);

        return $key instanceof \ApiKeys ? $key : $this->fail(401, 'Unauthorized', 'Not authenticated');
    }

    public function rotateAction()
    {
        if ($r = $this->gate('POST', false)) {
            return $r;
        }

        $old = $this->ownKey();

        if (!$old instanceof \ApiKeys) {
            return $old;
        }

        $grace = Provisioning::graceSeconds($this->getJsonBody()['grace_seconds'] ?? null);

        if ($grace === null) {
            return $this->fail(422, 'Unprocessable Entity', 'grace_seconds must be a whole number from ' . Provisioning::GRACE_MIN . ' to ' . Provisioning::GRACE_MAX);
        }

        [$new, $token] = $this->issue((int) $old->user_id, (string) $old->name, null);

        $deadline = date('Y-m-d H:i:s', time() + $grace);

        // Never lengthen an expiry the key already had.
        if ($old->expires_at === null || $old->expires_at > $deadline) {
            $old->expires_at = $deadline;

            if (!$old->save()) {
                return $this->fail(500, 'Internal Server Error', 'The old key could not be scheduled to expire');
            }
        }

        $this->noStore();
        $this->response->setStatusCode(201, 'Created');

        return $this->response->setJsonContent([
            'api_key'  => $this->serializeKey($new),
            'token'    => $token,
            'old_key'  => ['id' => (int) $old->id, 'expires_at' => $old->expires_at],
            'note'     => 'Store the new token, then call /api/provision/api-keys/confirm with it. The old key also dies at old_key.expires_at.',
        ]);
    }

    public function confirmAction()
    {
        if ($r = $this->gate('POST', false)) {
            return $r;
        }

        $own = $this->ownKey();

        if (!$own instanceof \ApiKeys) {
            return $own;
        }

        $revoked = 0;

        foreach (\ApiKeys::find([
            'conditions' => 'user_id = :u: AND name = :n: AND id != :id: AND revoked_at IS NULL AND expires_at IS NOT NULL',
            'bind'       => ['u' => (int) $own->user_id, 'n' => (string) $own->name, 'id' => (int) $own->id],
        ]) as $old) {
            $old->revoked_at = date('Y-m-d H:i:s');

            if ($old->save()) {
                $revoked++;
            }
        }

        return $this->response->setJsonContent(['revoked' => $revoked]);
    }
}
