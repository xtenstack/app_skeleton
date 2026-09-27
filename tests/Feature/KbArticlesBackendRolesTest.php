<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * backend\KbArticlesController role split (MAA-20260927-001): operators
 * (Charter Agents) can read the Knowledge Base — index and view — but
 * every authoring action (new/create/edit/update/delete/publish/bulk) is
 * admin only, and the write buttons aren't rendered for them. Real HTTP
 * against the real running stack, same as RbacTest.
 */
final class KbArticlesBackendRolesTest extends TestCase
{
    private static string $password = 'PhpunitTest123!';
    private static string $adminEmail;
    private static string $operatorEmail;

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        $operatorRoleId = \Roles::idsByNames(['operator'])[0] ?? null;
        self::assertNotNull($operatorRoleId, "fixture setup requires an 'operator' role to exist — run ./run seed");

        self::$adminEmail    = 'phpunit-kbroles-admin-' . bin2hex(random_bytes(6)) . '@example.invalid';
        self::$operatorEmail = 'phpunit-kbroles-operator-' . bin2hex(random_bytes(6)) . '@example.invalid';

        foreach ([[self::$adminEmail, 1, 'KbRolesAdmin'], [self::$operatorEmail, $operatorRoleId, 'KbRolesOperator']] as [$email, $roleId, $lastName]) {
            $user                = new \Users();
            $user->email         = $email;
            $user->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
            $user->first_name    = 'PHPUnit';
            $user->last_name     = $lastName;
            $user->role_id       = $roleId;
            $user->is_active     = 1;
            self::assertTrue($user->save(), 'fixture user failed to save: ' . implode('; ', $user->getMessages()));
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$adminEmail, self::$operatorEmail] as $email) {
            $user = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => $email]]);
            $user?->softDelete();
        }
    }

    private function loggedInClient(string $email): HttpClient
    {
        $client    = new HttpClient();
        $loginPage = $client->get('/backend/session');
        $csrf      = HttpClient::extractCsrf($loginPage['body']);
        $response  = $client->post('/backend/session/login', [
            'email'      => $email,
            'password'   => self::$password,
            $csrf['key'] => $csrf['token'],
        ]);
        $this->assertStringNotContainsString('Invalid email or password', $response['body'], "fixture $email failed to log in");

        return $client;
    }

    public function testOperatorCanReadButNotAuthor(): void
    {
        $client = $this->loggedInClient(self::$operatorEmail);

        $index = $client->get('/backend/kb-articles');
        $this->assertSame(200, $index['status'], 'operators must still be able to read the Knowledge Base');
        $this->assertStringNotContainsString('New Article', $index['body'], 'write controls should not be shown to operators');

        $this->assertSame(403, $client->get('/backend/kb-articles/new')['status'], 'operator reached the KB authoring form');
    }

    public function testAdminCanAuthor(): void
    {
        $client = $this->loggedInClient(self::$adminEmail);

        $index = $client->get('/backend/kb-articles');
        $this->assertSame(200, $index['status']);
        $this->assertStringContainsString('New Article', $index['body']);

        $this->assertSame(200, $client->get('/backend/kb-articles/new')['status']);
    }
}
