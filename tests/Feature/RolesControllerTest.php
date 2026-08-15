<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Project audit Fix Kit (2026-08-13, Tier 1) — direct coverage for
 * RolesController, which got real bulk-action logic (bulkAction()'s
 * hard-delete "with selected", guarded by the same assigned-user check
 * as the single-record deleteAction) via the list-view convention
 * rollout (1eb5b5b) but had zero automated coverage before this. Covers
 * create, the delete-blocked-by-assignment guard (both single and bulk
 * paths), and a successful bulk delete of an unassigned role. Real HTTP
 * against the real running stack — see HttpClient's own docblock.
 */
final class RolesControllerTest extends TestCase
{
    private static string $adminEmail;
    private static string $adminPassword = 'PhpunitTest123!';

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        self::$adminEmail = 'phpunit-roles-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $admin                = new \Users();
        $admin->email         = self::$adminEmail;
        $admin->password_hash = password_hash(self::$adminPassword, PASSWORD_DEFAULT);
        $admin->first_name    = 'PHPUnit';
        $admin->last_name     = 'RolesControllerTest';
        $admin->role_id       = 1; // admin
        $admin->is_active     = 1;

        self::assertTrue($admin->save(), 'fixture admin failed to save: ' . implode('; ', $admin->getMessages()));
    }

    public static function tearDownAfterClass(): void
    {
        $memberRole = \Roles::findFirst(['conditions' => "name = 'member'"]);

        foreach (\Roles::find(['conditions' => "name LIKE 'phpunit_roles_fixture_%'"]) as $leftover) {
            // Fixture users assigned to a fixture role in
            // testDeleteIsBlockedWhenARoleIsStillAssignedToAUser() /
            // testBulkActionDeletesUnassignedRolesAndSkipsAssignedOnes()
            // are only soft-deleted, not removed — role_id still points
            // at this row, so it has to be reassigned before the fixture
            // role itself can be hard-deleted (users_role_id_fkey).
            foreach (\Users::findWithTrashed(['conditions' => 'role_id = :rid:', 'bind' => ['rid' => $leftover->id]]) as $orphan) {
                $orphan->role_id = $memberRole->id;
                $orphan->save();
            }

            $leftover->delete();
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

    public function testAdminCanCreateARole(): void
    {
        $client = $this->loggedInClient();

        $newPage = $client->get('/backend/roles/new');
        $csrf    = HttpClient::extractCsrf($newPage['body']);

        $name = 'phpunit_roles_fixture_' . bin2hex(random_bytes(4));

        $client->post('/backend/roles/create', [
            'name'       => $name,
            $csrf['key'] => $csrf['token'],
        ]);

        $saved = \Roles::findFirst(['conditions' => 'name = :name:', 'bind' => ['name' => $name]]);

        $this->assertNotNull($saved, 'role was not created');
    }

    public function testDeleteIsBlockedWhenARoleIsStillAssignedToAUser(): void
    {
        $client = $this->loggedInClient();

        $role       = new \Roles();
        $role->name = 'phpunit_roles_fixture_' . bin2hex(random_bytes(4));
        $role->save();

        $assignedUser                = new \Users();
        $assignedUser->email         = 'phpunit-roles-fixture-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $assignedUser->password_hash = password_hash('PhpunitTest123!', PASSWORD_DEFAULT);
        $assignedUser->first_name    = 'PHPUnit';
        $assignedUser->last_name     = 'AssignedFixture';
        $assignedUser->role_id       = $role->id;
        $assignedUser->is_active     = 1;
        $assignedUser->save();

        $client->get('/backend/roles/delete/' . $role->id);

        $stillThere = \Roles::findFirstById($role->id);
        $this->assertNotNull($stillThere, 'a role still assigned to a user was deleted anyway');

        $assignedUser->softDelete();
    }

    public function testBulkActionDeletesUnassignedRolesAndSkipsAssignedOnes(): void
    {
        $client = $this->loggedInClient();

        $deletable       = new \Roles();
        $deletable->name = 'phpunit_roles_fixture_' . bin2hex(random_bytes(4));
        $deletable->save();

        $assigned       = new \Roles();
        $assigned->name = 'phpunit_roles_fixture_' . bin2hex(random_bytes(4));
        $assigned->save();

        $assignedUser                = new \Users();
        $assignedUser->email         = 'phpunit-roles-fixture-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $assignedUser->password_hash = password_hash('PhpunitTest123!', PASSWORD_DEFAULT);
        $assignedUser->first_name    = 'PHPUnit';
        $assignedUser->last_name     = 'BulkAssignedFixture';
        $assignedUser->role_id       = $assigned->id;
        $assignedUser->is_active     = 1;
        $assignedUser->save();

        $indexPage = $client->get('/backend/roles');
        $csrf      = HttpClient::extractCsrf($indexPage['body']);

        $client->post('/backend/roles/bulk', [
            'role_ids'   => [(string) $deletable->id, (string) $assigned->id],
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertNull(\Roles::findFirstById($deletable->id), 'the unassigned role was not deleted by bulkAction()');
        $this->assertNotNull(\Roles::findFirstById($assigned->id), 'the still-assigned role was deleted by bulkAction() despite the guard');

        $assignedUser->softDelete();
    }
}
