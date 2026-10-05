<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * An API key is a credential for one request, never the start of a
 * browser session. A request that presents one must come back with no
 * session cookie, must leave no identity in any session (a new one, or
 * one whose cookie was sent along with the key), and must be judged on
 * the key alone. The failure this guards against: the key's user was
 * copied into the PHP session, and the PHPSESSID in the response then
 * opened /backend as that user with no key at all.
 *
 * The other half is that nothing changes for a browser: /api called with
 * a real login session and no key works as before.
 *
 * Real HTTP against the real running stack; see HttpClient's docblock.
 */
final class ApiKeyStatelessTest extends TestCase
{
    private static string $password = 'PhpunitTest123!';

    private static string $adminEmail;
    private static int $adminId;
    private static string $adminToken;
    private static int $adminKeyId;

    private static string $memberEmail;
    private static int $memberId;

    /** @var int[] */
    private static array $ticketIds = [];

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        // An admin's key is the worst case: the session it used to leave
        // behind passed every role gate in the backend.
        self::$adminEmail = 'phpunit-apikeystateless-admin-' . bin2hex(random_bytes(6)) . '@example.invalid';
        self::$adminId    = self::makeUser(self::$adminEmail, 1);

        self::$memberEmail = 'phpunit-apikeystateless-member-' . bin2hex(random_bytes(6)) . '@example.invalid';
        self::$memberId    = self::makeUser(self::$memberEmail, 2);

        self::$adminToken = 'phpunit-apikeystateless-' . bin2hex(random_bytes(16));

