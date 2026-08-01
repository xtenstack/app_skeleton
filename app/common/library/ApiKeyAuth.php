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

        if ($authHeader !== '' && stripos($authHeader, 'Bearer ') === 0) {
            return trim(substr($authHeader, 7));
        }

        $apiKeyHeader = (string) $request->getHeader('X-Api-Key');

        return $apiKeyHeader !== '' ? $apiKeyHeader : null;
    }

    /**
     * Null if the token doesn't match a live (non-revoked) key or its
     * owning user is no longer active — otherwise the normalized
     * principal, after bumping the key's last_used_at.
     */
    public function resolve(string $token): ?array
    {
        $apiKey = \ApiKeys::findFirst([
            'conditions' => 'token_hash = :hash: AND revoked_at IS NULL',
            'bind'       => ['hash' => hash('sha256', $token)],
        ]);

        if (!$apiKey) {
            return null;
        }

        $user = \Users::findFirst([
            'conditions' => 'id = :id: AND is_active = 1',
            'bind'       => ['id' => $apiKey->user_id],
        ]);

        if (!$user) {
            return null;
        }

        $apiKey->last_used_at = date('Y-m-d H:i:s');
        $apiKey->save();

        return [
            'type'       => 'user',
            'api_key_id' => (int) $apiKey->id,
            'user_id'    => (int) $user->id,
            'role_id'    => (int) $user->role_id,
            'label'      => $apiKey->name,
        ];
    }
}
