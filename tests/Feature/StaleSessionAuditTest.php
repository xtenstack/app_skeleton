<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A browser session outlives the users row it names: the row can be
 * removed, or the whole database restored or recreated, while the cookie
 * and the session file both survive. audit_log.actor_user_id is a foreign
 * key to users, so every audited action taken with such a session used to
 * die on the audit insert with a foreign key violation: signing up a new
 * account answered 500 (after creating the account), and logging out
 * answered 500 without logging out. Both paths are covered here, plus the
 * ordinary soft-deleted account, whose row still exists.
 *
 * SQLite does not enforce the foreign key, so there these tests pin the
 * same outcome (the action succeeds, the entry has no actor) rather than
 * catching the original crash.
 *
 * Real HTTP against the real running stack; see HttpClient's docblock.
 */
final class StaleSessionAuditTest extends TestCase
{
    private static string $password = 'PhpunitTest123!';

    /** @var string[] */
    private static array $emails = [];

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$emails as $email) {
            $user = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => $email]]);
            $user?->softDelete();
        }
    }

    private static function newEmail(string $tag): string
    {
        $email          = 'phpunit-stalesession-' . $tag . '-' . bin2hex(random_bytes(6)) . '@example.invalid';
        self::$emails[] = $email;

        return $email;
    }

    private function signUp(HttpClient $client, string $email): array
    {
        $csrf = HttpClient::extractCsrf($client->get('/backend/signup')['body']);

        return $client->post('/backend/signup/create', [
            'email'      => $email,
            'password'   => self::$password,
            'first_name' => 'PHPUnit',
            'last_name'  => 'StaleSession',
            $csrf['key'] => $csrf['token'],
        ]);
    }

    /**
     * A client logged in as a freshly signed-up account, and that
     * account's id.
     *
     * @return array{0: HttpClient, 1: int}
     */
    private function loggedInAsNewAccount(string $tag): array
    {
        $client = new HttpClient();
        $email  = self::newEmail($tag);

        $this->signUp($client, $email);

        $csrf  = HttpClient::extractCsrf($client->get('/backend/session')['body']);
        $login = $client->post('/backend/session/login', [
            'email'      => $email,
            'password'   => self::$password,
            $csrf['key'] => $csrf['token'],
        ]);
        $this->assertStringNotContainsString('Invalid email or password', $login['body'], 'fixture account failed to log in');

        $user = \Users::findFirst(['conditions' => 'email = :email:', 'bind' => ['email' => $email]]);
        $this->assertNotNull($user, 'fixture account was not created');

        return [$client, (int) $user->id];
    }

    /**
     * Removes the row outright, as a restored or recreated database
     * would. Its own audit entries have to go first: they are what the
     * foreign key protects.
     */
    private static function removeUserRow(int $userId): void
    {
        $db = \Phalcon\Di\Di::getDefault()->getShared('db');

        $db->execute('DELETE FROM audit_log WHERE actor_user_id = :id', ['id' => $userId]);
        $db->execute('DELETE FROM users WHERE id = :id', ['id' => $userId]);
    }

    public function testSignupSucceedsWhenTheSessionsUserNoLongerExists(): void
    {
        [$client, $goneUserId] = $this->loggedInAsNewAccount('gone');
        self::removeUserRow($goneUserId);

        $newEmail = self::newEmail('fresh');
        $response = $this->signUp($client, $newEmail);

        $this->assertSame(200, $response['status'], 'signing up with a session whose user is gone failed');
        $this->assertStringContainsString('Check your email', $response['body']);

        $newUser = \Users::findFirst(['conditions' => 'email = :email:', 'bind' => ['email' => $newEmail]]);
        $this->assertNotNull($newUser, 'the new account was not created');

        $audit = \AuditLog::findFirst([
            'conditions' => "entity_type = 'users' AND entity_id = :id: AND action = 'insert'",
            'bind'       => ['id' => $newUser->id],
        ]);
        $this->assertNotNull($audit, 'the signup was not audited at all');
        $this->assertNull($audit->actor_user_id, 'the signup was attributed to a user that does not exist');
    }

    public function testLogoutSucceedsWhenTheSessionsUserNoLongerExists(): void
    {
        [$client, $goneUserId] = $this->loggedInAsNewAccount('gone');
        self::removeUserRow($goneUserId);

        $response = $client->get('/backend/session/logout');

        $this->assertSame(200, $response['status'], 'logging out with a session whose user is gone failed');
        $this->assertStringContainsString('name="email"', $response['body'], 'logout did not end on the login page');

        $afterwards = $client->exchange('GET', '/backend');
        $this->assertSame(302, $afterwards['status']);

        // Still logged out with the same cookie jar: the failure used to
        // leave the session's user in place, so nobody could log out.
        $this->assertStringContainsString('name="email"', $client->get('/backend')['body'], 'the session was still logged in after logout');

        $audit = \AuditLog::findFirst([
            'conditions' => "entity_type = 'auth' AND action = 'logout' AND entity_id = :id:",
            'bind'       => ['id' => $goneUserId],
        ]);
        $this->assertNotNull($audit, 'the logout was not audited at all');
        $this->assertNull($audit->actor_user_id);
        $this->assertSame(['missing_actor_user_id' => $goneUserId], json_decode((string) $audit->new_values, true));
    }

    public function testLogoutOfASoftDeletedAccountIsStillAttributedToIt(): void
    {
        [$client, $userId] = $this->loggedInAsNewAccount('trashed');

        $user = \Users::findFirstById($userId);
        $this->assertTrue($user->softDelete());

        $response = $client->get('/backend/session/logout');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('name="email"', $response['body'], 'logout did not end on the login page');

        $audit = \AuditLog::findFirst([
            'conditions' => "entity_type = 'auth' AND action = 'logout' AND entity_id = :id:",
            'bind'       => ['id' => $userId],
        ]);
        $this->assertNotNull($audit, 'the logout was not audited at all');
        $this->assertSame($userId, (int) $audit->actor_user_id);
    }
}
