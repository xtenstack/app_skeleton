<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * api\TicketsController::selfAssignAction() coverage (Tim/SSA
 * integration, 2026-09-05) — see that method's docblock and the Tickets
 * model docblock for why this is deliberately narrower than general
 * assignment: self-only (no target user id accepted), unassigned-only
 * (won't steal a ticket from an existing assignee), and unrestricted by
 * role — same pattern as createAction(), any authenticated principal
 * (agents included) may call it, since the only thing it can ever do is
 * assign the ticket to the caller themselves.
 *
 * Real HTTP against the real running stack — see HttpClient's own
 * docblock for why this isn't dispatched in-process.
 */
final class ApiTicketsSelfAssignControllerTest extends TestCase
{
    private static string $agentEmail;
    private static string $memberEmail;
    private static string $password = 'PhpunitTest123!';

    private static int $agentId;
    private static int $memberId;

    private static string $agentApiKeyRawToken;
    private static int $agentApiKeyId;

    /** @var int[] */
    private static array $ticketIds = [];

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        $agentRoleId = \Roles::idsByNames(['agent'])[0] ?? null;
        self::assertNotNull($agentRoleId, "fixture setup requires an 'agent' role to exist — run ./run seed");

        self::$agentEmail  = 'phpunit-apiselfassign-agent-' . bin2hex(random_bytes(6)) . '@example.invalid';
        self::$memberEmail = 'phpunit-apiselfassign-member-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $agent                = new \Users();
        $agent->email         = self::$agentEmail;
        $agent->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
        $agent->first_name    = 'PHPUnit';
        $agent->last_name     = 'ApiSelfAssignAgent';
        $agent->role_id       = $agentRoleId;
        $agent->is_active     = 1;
        self::assertTrue($agent->save(), 'fixture agent failed to save: ' . implode('; ', $agent->getMessages()));
        self::$agentId = (int) $agent->id;

        $member                = new \Users();
        $member->email         = self::$memberEmail;
        $member->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
        $member->first_name    = 'PHPUnit';
        $member->last_name     = 'ApiSelfAssignMember';
        $member->role_id       = 2; // member
        $member->is_active     = 1;
        self::assertTrue($member->save(), 'fixture member failed to save: ' . implode('; ', $member->getMessages()));
        self::$memberId = (int) $member->id;

        self::$agentApiKeyRawToken = 'phpunit-apiselfassign-token-' . bin2hex(random_bytes(16));

        $apiKey               = new \ApiKeys();
        $apiKey->user_id      = self::$agentId;
        $apiKey->name         = 'phpunit-apiselfassign-fixture-key';
        $apiKey->token_hash   = hash('sha256', self::$agentApiKeyRawToken);
        $apiKey->token_prefix = substr(self::$agentApiKeyRawToken, 0, 10);
        self::assertTrue($apiKey->save(), 'fixture api key failed to save: ' . implode('; ', $apiKey->getMessages()));
        self::$agentApiKeyId = (int) $apiKey->id;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$ticketIds as $ticketId) {
            \Tickets::findFirstById($ticketId)?->delete();
        }

        $apiKey = \ApiKeys::findFirstById(self::$agentApiKeyId);
        $apiKey?->delete();

        foreach ([self::$agentEmail, self::$memberEmail] as $email) {
            $user = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => $email]]);
            $user?->softDelete();
        }
    }

    private function newTicket(): \Tickets
    {
        $ticket              = new \Tickets();
        $ticket->title       = 'phpunit-apiselfassign-fixture-' . bin2hex(random_bytes(4));
        $ticket->description = 'fixture ticket for ApiTicketsSelfAssignControllerTest';
        $ticket->severity    = 'normal';
        $ticket->ticket_type = 'support';
        $ticket->status      = 'open';
        self::assertTrue($ticket->save(), 'fixture ticket failed to save: ' . implode('; ', $ticket->getMessages()));

        self::$ticketIds[] = (int) $ticket->id;

        return $ticket;
    }

    private function loggedInClient(string $email): HttpClient
    {
        $client    = new HttpClient();
        $loginPage = $client->get('/backend/session');
        $csrf      = HttpClient::extractCsrf($loginPage['body']);

        $response = $client->post('/backend/session/login', [
            'email'      => $email,
            'password'   => self::$password,
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertStringNotContainsString(
            'Invalid email or password',
            $response['body'],
            'fixture user failed to log in — can\'t test the controller without a real authenticated session'
        );

        return $client;
    }

    public function testAgentCanSelfAssignAnUnassignedTicketViaApiKeyAuth(): void
    {
        $ticket = $this->newTicket();
        $client = new HttpClient();

        $response = $client->postJson(
            '/api/tickets/self-assign/' . $ticket->id,
            [],
            ['X-Api-Key: ' . self::$agentApiKeyRawToken]
        );

        $this->assertSame(200, $response['status'], 'selfAssignAction did not return 200: ' . $response['body']);

        $ticket->refresh();
        $this->assertSame(self::$agentId, (int) $ticket->assigned_to_user_id, 'ticket was not assigned to the calling agent');

        $payload = json_decode($response['body'], true);
        $this->assertSame(self::$agentId, $payload['ticket']['assigned_to_user_id'] ?? null);
    }

    public function testSelfAssignIsIdempotentForTheSameCaller(): void
    {
        $ticket = $this->newTicket();
        $client = new HttpClient();

        $first = $client->postJson('/api/tickets/self-assign/' . $ticket->id, [], ['X-Api-Key: ' . self::$agentApiKeyRawToken]);
        $this->assertSame(200, $first['status']);

        $second = $client->postJson('/api/tickets/self-assign/' . $ticket->id, [], ['X-Api-Key: ' . self::$agentApiKeyRawToken]);
        $this->assertSame(200, $second['status'], 'claiming an already-self-assigned ticket again should succeed, not conflict');

        $ticket->refresh();
        $this->assertSame(self::$agentId, (int) $ticket->assigned_to_user_id);
    }

    public function testSelfAssignRejectsATicketAlreadyAssignedToSomeoneElse(): void
    {
        $ticket                      = $this->newTicket();
        $ticket->assigned_to_user_id = self::$memberId;
        self::assertTrue($ticket->save());

        $client   = new HttpClient();
        $response = $client->postJson('/api/tickets/self-assign/' . $ticket->id, [], ['X-Api-Key: ' . self::$agentApiKeyRawToken]);

        $this->assertSame(409, $response['status'], 'claiming a ticket already assigned to someone else should conflict, not silently reassign it');

        $ticket->refresh();
        $this->assertSame(self::$memberId, (int) $ticket->assigned_to_user_id, 'existing assignment must not be overwritten by a failed claim attempt');
    }

    public function testMemberRoleCanAlsoSelfAssignViaSessionAuth(): void
    {
        // Deliberately unrestricted by role, same pattern as
        // createAction() — the only thing this endpoint can ever do is
        // assign the ticket to the authenticated caller themselves, so
        // there's nothing a role gate would protect against here.
        $ticket = $this->newTicket();
        $client = $this->loggedInClient(self::$memberEmail);

        $response = $client->postJson('/api/tickets/self-assign/' . $ticket->id, []);

        $this->assertSame(200, $response['status'], 'member role should be able to self-assign: ' . $response['body']);

        $ticket->refresh();
        $this->assertSame(self::$memberId, (int) $ticket->assigned_to_user_id);
    }

    public function testSelfAssignWithoutAnyAuthIsRejected(): void
    {
        $ticket = $this->newTicket();
        $client = new HttpClient();

        $response = $client->postJson('/api/tickets/self-assign/' . $ticket->id, []);

        $this->assertSame(401, $response['status'], 'an unauthenticated caller should not be able to self-assign a ticket');

        $ticket->refresh();
        $this->assertNull($ticket->assigned_to_user_id, 'ticket was assigned despite no authentication');
    }

    public function testSelfAssignOnUnknownTicketReturns404(): void
    {
        $client = new HttpClient();

        $response = $client->postJson(
            '/api/tickets/self-assign/999999999',
            [],
            ['X-Api-Key: ' . self::$agentApiKeyRawToken]
        );

        $this->assertSame(404, $response['status']);
    }

    public function testSelfAssignRequiresPost(): void
    {
        $ticket = $this->newTicket();
        $client = $this->loggedInClient(self::$memberEmail);

        $response = $client->get('/api/tickets/self-assign/' . $ticket->id);

        $this->assertSame(405, $response['status']);

        $ticket->refresh();
        $this->assertNull($ticket->assigned_to_user_id);
    }
}
