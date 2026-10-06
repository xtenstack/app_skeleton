<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A request that never needs a session must not leave a file in the session
 * directory (ticket 75: crawlers and public endpoints were adding about
 * 61,000 files a day, and nothing removed them). The session now starts
 * only when something writes to it (login, a CSRF token for a form, a
 * flash message), or reads it when the request brought a session cookie;
 * see App_skeleton\LazySession.
 *
 * Real HTTP against the real running stack; see HttpClient's docblock. The
 * session directory is read straight off the disk, which the test shares
 * with the running app when run via `docker compose exec app`.
 */
final class SessionFilesTest extends TestCase
{
    private static string $password = 'PhpunitTest123!';

    private static string $email;
    private static int $userId;
    private static string $apiToken;
    private static int $apiKeyId;

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        self::$email = 'phpunit-sessionfiles-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $user                = new \Users();
        $user->email         = self::$email;
        $user->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
        $user->first_name    = 'PHPUnit';
        $user->last_name     = 'SessionFiles';
        $user->role_id       = 1; // admin
        $user->is_active     = 1;
        self::assertTrue($user->save(), 'fixture admin failed to save: ' . implode('; ', $user->getMessages()));
        self::$userId = (int) $user->id;

        self::$apiToken = 'phpunit-sessionfiles-' . bin2hex(random_bytes(16));

        $apiKey               = new \ApiKeys();
        $apiKey->user_id      = self::$userId;
        $apiKey->name         = 'phpunit-sessionfiles-fixture-key';
        $apiKey->token_hash   = hash('sha256', self::$apiToken);
        $apiKey->token_prefix = substr(self::$apiToken, 0, 10);
        self::assertTrue($apiKey->save(), 'fixture api key failed to save: ' . implode('; ', $apiKey->getMessages()));
        self::$apiKeyId = (int) $apiKey->id;
    }

    public static function tearDownAfterClass(): void
    {
        \ApiKeys::findFirstById(self::$apiKeyId)?->delete();

        $user = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => self::$email]]);
        $user?->softDelete();
    }

    private static function sessionFileCount(): int
    {
        return count(array_filter(
            scandir(BASE_PATH . '/sessions') ?: [],
            fn (string $name): bool => $name !== '.' && $name !== '..' && $name[0] !== '.'
        ));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function requestsThatNeedNoSession(): array
    {
        return [
            'guest landing page'        => ['/'],
            'public JSON endpoint'      => ['/api/public-kb/faq'],
            'unknown backend page'      => ['/backend/no-such-page'],
            'unmatched URL'             => ['/this-route-does-not-exist'],
            'backend page, logged out'  => ['/backend/users'],
            'api endpoint, no caller'   => ['/api/tickets'],
        ];
    }

    /**
     * @dataProvider requestsThatNeedNoSession
     */
    public function testAnonymousRequestLeavesNoSessionFile(string $path): void
    {
        $before = self::sessionFileCount();

        $response = (new HttpClient())->exchange('GET', $path);

        $this->assertLessThan(500, $response['status'], $path);
        $this->assertSame($before, self::sessionFileCount(), $path . ' left a session file behind');

        foreach ($response['headers'] as $header) {
            $this->assertStringNotContainsStringIgnoringCase('Set-Cookie: PHPSESSID', $header, $path . ' set a session cookie');
        }
    }

    public function testApiKeyRequestLeavesNoSessionFile(): void
    {
        $before = self::sessionFileCount();

        $response = (new HttpClient())->exchange('GET', '/api/tickets', ['X-Api-Key: ' . self::$apiToken]);

        $this->assertSame(200, $response['status']);
        $this->assertSame($before, self::sessionFileCount());
    }

    public function testLoginPageStartsASessionBecauseItHasAForm(): void
    {
        $before = self::sessionFileCount();

        $page = (new HttpClient())->exchange('GET', '/backend/session');

        $this->assertSame(200, $page['status']);
        $this->assertSame($before + 1, self::sessionFileCount());
        $this->assertNotEmpty(HttpClient::extractCsrf($page['body'])['token']);
    }

    public function testLoginUsesOneSessionFileAndLoggedInRequestsAddNone(): void
    {
        $client = new HttpClient();
        $before = self::sessionFileCount();

        $csrf = HttpClient::extractCsrf($client->get('/backend/session')['body']);
        $this->assertSame($before + 1, self::sessionFileCount(), 'the login page should open exactly one session');

        $login = $client->post('/backend/session/login', [
            'email'      => self::$email,
            'password'   => self::$password,
            $csrf['key'] => $csrf['token'],
        ]);
        $this->assertStringNotContainsString('Invalid email or password', $login['body'], 'fixture admin failed to log in');

        foreach (['/backend', '/backend/users', '/backend/users/edit/' . self::$userId] as $path) {
            $page = $client->get($path);

            $this->assertSame(200, $page['status'], $path . ' as a logged-in admin');
        }

        $this->assertSame($before + 1, self::sessionFileCount(), 'login and logged-in pages should reuse the one session');

        $client->get('/backend/session/logout');
        $this->assertSame($before + 1, self::sessionFileCount());
    }

    public function testFlashMessageStartsASession(): void
    {
        $before = self::sessionFileCount();

        // A POST with no session and no token is refused with a flashed
        // "session expired" error: a write, so it needs a session to hold
        // the message for the page it redirects to. (Not a wrong password:
        // failed logins feed the login rate limit, which would make this
        // test flaky.)
        $reply = (new HttpClient())->exchange('POST', '/backend/session/login', [], http_build_query([
            'email'    => self::$email,
            'password' => self::$password,
        ]));

        $this->assertSame(302, $reply['status']);
        $this->assertSame($before + 1, self::sessionFileCount());
        $this->assertStringContainsString('Set-Cookie: PHPSESSID', implode("\n", $reply['headers']));
    }
}
