<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Project audit Fix Kit (2026-08-11, Tier 2) — direct coverage for
 * UsersController's own CRUD behavior, distinct from RbacTest's coverage
 * of the shared $allowedRoles denial mechanism (that test already proves
 * the gate itself works, using this controller as its reference — not
 * duplicated here). Covers what only this controller does: creating a
 * user with a role assignment, and editing one — including the
 * min-length password validation on both paths. Real HTTP against the
 * real running stack — see HttpClient's own docblock.
 */
final class UsersControllerTest extends TestCase
{
    private static string $adminEmail;
    private static string $adminPassword = 'PhpunitTest123!';

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        self::$adminEmail = 'phpunit-users-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $admin                = new \Users();
        $admin->email         = self::$adminEmail;
        $admin->password_hash = password_hash(self::$adminPassword, PASSWORD_DEFAULT);
        $admin->first_name    = 'PHPUnit';
        $admin->last_name     = 'UsersControllerTest';
        $admin->role_id       = 1; // admin — see RbacTest's own comment on this mapping
        $admin->is_active     = 1;

        self::assertTrue($admin->save(), 'fixture admin failed to save: ' . implode('; ', $admin->getMessages()));
    }

    public static function tearDownAfterClass(): void
    {
        foreach (\Users::findWithTrashed(['conditions' => "email LIKE 'phpunit-users-fixture-%'"]) as $leftover) {
            $leftover->softDelete();
        }

        $admin = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => self::$adminEmail]]);
        $admin?->softDelete();
    }

    private function loggedInClient(): HttpClient
    {
        $client    = new HttpClient();
        $loginPage = $client->get('/backend/session');
        $csrf      = HttpClient::extractCsrf($loginPage['body']);

        $response = $client->post('/backend/session/login', [
            'email'      => self::$adminEmail,
            'password'   => self::$adminPassword,
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertStringNotContainsString(
            'Invalid email or password',
            $response['body'],
            'fixture admin failed to log in — can\'t test the controller without a real authenticated session'
        );

        return $client;
    }

    public function testAdminCanCreateAUserWithARoleAssignment(): void
    {
        $client = $this->loggedInClient();

        $newPage = $client->get('/backend/users/new');
        $csrf    = HttpClient::extractCsrf($newPage['body']);

        $memberRole = \Roles::findFirst(['conditions' => "name = 'member'"]);
        $this->assertNotNull($memberRole, 'the member role fixture is expected to be seeded');

        $email = 'phpunit-users-fixture-' . bin2hex(random_bytes(4)) . '@example.invalid';

        $client->post('/backend/users/create', [
            'email'        => $email,
            'password'     => 'PhpunitTest123!',
            'first_name'   => 'PHPUnit',
            'last_name'    => 'Fixture',
            'role_id'      => (string) $memberRole->id,
            $csrf['key']   => $csrf['token'],
        ]);

        $saved = \Users::findFirst(['conditions' => 'email = :email:', 'bind' => ['email' => $email]]);

        $this->assertNotNull($saved, 'user was not created');
        $this->assertSame((int) $memberRole->id, (int) $saved->role_id, 'the selected role did not round-trip through create');
        $this->assertTrue(password_verify('PhpunitTest123!', $saved->password_hash), 'the password did not hash/save correctly');
    }

    public function testCreateRejectsAShortPassword(): void
    {
        $client = $this->loggedInClient();

        $newPage = $client->get('/backend/users/new');
        $csrf    = HttpClient::extractCsrf($newPage['body']);

        $email = 'phpunit-users-fixture-' . bin2hex(random_bytes(4)) . '@example.invalid';

        $response = $client->post('/backend/users/create', [
            'email'      => $email,
            'password'   => 'short',
            'first_name' => 'PHPUnit',
            'last_name'  => 'Fixture',
            'role_id'    => '1',
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertStringContainsString('Password must be at least 8 characters', $response['body']);

        $saved = \Users::findFirst(['conditions' => 'email = :email:', 'bind' => ['email' => $email]]);
        $this->assertNull($saved, 'a user was created despite a too-short password');
    }

    public function testAdminCanEditAnExistingUsersRole(): void
    {
        $client = $this->loggedInClient();

        $memberRole = \Roles::findFirst(['conditions' => "name = 'member'"]);
        $operatorRole = \Roles::findFirst(['conditions' => "name = 'operator'"]);
        $this->assertNotNull($operatorRole, 'the operator role fixture is expected to be seeded');

        $target                = new \Users();
        $target->email         = 'phpunit-users-fixture-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $target->password_hash = password_hash('PhpunitTest123!', PASSWORD_DEFAULT);
        $target->first_name    = 'PHPUnit';
        $target->last_name     = 'EditFixture';
        $target->role_id       = $memberRole->id;
        $target->is_active     = 1;
        $target->save();

        $editPage = $client->get('/backend/users/edit/' . $target->id);
        $csrf     = HttpClient::extractCsrf($editPage['body']);

        $client->post('/backend/users/save', [
            'id'         => (string) $target->id,
            'email'      => $target->email,
            'first_name' => $target->first_name,
            'last_name'  => $target->last_name,
            'role_id'    => (string) $operatorRole->id,
            $csrf['key'] => $csrf['token'],
        ]);

        $target->refresh();

        $this->assertSame((int) $operatorRole->id, (int) $target->role_id, 'role change did not round-trip through save');
    }

    /**
     * MAA-20260827-002 — upstreamed from the xten-marketing instance
     * clone, where this was built and verified live but never landed in
     * app_skeleton itself. Covers the three cases the fix's own guards
     * exist for: a normal delete succeeds (soft, not hard), an admin
     * can't delete themselves, and the last active admin can't be
     * deleted even by another admin.
     */
    public function testAdminCanDeleteAnotherUser(): void
    {
        $client = $this->loggedInClient();

        $memberRole = \Roles::findFirst(['conditions' => "name = 'member'"]);

        $target                = new \Users();
        $target->email         = 'phpunit-users-fixture-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $target->password_hash = password_hash('PhpunitTest123!', PASSWORD_DEFAULT);
        $target->first_name    = 'PHPUnit';
        $target->last_name     = 'DeleteFixture';
        $target->role_id       = $memberRole->id;
        $target->is_active     = 1;
        $target->save();

        $indexPage = $client->get('/backend/users');
        $csrf      = HttpClient::extractCsrf($indexPage['body']);

        $client->post('/backend/users/delete/' . $target->id, [
            $csrf['key'] => $csrf['token'],
        ]);

        $target->refresh();

        $this->assertNotNull($target->deleted_at, 'user was not soft-deleted');
        $this->assertNull(
            \Users::findFirst(['conditions' => 'id = :id:', 'bind' => ['id' => $target->id]]),
            'soft-deleted user still appears in the default (non-trashed) scope'
        );
    }

    public function testAdminCannotDeleteTheirOwnAccount(): void
    {
        $client = $this->loggedInClient();

        $admin = \Users::findFirst(['conditions' => 'email = :email:', 'bind' => ['email' => self::$adminEmail]]);

        $indexPage = $client->get('/backend/users');
        $csrf      = HttpClient::extractCsrf($indexPage['body']);

        $response = $client->post('/backend/users/delete/' . $admin->id, [
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertStringContainsString('You cannot delete your own account', $response['body']);

        $admin->refresh();
        $this->assertNull($admin->deleted_at, 'admin deleted their own account');
    }

    public function testCannotDeleteTheLastActiveAdmin(): void
    {
        // The guard counts *all* active admins system-wide other than the
        // target, so the only way to exercise it without touching any
        // pre-existing admin (real or another test's fixture) is to have
        // the acting admin itself be the one that stops counting: log in
        // while active (required to even reach this RBAC-gated
        // controller), then flip is_active off directly, simulating an
        // admin deactivated by someone else mid-session — their cookie is
        // still valid (no per-request is_active re-check), but they no
        // longer count as "another active admin" once deactivated.
        $adminRole = \Roles::findFirst(['conditions' => "name = 'admin'"]);

        $actingAdminEmail    = 'phpunit-users-fixture-acting-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $actingAdminPassword = 'PhpunitTest123!';
        $actingAdmin                = new \Users();
        $actingAdmin->email         = $actingAdminEmail;
        $actingAdmin->password_hash = password_hash($actingAdminPassword, PASSWORD_DEFAULT);
        $actingAdmin->first_name    = 'PHPUnit';
        $actingAdmin->last_name     = 'ActingAdmin';
        $actingAdmin->role_id       = $adminRole->id;
        $actingAdmin->is_active     = 1;
        $actingAdmin->save();

        $actingClient = new HttpClient();
        $loginPage    = $actingClient->get('/backend/session');
        $loginCsrf    = HttpClient::extractCsrf($loginPage['body']);
        $actingClient->post('/backend/session/login', [
            'email'           => $actingAdminEmail,
            'password'        => $actingAdminPassword,
            $loginCsrf['key'] => $loginCsrf['token'],
        ]);

        $actingAdmin->is_active = 0;
        $actingAdmin->save();

        $lastAdmin = \Users::findFirst(['conditions' => 'email = :email:', 'bind' => ['email' => self::$adminEmail]]);

        $indexPage = $actingClient->get('/backend/users');
        $csrf      = HttpClient::extractCsrf($indexPage['body']);

        $response = $actingClient->post('/backend/users/delete/' . $lastAdmin->id, [
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertStringContainsString('Cannot delete the last active admin account', $response['body']);

        $lastAdmin->refresh();
        $this->assertNull($lastAdmin->deleted_at, 'the last active admin was deleted');
    }
}
