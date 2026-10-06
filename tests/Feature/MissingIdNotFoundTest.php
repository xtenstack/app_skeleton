<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A URL that leaves out an id the action needs (/backend/users/edit,
 * /api/tickets/view) used to answer 500: Phalcon calls editAction($id)
 * with nothing to pass, PHP throws ArgumentCountError, and the dispatcher
 * treated that like any crash. The dispatcher now answers 404 in that one
 * case (see services_web.php), for every module at once, with no PHP
 * warning or ErrorLog row left behind. Also covers ListView being handed
 * an array where it expects a search string (?q[]=x), which used to log
 * "Array to string conversion".
 *
 * Real HTTP against the real running stack; see HttpClient's docblock. The
 * log is read straight from logs/app.log, which is shared with the running
 * app when these run via `docker compose exec app`.
 */
final class MissingIdNotFoundTest extends TestCase
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

        self::$email = 'phpunit-missingid-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $user                = new \Users();
        $user->email         = self::$email;
        $user->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
        $user->first_name    = 'PHPUnit';
        $user->last_name     = 'MissingId';
        $user->role_id       = 1; // admin
        $user->is_active     = 1;
        self::assertTrue($user->save(), 'fixture admin failed to save: ' . implode('; ', $user->getMessages()));
        self::$userId = (int) $user->id;

        self::$apiToken = 'phpunit-missingid-' . bin2hex(random_bytes(16));

        $apiKey               = new \ApiKeys();
        $apiKey->user_id      = self::$userId;
        $apiKey->name         = 'phpunit-missingid-fixture-key';
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

    private function loggedInClient(): HttpClient
    {
        $client = new HttpClient();
        $csrf   = HttpClient::extractCsrf($client->get('/backend/session')['body']);

        $response = $client->post('/backend/session/login', [
            'email'      => self::$email,
            'password'   => self::$password,
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertStringNotContainsString('Invalid email or password', $response['body'], 'fixture admin failed to log in');

        return $client;
    }

    private static function logSize(): int
    {
        clearstatcache(true, BASE_PATH . '/logs/app.log');

        return is_file(BASE_PATH . '/logs/app.log') ? (int) filesize(BASE_PATH . '/logs/app.log') : 0;
    }

    /** What the app appended to its log since $offset (a logSize() reading). */
    private static function logSince(int $offset): string
    {
        $log = @file_get_contents(BASE_PATH . '/logs/app.log', false, null, $offset);

        return $log === false ? '' : $log;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function backendActionsMissingAnId(): array
    {
        return [
            'users/edit'    => ['/backend/users/edit'],
            'users/profile' => ['/backend/users/profile'],
            'users/delete'  => ['/backend/users/delete'],
        ];
    }

    /**
     * @dataProvider backendActionsMissingAnId
     */
    public function testBackendActionMissingItsIdIs404WithNoLogNoise(string $path): void
    {
        $client = $this->loggedInClient();
        $before = self::logSize();

        $response = $client->get($path);

        $this->assertSame(404, $response['status'], $path . ' did not answer 404');
        $this->assertStringContainsString('404', $response['body'], 'not the existing themed not-found page');
        $this->assertSame('', self::logSince($before), $path . ' wrote to the PHP log');
    }

    public function testBackendActionWithARealIdStillWorks(): void
    {
        $client = $this->loggedInClient();

        $response = $client->get('/backend/users/edit/' . self::$userId);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString(self::$email, $response['body']);
    }

    public function testApiActionMissingItsIdIsJson404(): void
    {
        $client = new HttpClient();
        $before = self::logSize();

        $response = $client->exchange('GET', '/api/tickets/view', ['X-Api-Key: ' . self::$apiToken]);

        $this->assertSame(404, $response['status']);
        $this->assertSame(['error' => 'Not Found'], json_decode($response['body'], true));
        $this->assertSame('', self::logSince($before), 'the api 404 wrote to the PHP log');
    }

    public function testGenuinelyUnknownActionsAre404AsBefore(): void
    {
        $client = $this->loggedInClient();

        $this->assertSame(404, $client->get('/backend/users/no-such-action')['status']);
        $this->assertSame(404, $client->get('/backend/no-such-controller')['status']);
    }

    public function testSearchTermThatIsAnArrayIsTreatedAsEmpty(): void
    {
        $client = $this->loggedInClient();
        $before = self::logSize();

        foreach (['/backend/users?q[]=x', '/backend/users?sort[]=x&dir[]=y&page[]=2'] as $path) {
            $response = $client->get($path);

            $this->assertSame(200, $response['status'], $path);
            $this->assertStringContainsString(self::$email, $response['body'], $path . ' should list as if no search were given');
        }

        $this->assertSame('', self::logSince($before), 'an array query parameter wrote to the PHP log');
    }
}
