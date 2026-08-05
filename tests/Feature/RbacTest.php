<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ControllerBase::onConstruct()'s $allowedRoles check (app/modules/backend/
 * controllers/ControllerBase.php) is what CLAUDE.md's "no new endpoint
 * with no explicit $allowedRoles decision" rule protects — this test
 * covers the enforcement side: once a controller *has* an $allowedRoles
 * list, a logged-in user outside it actually gets denied, not just that
 * the property exists. UsersController ($allowedRoles = [1], admin-only)
 * is the reference — a real signed-up account (default role: member,
 * SignupController::createAction() hardcodes role_id 2) hitting it.
 * Real HTTP against the real running stack — see HttpClient's own
 * docblock for why this isn't dispatched in-process.
 */
final class RbacTest extends TestCase
{
    private static string $testEmail;

    public static function setUpBeforeClass(): void
    {
        self::$testEmail = 'phpunit-rbac-' . bin2hex(random_bytes(6)) . '@example.invalid';
    }

    public static function tearDownAfterClass(): void
    {
        // Direct DB cleanup, not HTTP — there's no self-service account
        // deletion endpoint (nor should there be, for this test's sake).
        // Same minimal-DI pattern as SoftDeleteTest, only 'db' needed.
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        // softDelete(), not delete() — a fixture account that logged in
        // has real audit_log rows referencing it (audit_log_actor_user_id_fkey),
        // so a hard delete fails with a foreign key violation. Confirmed
        // directly, not assumed: this is exactly the real constraint
        // that makes SoftDeletes the right tool for Users in the first
        // place, not just a test-cleanup inconvenience to work around.
        $user = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => self::$testEmail]]);
        $user?->softDelete();
    }

    public function testMemberRoleIsDeniedAdminOnlyController(): void
    {
        $client = new HttpClient();

        $signupPage = $client->get('/backend/signup');
        $csrf       = HttpClient::extractCsrf($signupPage['body']);

        $client->post('/backend/signup/create', [
            'email'      => self::$testEmail,
            'password'   => 'PhpunitTest123!',
            'first_name' => 'PHPUnit',
            'last_name'  => 'RbacTest',
            $csrf['key'] => $csrf['token'],
        ]);

        // Signup doesn't auto-authenticate (confirmed directly against
        // the running app, Session 13) — log in explicitly, same
        // session/cookie jar throughout.
        $loginPage = $client->get('/backend/session');
        $csrf      = HttpClient::extractCsrf($loginPage['body']);

        $loginResponse = $client->post('/backend/session/login', [
            'email'      => self::$testEmail,
            'password'   => 'PhpunitTest123!',
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertStringNotContainsString(
            'Invalid email or password',
            $loginResponse['body'],
            'fixture account failed to log in — can\'t test role denial without a real authenticated session'
        );

        $response = $client->get('/backend/users');

        $this->assertSame(403, $response['status'], 'a member-role account reached an admin-only ($allowedRoles = [1]) controller');
        $this->assertStringContainsString('403', $response['body']);
    }
}
