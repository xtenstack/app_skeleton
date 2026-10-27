<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * MAA-20261027-008: the admin-only provisioning API. Real HTTP against the
 * running stack (see HttpClient). Covers the gate (no key 401, non-admin
 * 403), create-or-update users with role sets, the refusals (admin role,
 * unknown role, admin target), issuing a key and using it, rotate with a
 * grace window then confirm, expiry, and deactivate.
 */
final class ProvisionApiTest extends TestCase
{
    private static int $adminId;
    private static int $agentId;
    private static string $adminToken;
    private static string $agentToken;

    /** @var int[] */
    private static array $userIds = [];

    /** @var string[] */
    private static array $emails = [];

    private HttpClient $http;

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        [self::$adminId, self::$adminToken] = self::fixtureUser('admin', 'Admin');
        [self::$agentId, self::$agentToken] = self::fixtureUser('agent', 'Agent');
    }

    /** @return array{0: int, 1: string} user id and raw token */
    private static function fixtureUser(string $role, string $last): array
    {
        $roleId = \Roles::idsByNames([$role])[0] ?? null;
        self::assertNotNull($roleId, "fixture needs the '{$role}' role: run ./run seed / ./run migrate run");

        $user                = new \Users();
        $user->email         = 'phpunit-prov-' . $role . '-' . bin2hex(random_bytes(6)) . '@example.invalid';
        $user->password_hash = password_hash('PhpunitTest123!', PASSWORD_DEFAULT);
        $user->first_name    = 'PHPUnit';
        $user->last_name     = 'Prov' . $last;
        $user->role_id       = $roleId;
        $user->is_active     = 1;
        self::assertTrue($user->save(), 'fixture user failed: ' . implode('; ', $user->getMessages()));
        $id              = (int) $user->id;
        self::$userIds[] = $id;

        $raw = 'phpunit-prov-' . $role . '-' . bin2hex(random_bytes(16));

        $key               = new \ApiKeys();
        $key->user_id      = $id;
        $key->name         = 'phpunit-prov-' . $role;
        $key->token_hash   = hash('sha256', $raw);
        $key->token_prefix = substr($raw, 0, 10);
        self::assertTrue($key->save(), 'fixture key failed: ' . implode('; ', $key->getMessages()));

        return [$id, $raw];
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$emails as $email) {
            $user = \Users::findFirstWithTrashed(['conditions' => 'email = :e:', 'bind' => ['e' => $email]]);

            if ($user) {
                self::$userIds[] = (int) $user->id;
            }
        }

        foreach (array_unique(self::$userIds) as $userId) {
            foreach (\ApiKeys::find(['conditions' => 'user_id = :u:', 'bind' => ['u' => $userId]]) as $key) {
                $key->delete();
            }

            foreach (\UserRoles::find(['conditions' => 'user_id = :u:', 'bind' => ['u' => $userId]]) as $row) {
                $row->delete();
            }

            \Users::findFirstWithTrashed(['conditions' => 'id = :i:', 'bind' => ['i' => $userId]])?->softDelete();
        }
    }

    protected function setUp(): void
    {
        $this->http = new HttpClient();
    }

    /** @return array{status: int, json: array<string, mixed>} */
    private function call(string $method, string $path, ?string $token, array $body = []): array
    {
        $headers = $token === null ? [] : ['X-Api-Key: ' . $token];
        $r       = $method === 'GET' ? $this->http->getWithHeaders($path, $headers) : $this->http->postJson($path, $body, $headers);

        return ['status' => $r['status'], 'json' => json_decode($r['body'], true) ?? []];
    }

    private function agentPayload(string $email, array $over = []): array
    {
        self::$emails[] = $email;

        return $over + ['email' => $email, 'first_name' => 'Test', 'last_name' => 'Agent', 'kind' => 'agent', 'role_names' => ['agent']];
    }

    public function testTheGate(): void
    {
        self::assertSame(401, $this->call('GET', '/api/provision/roles', null)['status']);
        self::assertSame(403, $this->call('GET', '/api/provision/roles', self::$agentToken)['status']);
        self::assertSame(403, $this->call('POST', '/api/provision/users', self::$agentToken, $this->agentPayload('phpunit-prov-x@example.invalid'))['status']);
        self::assertSame(403, $this->call('POST', '/api/provision/users/' . self::$agentId . '/api-key', self::$agentToken, ['label' => 'x'])['status']);
        self::assertSame(405, $this->call('GET', '/api/provision/users', self::$adminToken)['status']);
    }

    public function testRolesListMarksAdminUnassignable(): void
    {
        $r = $this->call('GET', '/api/provision/roles', self::$adminToken);

        self::assertSame(200, $r['status']);
        $byName = array_column($r['json']['roles'], 'assignable', 'name');
        self::assertFalse($byName['admin']);
        self::assertTrue($byName['agent']);
    }

    public function testCreateThenUpdateIsIdempotent(): void
    {
        $email = 'phpunit-prov-' . bin2hex(random_bytes(5)) . '@example.invalid';

        $first = $this->call('POST', '/api/provision/users', self::$adminToken, $this->agentPayload($email, ['role_names' => ['agent', 'operator']]));
        self::assertSame(201, $first['status'], json_encode($first['json']));
        self::assertTrue($first['json']['created']);
        self::assertSame(['agent', 'operator'], $first['json']['user']['role_names']);

        $again = $this->call('POST', '/api/provision/users', self::$adminToken, $this->agentPayload($email, ['last_name' => 'Renamed', 'role_names' => ['operator'], 'active' => false]));
        self::assertSame(200, $again['status']);
        self::assertFalse($again['json']['created']);
        self::assertSame($first['json']['user']['id'], $again['json']['user']['id']);
        self::assertSame('Renamed', $again['json']['user']['last_name']);
        self::assertSame(['operator'], $again['json']['user']['role_names']);
        self::assertFalse($again['json']['user']['is_active']);

        $user = \Users::findFirstById((int) $first['json']['user']['id']);
        self::assertFalse(password_verify('', $user->password_hash), 'an agent has no usable password');
    }

    public function testRefusals(): void
    {
        $email = 'phpunit-prov-' . bin2hex(random_bytes(5)) . '@example.invalid';

        self::assertSame(422, $this->call('POST', '/api/provision/users', self::$adminToken, $this->agentPayload($email, ['role_names' => ['admin']]))['status'], 'admin cannot be granted');
        $unknown = $this->call('POST', '/api/provision/users', self::$adminToken, $this->agentPayload($email, ['role_names' => ['no-such-role']]));
        self::assertSame(422, $unknown['status']);
        self::assertSame(['no-such-role'], $unknown['json']['unknown_roles']);
        self::assertSame(422, $this->call('POST', '/api/provision/users', self::$adminToken, ['email' => 'nope'])['status']);

        $existingAdmin = \Users::findFirstById(self::$adminId)->email;
        self::assertSame(403, $this->call('POST', '/api/provision/users', self::$adminToken, $this->agentPayload($existingAdmin))['status'], 'an admin user cannot be changed');
        self::assertSame(422, $this->call('POST', '/api/provision/users/' . self::$adminId . '/deactivate', self::$adminToken)['status'], 'you cannot deactivate yourself');
    }

    public function testIssueKeyShowsTokenOnceAndItWorks(): void
    {
        $email = 'phpunit-prov-' . bin2hex(random_bytes(5)) . '@example.invalid';
        $user  = $this->call('POST', '/api/provision/users', self::$adminToken, $this->agentPayload($email))['json']['user'];

        $issued = $this->call('POST', '/api/provision/users/' . $user['id'] . '/api-key', self::$adminToken, ['label' => 'phpunit-new']);
        self::assertSame(201, $issued['status']);
        self::assertMatchesRegularExpression('/^sk_[0-9a-f]{48}$/', $issued['json']['token']);
        self::assertArrayNotHasKey('token_hash', $issued['json']['api_key']);

        // The key authenticates as the agent: 403 (known, not admin), not 401.
        self::assertSame(403, $this->call('GET', '/api/provision/roles', $issued['json']['token'])['status']);

        self::assertSame(422, $this->call('POST', '/api/provision/users/' . $user['id'] . '/api-key', self::$adminToken, [])['status'], 'label is required');
        self::assertSame(404, $this->call('POST', '/api/provision/users/999999999/api-key', self::$adminToken, ['label' => 'x'])['status']);
    }

    public function testAnAdminRotatesItsOwnKeyAndNormalUsersKeysButNotAnotherAdmins(): void
    {
        // A dedicated admin: confirm revokes the key it replaces, which must not be the shared fixture's.
        [, $adminToken] = self::fixtureUser('admin', 'Admin');
        $email = 'phpunit-prov-' . bin2hex(random_bytes(5)) . '@example.invalid';
        $user  = $this->call('POST', '/api/provision/users', self::$adminToken, $this->agentPayload($email))['json']['user'];

        $own = $this->call('POST', '/api/provision/api-keys/rotate', $adminToken, ['grace_seconds' => 60]);
        self::assertSame(201, $own['status'], 'admins can rotate their own key: ' . json_encode($own['json']));
        self::assertSame(200, $this->call('POST', '/api/provision/api-keys/confirm', $own['json']['token'])['status']);
        self::assertSame(200, $this->call('GET', '/api/provision/roles', $own['json']['token'])['status'], 'the new admin key works');

        // Not on the rotate-any list: may rotate a NON-admin's keys, never another admin's.
        $rot = $this->call('POST', '/api/provision/users/' . $user['id'] . '/rotate-keys', $own['json']['token'], []);
        self::assertSame(201, $rot['status'], 'an admin may rotate a normal user\'s keys: ' . json_encode($rot['json']));
        self::assertNotEmpty($rot['json']['token']);
        self::assertSame(403, $this->call('POST', '/api/provision/users/' . self::$adminId . '/rotate-keys', $own['json']['token'], [])['status'], 'not another admin');
        self::assertSame(403, $this->call('POST', '/api/provision/users/' . self::$adminId . '/api-key', $own['json']['token'], ['label' => 'x'])['status']);
    }

    public function testRotateKeepsTheOldKeyForAGraceWindowThenConfirmKillsIt(): void
    {
        $email = 'phpunit-prov-' . bin2hex(random_bytes(5)) . '@example.invalid';
        $user  = $this->call('POST', '/api/provision/users', self::$adminToken, $this->agentPayload($email))['json']['user'];
        $old   = $this->call('POST', '/api/provision/users/' . $user['id'] . '/api-key', self::$adminToken, ['label' => 'rotating'])['json']['token'];

        self::assertSame(422, $this->call('POST', '/api/provision/api-keys/rotate', $old, ['grace_seconds' => 5])['status']);

        $rotated = $this->call('POST', '/api/provision/api-keys/rotate', $old, ['grace_seconds' => 120]);
        self::assertSame(201, $rotated['status'], json_encode($rotated['json']));
        $new = $rotated['json']['token'];
        self::assertNotSame($old, $new);
        self::assertNotNull($rotated['json']['old_key']['expires_at']);

        // Both work during the grace window.
        self::assertSame(403, $this->call('GET', '/api/provision/roles', $old)['status']);
        self::assertSame(403, $this->call('GET', '/api/provision/roles', $new)['status']);

        $confirmed = $this->call('POST', '/api/provision/api-keys/confirm', $new);
        self::assertSame(200, $confirmed['status']);
        self::assertSame(1, $confirmed['json']['revoked']);

        self::assertSame(401, $this->call('GET', '/api/provision/roles', $old)['status'], 'the old key is dead after confirm');
        self::assertSame(403, $this->call('GET', '/api/provision/roles', $new)['status'], 'the new key still works');
    }

    public function testAnExpiredKeyIsRefused(): void
    {
        $email = 'phpunit-prov-' . bin2hex(random_bytes(5)) . '@example.invalid';
        $user  = $this->call('POST', '/api/provision/users', self::$adminToken, $this->agentPayload($email))['json']['user'];
        $key   = $this->call('POST', '/api/provision/users/' . $user['id'] . '/api-key', self::$adminToken, ['label' => 'short', 'expires_in_days' => 1]);

        self::assertSame(201, $key['status']);
        self::assertNotNull($key['json']['api_key']['expires_at']);
        self::assertSame(403, $this->call('GET', '/api/provision/roles', $key['json']['token'])['status']);

        $row             = \ApiKeys::findFirstById((int) $key['json']['api_key']['id']);
        $row->expires_at = date('Y-m-d H:i:s', time() - 60);
        self::assertTrue($row->save());

        self::assertSame(401, $this->call('GET', '/api/provision/roles', $key['json']['token'])['status']);
    }

    public function testDeactivateDisablesTheUserAndRevokesItsKeys(): void
    {
        $email = 'phpunit-prov-' . bin2hex(random_bytes(5)) . '@example.invalid';
        $user  = $this->call('POST', '/api/provision/users', self::$adminToken, $this->agentPayload($email))['json']['user'];
        $token = $this->call('POST', '/api/provision/users/' . $user['id'] . '/api-key', self::$adminToken, ['label' => 'doomed'])['json']['token'];

        $r = $this->call('POST', '/api/provision/users/' . $user['id'] . '/deactivate', self::$adminToken);
        self::assertSame(200, $r['status']);
        self::assertFalse($r['json']['user']['is_active']);
        self::assertSame(1, $r['json']['keys_revoked']);
        self::assertSame(401, $this->call('GET', '/api/provision/roles', $token)['status']);
        self::assertSame(422, $this->call('POST', '/api/provision/users/' . $user['id'] . '/api-key', self::$adminToken, ['label' => 'again'])['status'], 'no key for an inactive user');
    }
}
