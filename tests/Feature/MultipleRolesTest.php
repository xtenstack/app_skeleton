<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Users hold a set of roles (user_roles + users.role_id as the primary):
 * login puts the set in the session, a controller's $allowedRoles passes a
 * user holding any one of them, the landing rule sends a member-only user
 * to the frontend dashboard and anyone with another role to the backend,
 * the Users screen saves the set, and the Roles delete guard counts a role
 * held only as a secondary. Real HTTP against the running stack, same as
 * RbacTest.
 */
final class MultipleRolesTest extends TestCase
{
    private static string $password = 'PhpunitTest123!';

    /** @var array<string, string> fixture key => email */
    private static array $emails = [];

    /** @var array<string, int> fixture key => user id */
    private static array $ids = [];

    private static int $memberRoleId;
    private static int $operatorRoleId;
    private static int $adminRoleId = 1;

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        self::$memberRoleId   = \Roles::idsByNames(['member'])[0];
        self::$operatorRoleId = \Roles::idsByNames(['operator'])[0] ?? 0;
        self::assertGreaterThan(0, self::$operatorRoleId, "fixture setup requires the 'operator' role — run ./run seed run");

        $suffix = bin2hex(random_bytes(6));

        foreach ([
            'admin'          => [self::$adminRoleId, []],
            'memberOnly'     => [self::$memberRoleId, []],
            'memberAdmin'    => [self::$memberRoleId, [self::$adminRoleId]],
            'memberOperator' => [self::$memberRoleId, [self::$operatorRoleId]],
            'target'         => [self::$memberRoleId, []],
        ] as $key => [$primary, $extra]) {
            $email               = "phpunit-mr-{$key}-{$suffix}@example.invalid";
            $user                = new \Users();
            $user->email         = $email;
            $user->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
            $user->first_name    = 'PHPUnit';
            $user->last_name     = 'MultipleRoles';
            $user->role_id       = $primary;
            $user->is_active     = 1;
            self::assertTrue($user->save(), "fixture {$key} failed to save: " . implode('; ', $user->getMessages()));
            self::assertTrue(\UserRoles::syncForUser((int) $user->id, [$primary, ...$extra]));

            self::$emails[$key] = $email;
            self::$ids[$key]    = (int) $user->id;
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$ids as $id) {
            foreach (\UserRoles::find(['conditions' => 'user_id = :id:', 'bind' => ['id' => $id]]) as $row) {
                $row->delete();
            }

            \Users::findFirstById($id)?->softDelete();
        }
    }

    private function loggedIn(string $key): HttpClient
    {
        $client    = new HttpClient();
        $loginPage = $client->get('/backend/session');
        $csrf      = HttpClient::extractCsrf($loginPage['body']);
        $response  = $client->post('/backend/session/login', [
            'email'      => self::$emails[$key],
            'password'   => self::$password,
            $csrf['key'] => $csrf['token'],
        ]);
        $this->assertStringNotContainsString('Invalid email or password', $response['body'], "fixture {$key} failed to log in");

        return $client;
    }

    public function testLoginPutsTheRoleSetInTheSession(): void
    {
        $client = $this->loggedIn('memberAdmin');
        $me     = $client->get('/api/session/me');

        $this->assertSame(200, $me['status']);
        $user = json_decode($me['body'], true)['user'] ?? [];

        $expected = [self::$memberRoleId, self::$adminRoleId];
        sort($expected);

        $this->assertSame(self::$memberRoleId, (int) $user['role_id'], 'role_id stays the primary role');
        $this->assertSame($expected, array_map('intval', $user['role_ids']), 'role_ids is the full set');
    }

    public function testAUserWithTwoRolesReachesAControllerAllowedForEither(): void
    {
        // UsersController is admin-only ([1]); this user's primary is member.
        $this->assertSame(200, $this->loggedIn('memberAdmin')->get('/backend/users')['status'], 'a secondary admin role did not open an admin-only controller');

        // TicketsController allows admin/operator/charter_agent: operator as
        // a secondary role opens it, and the admin-only screen stays closed.
        $operator = $this->loggedIn('memberOperator');
        $this->assertSame(200, $operator->get('/backend/tickets')['status']);
        $this->assertSame(403, $operator->get('/backend/users')['status'], 'a role the user does not hold let them in');

        $this->assertSame(403, $this->loggedIn('memberOnly')->get('/backend/tickets')['status']);
    }

    public function testLandingRuleSendsAnyNonMemberRoleToTheBackend(): void
    {
        $this->assertStringEndsWith('/backend', $this->loginLocation('memberOperator'), 'member + operator should land in the backend');
        $this->assertStringEndsWith('/frontend/dashboard', $this->loginLocation('memberOnly'), 'a member-only user should land on the member dashboard');
    }

    /**
     * The Location header of the login POST itself — HttpClient::post()
     * follows redirects, which hides the one assertion this test is about.
     */
    private function loginLocation(string $key): string
    {
        $client    = new HttpClient();
        $loginPage = $client->exchange('GET', '/backend/session');
        $cookies   = [];

        foreach ($loginPage['headers'] as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $cookies[] = trim(explode(';', substr($line, strlen('Set-Cookie:')))[0]);
            }
        }

        $csrf     = HttpClient::extractCsrf($loginPage['body']);
        $response = $client->exchange('POST', '/backend/session/login', [
            'Cookie: ' . implode('; ', $cookies),
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'email'      => self::$emails[$key],
            'password'   => self::$password,
            $csrf['key'] => $csrf['token'],
        ]));

        $this->assertSame(302, $response['status'], "login for {$key} did not redirect: " . substr($response['body'], 0, 200));

        foreach ($response['headers'] as $line) {
            if (stripos($line, 'Location:') === 0) {
                return rtrim(trim(substr($line, strlen('Location:'))), '/');
            }
        }

        $this->fail('no Location header on the login response');
    }

    public function testUsersScreenSavesTheRoleSet(): void
    {
        $client   = $this->loggedIn('admin');
        $targetId = self::$ids['target'];

        $editPage = $client->get('/backend/users/edit/' . $targetId);
        $this->assertSame(200, $editPage['status']);
        $this->assertStringContainsString('name="role_ids[]"', $editPage['body'], 'the edit screen offers a role set');

        $csrf = HttpClient::extractCsrf($editPage['body']);
        $client->post('/backend/users/save', [
            'id'         => (string) $targetId,
            'email'      => self::$emails['target'],
            'first_name' => 'PHPUnit',
            'last_name'  => 'MultipleRoles',
            'role_ids'   => [(string) self::$memberRoleId, (string) self::$operatorRoleId],
            'role_id'    => (string) self::$operatorRoleId,
            'is_active'  => '1',
            $csrf['key'] => $csrf['token'],
        ]);

        $target = \Users::findFirstById($targetId);
        $this->assertSame(self::$operatorRoleId, (int) $target->role_id, 'the primary did not round-trip');
        $expected = [self::$memberRoleId, self::$operatorRoleId];
        sort($expected);
        $this->assertSame($expected, $target->roleIds(), 'the role set did not round-trip');

        // A primary outside the ticked set is refused and nothing changes.
        $editPage = $client->get('/backend/users/edit/' . $targetId);
        $csrf     = HttpClient::extractCsrf($editPage['body']);
        $response = $client->post('/backend/users/save', [
            'id'         => (string) $targetId,
            'email'      => self::$emails['target'],
            'first_name' => 'PHPUnit',
            'last_name'  => 'MultipleRoles',
            'role_ids'   => [(string) self::$memberRoleId],
            'role_id'    => (string) self::$adminRoleId,
            $csrf['key'] => $csrf['token'],
        ]);
        $this->assertStringContainsString('The primary role must be one of the selected roles', $response['body']);
        $this->assertSame($expected, \Users::findFirstById($targetId)->roleIds());

        // Dropping back to one role clears user_roles of the rest.
        $editPage = $client->get('/backend/users/edit/' . $targetId);
        $csrf     = HttpClient::extractCsrf($editPage['body']);
        $client->post('/backend/users/save', [
            'id'         => (string) $targetId,
            'email'      => self::$emails['target'],
            'first_name' => 'PHPUnit',
            'last_name'  => 'MultipleRoles',
            'role_ids'   => [(string) self::$memberRoleId],
            'role_id'    => (string) self::$memberRoleId,
            $csrf['key'] => $csrf['token'],
        ]);
        $this->assertSame([self::$memberRoleId], \Users::findFirstById($targetId)->roleIds());
    }

    public function testRoleDeleteGuardCountsARoleHeldOnlyAsASecondary(): void
    {
        $role       = new \Roles();
        $role->name = 'phpunit-mr-' . bin2hex(random_bytes(4));
        $this->assertTrue($role->save(), implode('; ', $role->getMessages()));
        $roleId = (int) $role->id;

        try {
            $this->assertTrue(\UserRoles::syncForUser(self::$ids['target'], [self::$memberRoleId, $roleId]));

            $client    = $this->loggedIn('admin');
            $indexPage = $client->get('/backend/roles');
            $csrf      = HttpClient::extractCsrf($indexPage['body']);
            $response  = $client->post('/backend/roles/delete/' . $roleId, [$csrf['key'] => $csrf['token']]);

            $this->assertStringContainsString('Cannot delete a role that is still assigned', $response['body']);
            $this->assertNotNull(\Roles::findFirstById($roleId), 'a role held as a secondary was deleted');
        } finally {
            \UserRoles::syncForUser(self::$ids['target'], [self::$memberRoleId]);
            \Roles::findFirstById($roleId)?->delete();
        }
    }
}