        $apiKey               = new \ApiKeys();
        $apiKey->user_id      = self::$adminId;
        $apiKey->name         = 'phpunit-apikeystateless-fixture-key';
        $apiKey->token_hash   = hash('sha256', self::$adminToken);
        $apiKey->token_prefix = substr(self::$adminToken, 0, 10);
        self::assertTrue($apiKey->save(), 'fixture api key failed to save: ' . implode('; ', $apiKey->getMessages()));
        self::$adminKeyId = (int) $apiKey->id;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$ticketIds as $ticketId) {
            \Tickets::findFirstWithTrashed(['conditions' => 'id = :id:', 'bind' => ['id' => $ticketId]])?->delete();
        }

        \ApiKeys::findFirstById(self::$adminKeyId)?->delete();

        foreach ([self::$adminEmail, self::$memberEmail] as $email) {
            $user = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => $email]]);
            $user?->softDelete();
        }
    }

    private static function makeUser(string $email, int $roleId): int
    {
        $user                = new \Users();
        $user->email         = $email;
        $user->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
        $user->first_name    = 'PHPUnit';
        $user->last_name     = 'ApiKeyStateless';
        $user->role_id       = $roleId;
        $user->is_active     = 1;
        self::assertTrue($user->save(), 'fixture user failed to save: ' . implode('; ', $user->getMessages()));

        return (int) $user->id;
    }

    /**
     * Logs in through the real form and returns the session cookie as a
     * request header, for exchange() (which keeps no cookie jar).
     */
    private function loginCookie(string $email): string
    {
        $client = new HttpClient();

        $loginPage = $client->exchange('GET', '/backend/session');
        $cookie    = self::sessionCookie($loginPage['headers']);
        $this->assertNotNull($cookie, 'the login page set no session cookie');

        $csrf = HttpClient::extractCsrf($loginPage['body']);

        $login = $client->exchange('POST', '/backend/session/login', ['Cookie: ' . $cookie], http_build_query([
            'email'      => $email,
            'password'   => self::$password,
            $csrf['key'] => $csrf['token'],
        ]));

        $this->assertSame(302, $login['status'], 'fixture user failed to log in');

        return $cookie;
    }

    /**
     * "PHPSESSID=…" from a response's Set-Cookie lines, or null if it set
     * no session cookie.
     *
     * @param string[] $headers
     */
    private static function sessionCookie(array $headers): ?string
    {
        foreach ($headers as $line) {
            if (preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $line, $match)) {
                return $match[1];
            }
        }

        return null;
    }

    /** @param string[] $headers */
    private function assertNoCookieSet(array $headers, string $what): void
    {
        foreach ($headers as $line) {
            $this->assertDoesNotMatchRegularExpression('/^Set-Cookie:/i', $line, $what . ' set a cookie');
        }
    }

    /** @return array<string, array{string}> */
    public static function keyHeaders(): array
    {
        return [
            'Authorization: Bearer' => ['Authorization: Bearer %s'],
            'X-Api-Key'             => ['X-Api-Key: %s'],
        ];
    }

    /**
     * @dataProvider keyHeaders
     */
    public function testApiKeyRequestSetsNoSessionCookie(string $headerFormat): void
    {
        $client = new HttpClient();
        $key    = sprintf($headerFormat, self::$adminToken);

        $read = $client->exchange('GET', '/api/tickets', [$key]);
        $this->assertSame(200, $read['status'], 'the key did not authenticate: ' . $read['body']);
        $this->assertNoCookieSet($read['headers'], 'an API-key read');

        $write = $client->exchange('POST', '/api/tickets/create', [$key, 'Content-Type: application/json'], json_encode([
            'title' => 'phpunit-apikeystateless-fixture-' . bin2hex(random_bytes(4)),
        ]));
        $this->assertSame(201, $write['status'], 'the key could not create a ticket: ' . $write['body']);
        $this->assertNoCookieSet($write['headers'], 'an API-key write');
        self::$ticketIds[] = (int) json_decode($write['body'], true)['ticket']['id'];

        $unknown = $client->exchange('GET', '/api/no-such-controller', [$key]);
        $this->assertSame(404, $unknown['status']);
        $this->assertNoCookieSet($unknown['headers'], 'an API-key request to an unknown endpoint');

        $rejected = $client->exchange('GET', '/api/tickets', [sprintf($headerFormat, 'not-a-real-key')]);
        $this->assertSame(401, $rejected['status']);
        $this->assertNoCookieSet($rejected['headers'], 'a rejected API key');
    }

    public function testApiKeyCannotBeTurnedIntoBackendAccess(): void
    {
        $client = new HttpClient();

        // The attacker's best case: a session that already exists (any
        // visitor gets one from the login page), sent along with the key
        // so there is a session for the key's user to be written into.
        $cookie = self::sessionCookie($client->exchange('GET', '/backend/session')['headers']);
        $this->assertNotNull($cookie, 'the login page set no session cookie');

        $withKey = $client->exchange('GET', '/api/tickets', ['X-Api-Key: ' . self::$adminToken, 'Cookie: ' . $cookie]);
        $this->assertSame(200, $withKey['status'], 'the key did not authenticate: ' . $withKey['body']);
        $this->assertNoCookieSet($withKey['headers'], 'an API-key request');

        foreach (['/backend', '/backend/users', '/backend/api-keys'] as $path) {
            $replay = $client->exchange('GET', $path, ['Cookie: ' . $cookie]);

            $this->assertSame(302, $replay['status'], "{$path} opened for a session cookie that had only ever been sent with an API key");
            $this->assertStringContainsString('backend/session', implode("\n", $replay['headers']));
        }

        $api = $client->exchange('GET', '/api/tickets', ['Cookie: ' . $cookie]);
        $this->assertSame(401, $api['status'], 'the cookie alone authenticated an /api call');
    }

    public function testApiKeyIsTheWholeIdentityEvenWithALoginSessionCookie(): void
    {
        $client       = new HttpClient();
        $memberCookie = $this->loginCookie(self::$memberEmail);

        // Key and session name different users: the key decides.
        $create = $client->exchange(
            'POST',
            '/api/tickets/create',
            ['X-Api-Key: ' . self::$adminToken, 'Cookie: ' . $memberCookie, 'Content-Type: application/json'],
            json_encode(['title' => 'phpunit-apikeystateless-fixture-' . bin2hex(random_bytes(4))])
        );
        $this->assertSame(201, $create['status'], $create['body']);

        $ticketId          = (int) json_decode($create['body'], true)['ticket']['id'];
        self::$ticketIds[] = $ticketId;

        $ticket = \Tickets::findFirstById($ticketId);
        $this->assertSame(self::$adminId, (int) $ticket->reporter_user_id, 'the session cookie, not the key, decided who the caller was');
        $this->assertSame(self::$adminKeyId, (int) $ticket->reporter_api_key_id);

        // A key that doesn't resolve is refused; the valid session sent
        // with it is not a fallback.
        $badKey = $client->exchange('GET', '/api/tickets', ['X-Api-Key: not-a-real-key', 'Cookie: ' . $memberCookie]);
        $this->assertSame(401, $badKey['status'], 'a bad key fell back to the session cookie sent with it');

        // And the member's own session is untouched by either request.
        $own = $client->exchange('GET', '/api/session/me', ['Cookie: ' . $memberCookie]);
        $this->assertSame(200, $own['status']);
        $this->assertSame(self::$memberId, (int) json_decode($own['body'], true)['user']['id']);
    }

    public function testSessionEndpointsRefuseAnApiKey(): void
    {
        $client = new HttpClient();

        $me = $client->exchange('GET', '/api/session/me', ['X-Api-Key: ' . self::$adminToken]);

        $this->assertSame(400, $me['status']);
        $this->assertNoCookieSet($me['headers'], '/api/session/me called with an API key');
    }

    public function testSessionAuthenticatedApiCallStillWorks(): void
    {
        $client = new HttpClient();
        $cookie = $this->loginCookie(self::$memberEmail);

        $list = $client->exchange('GET', '/api/tickets', ['Cookie: ' . $cookie]);
        $this->assertSame(200, $list['status'], 'a logged-in browser session could not read /api: ' . $list['body']);

        $create = $client->exchange(
            'POST',
            '/api/tickets/create',
            ['Cookie: ' . $cookie, 'Content-Type: application/json'],
            json_encode(['title' => 'phpunit-apikeystateless-fixture-' . bin2hex(random_bytes(4))])
        );
        $this->assertSame(201, $create['status'], 'a logged-in browser session could not write through /api: ' . $create['body']);

        $ticketId          = (int) json_decode($create['body'], true)['ticket']['id'];
        self::$ticketIds[] = $ticketId;

        $ticket = \Tickets::findFirstById($ticketId);
        $this->assertSame(self::$memberId, (int) $ticket->reporter_user_id);
        $this->assertNull($ticket->reporter_api_key_id);

        $audit = \AuditLog::findFirst([
            'conditions' => "entity_type = 'tickets' AND entity_id = :id: AND action = 'insert'",
            'bind'       => ['id' => $ticketId],
        ]);
        $this->assertNotNull($audit, 'the ticket insert was not audited');
        $this->assertSame(self::$memberId, (int) $audit->actor_user_id);
        $this->assertNull($audit->actor_api_key_id);
    }

    public function testApiKeyWriteIsAuditedAgainstTheKeysUserAndTheKey(): void
    {
        $client = new HttpClient();

        $create = $client->exchange(
            'POST',
            '/api/tickets/create',
            ['Authorization: Bearer ' . self::$adminToken, 'Content-Type: application/json'],
            json_encode(['title' => 'phpunit-apikeystateless-fixture-' . bin2hex(random_bytes(4))])
        );
        $this->assertSame(201, $create['status'], $create['body']);

        $ticketId          = (int) json_decode($create['body'], true)['ticket']['id'];
        self::$ticketIds[] = $ticketId;

        $audit = \AuditLog::findFirst([
            'conditions' => "entity_type = 'tickets' AND entity_id = :id: AND action = 'insert'",
            'bind'       => ['id' => $ticketId],
        ]);

        $this->assertNotNull($audit, 'the ticket insert was not audited');
        $this->assertSame(self::$adminId, (int) $audit->actor_user_id, 'an API-key write was audited with the wrong actor, or none');
        $this->assertSame(self::$adminKeyId, (int) $audit->actor_api_key_id, 'the audit entry does not say which key made the change');
    }
}
