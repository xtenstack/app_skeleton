<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * GET /api/tickets and /api/tickets/view/{id} used to answer any
 * authenticated caller with every ticket, including a self-service signup
 * member. Admin, operator and agent callers still see all; anyone else sees
 * only the tickets they raised, and retest-result is staff-only. (self-assign stays open to any caller by design: ApiTicketsSelfAssignControllerTest.)
 * Real HTTP against the running stack.
 */
final class ApiTicketsAccessTest extends TestCase
{
    /** @var int[] */
    private static array $userIds = [];
    /** @var int[] */
    private static array $keyIds = [];
    private static int $ticketId;
    private static string $ownerToken;
    private static string $strangerToken;
    private static string $operatorToken;
    private static string $agentToken;

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        $member   = \Roles::idsByNames(['member'])[0] ?? null;
        $operator = \Roles::idsByNames(['operator'])[0] ?? null;
        $agent    = \Roles::idsByNames(['agent'])[0] ?? null;
        self::assertNotNull($member);
        self::assertNotNull($operator);
        self::assertNotNull($agent);

        $tag = bin2hex(random_bytes(5));
        [$ownerUser, self::$ownerToken, $ownerKey] = self::actor("owner-$tag", (int) $member);
        [, self::$strangerToken] = self::actor("stranger-$tag", (int) $member);
        [, self::$operatorToken] = self::actor("operator-$tag", (int) $operator);
        [, self::$agentToken] = self::actor("agent-$tag", (int) $agent);

        $ticket                      = new \Tickets();
        $ticket->title               = 'phpunit-access-fixture-' . $tag;
        $ticket->description         = 'private fixture ticket';
        $ticket->severity            = 'normal';
        $ticket->ticket_type         = 'support';
        $ticket->status              = 'open';
        $ticket->source_ref          = 'phpunit:' . $tag;
        $ticket->reporter_user_id    = $ownerUser;
        $ticket->reporter_api_key_id = $ownerKey;
        self::assertTrue($ticket->save(), implode('; ', $ticket->getMessages()));
        self::$ticketId = (int) $ticket->id;
    }

    public static function tearDownAfterClass(): void
    {
        \Tickets::findFirstById(self::$ticketId)?->delete();

        foreach (self::$keyIds as $id) {
            \ApiKeys::findFirstById($id)?->delete();
        }

        foreach (self::$userIds as $id) {
            \Users::findFirstWithTrashed(['conditions' => 'id = :id:', 'bind' => ['id' => $id]])?->softDelete();
        }
    }

    /** @return array{0: int, 1: string, 2: int} */
    private static function actor(string $label, int $roleId): array
    {
        $user                = new \Users();
        $user->email         = "phpunit-ticketacl-$label@example.invalid";
        $user->password_hash = password_hash('PhpunitTest123!', PASSWORD_DEFAULT);
        $user->first_name    = 'PHPUnit';
        $user->last_name     = 'TicketAccess';
        $user->role_id       = $roleId;
        $user->is_active     = 1;
        self::assertTrue($user->save(), implode('; ', $user->getMessages()));
        self::$userIds[] = (int) $user->id;

        $token             = "phpunit-ticketacl-$label-" . bin2hex(random_bytes(12));
        $key               = new \ApiKeys();
        $key->user_id      = (int) $user->id;
        $key->name         = "phpunit-ticketacl-$label";
        $key->token_hash   = hash('sha256', $token);
        $key->token_prefix = substr($token, 0, 10);
        self::assertTrue($key->save(), implode('; ', $key->getMessages()));
        self::$keyIds[] = (int) $key->id;

        return [(int) $user->id, $token, (int) $key->id];
    }

    private function call(string $method, string $path, string $token): array
    {
        $r = (new HttpClient())->exchange($method, $path, ['X-Api-Key: ' . $token]);
        $r['json'] = json_decode($r['body'], true);

        return $r;
    }

    private static function ids(array $response): array
    {
        return array_map(fn ($t) => (int) $t['id'], $response['json']['tickets'] ?? []);
    }

    public function testOwnerSeesTheirTicketAndStrangerDoesNot(): void
    {
        $this->assertContains(self::$ticketId, self::ids($this->call('GET', '/api/tickets', self::$ownerToken)));
        $this->assertNotContains(self::$ticketId, self::ids($this->call('GET', '/api/tickets', self::$strangerToken)));
    }

    public function testStaffAndAgentsStillSeeEveryTicket(): void
    {
        $this->assertContains(self::$ticketId, self::ids($this->call('GET', '/api/tickets', self::$operatorToken)));
        $this->assertContains(self::$ticketId, self::ids($this->call('GET', '/api/tickets', self::$agentToken)));
    }

    public function testViewIs404ForAStrangerAndOkForOwnerAndStaff(): void
    {
        $path = '/api/tickets/view/' . self::$ticketId;

        $this->assertSame(404, $this->call('GET', $path, self::$strangerToken)['status']);
        $this->assertSame(200, $this->call('GET', $path, self::$ownerToken)['status']);
        $this->assertSame(200, $this->call('GET', $path, self::$operatorToken)['status']);
        $this->assertSame(200, $this->call('GET', $path, self::$agentToken)['status']);
    }

    public function testRetestResultIsStaffOnly(): void
    {
        $r = $this->call('POST', '/api/tickets/retest-result/' . self::$ticketId, self::$strangerToken);
        $this->assertSame(403, $r['status'], 'retest-result can close any ticket, so a member is refused');
    }
}
