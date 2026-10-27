<?php
declare(strict_types=1);

namespace App_skeleton;

use Phalcon\Di\Injectable;
use Phalcon\Http\RequestInterface;

/**
 * Resolves an `Authorization: Bearer <token>` or `X-Api-Key` header into
 * the users row that issued it. api_keys only ever stores a hash (see
 * migration 002_profiles_settings_api_audit.sql) — resolution re-hashes
 * the presented token and looks up the match, the same way password_verify
 * works for a login password. Every API-key caller resolves to the same
 * 'user' principal shape whether it's an agent, staff, or a customer's own
 * key — agents are ordinary `users` rows with the `agent` role, not a
 * separate identity type.
 */
class ApiKeyAuth extends Injectable
{
    public function tokenFromRequest(RequestInterface $request): ?string
    {
        $authHeader = (string) $request->getHeader('Authorization');

        if ($authHeader !== '' && stripos($authHeader, 'Bearer ') === 0 && trim(substr($authHeader, 7)) !== '') {
            return trim(substr($authHeader, 7));
        }

        $apiKeyHeader = (string) $request->getHeader('X-Api-Key');

        return $apiKeyHeader !== '' ? $apiKeyHeader : null;
    }

    /**
     * Null if the token doesn't match a live (non-revoked, unexpired) key or its
     * owning user is no longer active — otherwise the normalized
     * principal, after bumping the key's last_used_at. A resolved key's
     * user also becomes this request's principal (see CurrentPrincipal),
     * which is how Audit attributes what the caller goes on to change.
     */
    public function resolve(string $token): ?array
    {
        $apiKey = \ApiKeys::findFirst([
            'conditions' => 'token_hash = :hash: AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > :now:)',
            'bind'       => ['hash' => hash('sha256', $token), 'now' => date('Y-m-d H:i:s')],
        ]);

        if (!$apiKey) {
            return null;
        }

        $user = \Users::findFirst([
            'conditions' => 'id = :id: AND is_active = 1',
            'bind'       => ['id' => $apiKey->user_id],
        ]);

        // instanceof, not a falsy check: findFirst() is typed against
        // ModelInterface, which has no roleIds() (Psalm UndefinedInterfaceMethod).
        if (!$user instanceof \Users) {
            return null;
        }

        $apiKey->last_used_at = date('Y-m-d H:i:s');
        $apiKey->save();

        $roleIds = $user->roleIds();

        $this->currentPrincipal->set((int) $user->id, (int) $user->role_id, (int) $apiKey->id, $roleIds);

        return [
            'type'       => 'user',
            'api_key_id' => (int) $apiKey->id,
            'user_id'    => (int) $user->id,
            'role_id'    => (int) $user->role_id,
            'role_ids'   => $roleIds,
            'label'      => $apiKey->name,
        ];
    }
}
