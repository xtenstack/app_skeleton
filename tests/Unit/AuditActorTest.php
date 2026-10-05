<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Phalcon\Di\FactoryDefault;

/**
 * Audit takes the acting user from the request's CurrentPrincipal, which
 * Auth (browser session) and ApiKeyAuth (API key) both set, and from
 * nowhere else. Covered here against a real database, with no HTTP: the
 * no-principal case every CLI task is in, a principal with and without an
 * API key.
 */
final class AuditActorTest extends TestCase
{
    private static \Phalcon\Di\DiInterface $di;

    private static int $userId;

    /** @var int[] */
    private static array $ticketIds = [];

    public static function setUpBeforeClass(): void
    {
        $di = new FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';

        self::$di = $di;
        \Phalcon\Di\Di::setDefault($di);

        $user                = new \Users();
        $user->email         = 'phpunit-auditactor-' . bin2hex(random_bytes(6)) . '@example.invalid';
        $user->password_hash = password_hash('PhpunitTest123!', PASSWORD_DEFAULT);
        $user->first_name    = 'PHPUnit';
        $user->last_name     = 'AuditActor';
        $user->role_id       = 2;
        $user->is_active     = 1;
        self::assertTrue($user->save(), 'fixture user failed to save: ' . implode('; ', $user->getMessages()));

        self::$userId = (int) $user->id;
    }

    public static function tearDownAfterClass(): void
    {
        self::$di->getShared('currentPrincipal')->clear();

        foreach (self::$ticketIds as $ticketId) {
            \Tickets::findFirstWithTrashed(['conditions' => 'id = :id:', 'bind' => ['id' => $ticketId]])?->delete();
        }

        \Users::findFirstWithTrashed(['conditions' => 'id = :id:', 'bind' => ['id' => self::$userId]])?->softDelete();
    }

    protected function setUp(): void
    {
        self::$di->getShared('currentPrincipal')->clear();
    }

    private function auditedTicketInsert(): \AuditLog
    {
        $ticket        = new \Tickets();
        $ticket->title = 'AuditActorTest fixture ' . bin2hex(random_bytes(4));
        $this->assertTrue($ticket->save(), 'fixture ticket failed to save: ' . implode('; ', $ticket->getMessages()));

        self::$ticketIds[] = (int) $ticket->id;

        $audit = \AuditLog::findFirst([
            'conditions' => "entity_type = 'tickets' AND entity_id = :id: AND action = 'insert'",
            'bind'       => ['id' => $ticket->id],
        ]);
        $this->assertInstanceOf(\AuditLog::class, $audit, 'the insert was not audited');

        return $audit;
    }

    private function latestEvent(string $action): \AuditLog
    {
        $audit = \AuditLog::findFirst([
            'conditions' => "entity_type = 'auth' AND action = :action:",
            'bind'       => ['action' => $action],
            'order'      => 'id DESC',
        ]);
        $this->assertInstanceOf(\AuditLog::class, $audit, 'the event was not recorded');

        return $audit;
    }

    public function testNoPrincipalRecordsNoActor(): void
    {
        $audit = $this->auditedTicketInsert();

        $this->assertNull($audit->actor_user_id);
        $this->assertNull($audit->actor_api_key_id);
    }

    public function testSessionPrincipalIsTheActorWithNoApiKey(): void
    {
        self::$di->getShared('currentPrincipal')->set(self::$userId, 2);

        $audit = $this->auditedTicketInsert();

        $this->assertSame(self::$userId, (int) $audit->actor_user_id);
        $this->assertNull($audit->actor_api_key_id);
    }

    public function testApiKeyPrincipalIsTheActorAndTheKeyIsRecorded(): void
    {
        self::$di->getShared('currentPrincipal')->set(self::$userId, 2, 987654);

        $audit = $this->auditedTicketInsert();

        $this->assertSame(self::$userId, (int) $audit->actor_user_id);
        $this->assertSame(987654, (int) $audit->actor_api_key_id);
    }

    public function testResolvingAnApiKeySetsThePrincipal(): void
    {
        $token = 'phpunit-auditactor-' . bin2hex(random_bytes(16));

        $apiKey               = new \ApiKeys();
        $apiKey->user_id      = self::$userId;
        $apiKey->name         = 'phpunit-auditactor-fixture-key';
        $apiKey->token_hash   = hash('sha256', $token);
        $apiKey->token_prefix = substr($token, 0, 10);
        $this->assertTrue($apiKey->save(), 'fixture api key failed to save: ' . implode('; ', $apiKey->getMessages()));

        try {
            $this->assertNotNull(self::$di->getShared('apiKeyAuth')->resolve($token));

            $principal = self::$di->getShared('currentPrincipal');
            $this->assertSame(self::$userId, $principal->userId());
            $this->assertSame(2, $principal->roleId());
            $this->assertSame((int) $apiKey->id, $principal->apiKeyId());
        } finally {
            $apiKey->delete();
        }
    }

    public function testEventRecordsTheApiKeyOnlyWhenTheActorIsThePrincipal(): void
    {
        self::$di->getShared('currentPrincipal')->set(self::$userId, 2, 987654);

        $own = 'phpunit_' . bin2hex(random_bytes(4));
        \App_skeleton\Audit::recordEvent($own, self::$userId);
        $ownEntry = $this->latestEvent($own);
        $this->assertSame(987654, (int) $ownEntry->actor_api_key_id);

        $other = 'phpunit_' . bin2hex(random_bytes(4));
        \App_skeleton\Audit::recordEvent($other, null);
        $otherEntry = $this->latestEvent($other);
        $this->assertNull($otherEntry->actor_api_key_id);

        $ownEntry->delete();
        $otherEntry->delete();
    }
}
