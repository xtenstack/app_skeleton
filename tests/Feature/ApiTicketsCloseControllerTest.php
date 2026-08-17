<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REQ-168: api\TicketsController::closeAction() coverage. Session 18
 * extended ticket-closing authority to the API for admin/operator
 * callers given a real close_reason (see this repo's CLAUDE.md and the
 * controller's own docblock) — everything else in api\TicketsController
 * stays reachable by any authenticated principal (agents included),
 * closeAction() is the one deliberate exception, gated per-action
 * rather than via ControllerBase's class-wide $allowedRoles.
 *
 * Covers: both supported auth paths (session cookie and X-Api-Key),
 * that unauthenticated and insufficient-role (member) callers are
 * rejected, that close_reason is required, unknown-ticket 404, and that
 * the side effects (status/closed_at/close_reason, plus the optional
 * notes field) actually land — not just that the response looks right.
 * Real HTTP against the real running stack — see HttpClient's own
 * docblock for why this isn't dispatched in-process.
 */
final class ApiTicketsCloseControllerTest extends TestCase
{
    private static string $adminEmail;
    private static string $operatorEmail;
    private static string $memberEmail;
    private static string $password = 'PhpunitTest123!';

    private static int $adminId;
    private static int $operatorId;
    private static int $memberId;

    private static string $apiKeyRawToken;
    private static int $apiKeyId;

    /** @var int[] */
    private static array $ticketIds = [];

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        $operatorRoleId = \Roles::idsByNames(['operator'])[0] ?? null;
        self::assertNotNull($operatorRoleId, "fixture setup requires an 'operator' role to exist — run ./run seed");

        self::$adminEmail    = 'phpunit-apiclose-admin-' . bin2hex(random_bytes(6)) . '@example.invalid';
        self::$operatorEmail = 'phpunit-apiclose-operator-' . bin2hex(random_bytes(6)) . '@example.invalid';
        self::$memberEmail   = 'phpunit-apiclose-member-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $admin                = new \Users();
        $admin->email         = self::$adminEmail;
        $admin->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
        $admin->first_name    = 'PHPUnit';
        $admin->last_name     = 'ApiTicketsCloseAdmin';
        $admin->role_id       = 1; // admin
        $admin->is_active     = 1;
        self::assertTrue($admin->save(), 'fixture admin failed to save: ' . implode('; ', $admin->getMessages()));
        self::$adminId = (int) $admin->id;

        $operator                = new \Users();
        $operator->email         = self::$operatorEmail;
        $operator->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
        $operator->first_name    = 'PHPUnit';
        $operator->last_name     = 'ApiTicketsCloseOperator';
        $operator->role_id       = $operatorRoleId;
        $operator->is_active     = 1;
        self::assertTrue($operator->save(), 'fixture operator failed to save: ' . implode('; ', $operator->getMessages()));
        self::$operatorId = (int) $operator->id;

        $member                = new \Users();
        $member->email         = self::$memberEmail;
        $member->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
        $member->first_name    = 'PHPUnit';
        $member->last_name     = 'ApiTicketsCloseMember';
        $member->role_id       = 2; // member
        $member->is_active     = 1;
        self::assertTrue($member->save(), 'fixture member failed to save: ' . implode('; ', $member->getMessages()));
        self::$memberId = (int) $member->id;

        self::$apiKeyRawToken = 'phpunit-apiclose-token-' . bin2hex(random_bytes(16));

        $apiKey               = new \ApiKeys();
        $apiKey->user_id      = self::$adminId;
        $apiKey->name         = 'phpunit-apiclose-fixture-key';
        $apiKey->token_hash   = hash('sha256', self::$apiKeyRawToken);
        $apiKey->token_prefix = substr(self::$apiKeyRawToken, 0, 10);
        self::assertTrue($apiKey->save(), 'fixture api key failed to save: ' . implode('; ', $apiKey->getMessages()));
        self::$apiKeyId = (int) $apiKey->id;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$ticketIds as $ticketId) {
            \Tickets::findFirstById($ticketId)?->delete();
        }

        $apiKey = \ApiKeys::findFirstById(self::$apiKeyId);
        $apiKey?->delete();

        foreach ([self::$adminEmail, self::$operatorEmail, self::$memberEmail] as $email) {
            $user = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => $email]]);
            $user?->softDelete();
        }
    }

    private function newTicket(): \Tickets
    {
        $ticket              = new \Tickets();
        $ticket->title       = 'phpunit-apiclose-fixture-' . bin2hex(random_bytes(4));
        $ticket->description = 'fixture ticket for ApiTicketsCloseControllerTest';
        $ticket->severity    = 'normal';
        $ticket->ticket_type = 'bug';
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

    public function testAdminCanCloseTicketViaSessionAuthAndSideEffectsMatchBackendClose(): void
    {
        $ticket = $this->newTicket();
        $client = $this->loggedInClient(self::$adminEmail);

        $response = $client->postJson('/api/tickets/close/' . $ticket->id, [
            'close_reason' => 'fixed',
            'notes'        => 'Root cause: race condition in the mailer retry queue. Fixed by serializing retries — see ticket #19 fix.',
        ]);

        $this->assertSame(200, $response['status'], 'closeAction did not return 200: ' . $response['body']);

        $ticket->refresh();
        $this->assertSame('closed', $ticket->status, 'closeAction did not set status to closed');
        $this->assertNotNull($ticket->closed_at, 'closeAction did not set closed_at (same field the backend close action sets)');
        $this->assertSame('fixed', $ticket->close_reason);
        $this->assertSame('Root cause: race condition in the mailer retry queue. Fixed by serializing retries — see ticket #19 fix.', $ticket->notes);

        $payload = json_decode($response['body'], true);
        $this->assertSame('closed', $payload['ticket']['status'] ?? null);
        $this->assertSame($ticket->close_reason, $payload['ticket']['close_reason'] ?? null);
        $this->assertArrayNotHasKey('notes', $payload['ticket'], 'notes is staff-only and must never be returned by the API (migration 012\'s own comment)');
    }

    public function testCloseRejectsACloseReasonLongerThanTheColumnLimit(): void
    {
        $ticket = $this->newTicket();
        $client = $this->loggedInClient(self::$adminEmail);

        $response = $client->postJson('/api/tickets/close/' . $ticket->id, [
            'close_reason' => 'this-close-reason-is-way-too-long-for-the-column',
        ]);

        $this->assertSame(422, $response['status'], 'close_reason over 20 chars (tickets.close_reason is VARCHAR(20)) should be rejected, not 500');

        $ticket->refresh();
        $this->assertSame('open', $ticket->status, 'ticket status changed despite an over-length close_reason');
    }

    public function testOperatorCanCloseTicketViaSessionAuth(): void
    {
        $ticket = $this->newTicket();
        $client = $this->loggedInClient(self::$operatorEmail);

        $response = $client->postJson('/api/tickets/close/' . $ticket->id, [
            'close_reason' => 'duplicate',
            'notes'        => 'Duplicate of #12, closing as such.',
        ]);

        $this->assertSame(200, $response['status'], 'operator role should be allowed to close: ' . $response['body']);

        $ticket->refresh();
        $this->assertSame('closed', $ticket->status);
    }

    public function testAdminCanCloseTicketViaApiKeyAuth(): void
    {
        $ticket = $this->newTicket();
        $client = new HttpClient();

        $response = $client->postJson(
            '/api/tickets/close/' . $ticket->id,
            [
                'close_reason' => 'fixed',
                'notes'        => 'Closed via API key — verified fix in prod.',
            ],
            ['X-Api-Key: ' . self::$apiKeyRawToken]
        );

        $this->assertSame(200, $response['status'], 'API-key auth should be able to close: ' . $response['body']);

        $ticket->refresh();
        $this->assertSame('closed', $ticket->status);
        $this->assertSame('fixed', $ticket->close_reason);
        $this->assertSame('Closed via API key — verified fix in prod.', $ticket->notes);
    }

    public function testCloseWithoutAnyAuthIsRejected(): void
    {
        $ticket = $this->newTicket();
        $client = new HttpClient();

        $response = $client->postJson('/api/tickets/close/' . $ticket->id, [
            'close_reason' => 'should not apply',
        ]);

        $this->assertSame(401, $response['status'], 'an unauthenticated caller should not be able to close a ticket');

        $ticket->refresh();
        $this->assertSame('open', $ticket->status, 'ticket status changed despite no authentication');
    }

    public function testMemberRoleIsForbiddenFromClosing(): void
    {
        $ticket = $this->newTicket();
        $client = $this->loggedInClient(self::$memberEmail);

        $response = $client->postJson('/api/tickets/close/' . $ticket->id, [
            'close_reason' => 'should not apply',
        ]);

        $this->assertSame(403, $response['status'], 'member role should be forbidden from closing tickets via the API');

        $ticket->refresh();
        $this->assertSame('open', $ticket->status, 'ticket status changed despite an insufficient role');
    }

    public function testCloseRequiresACloseReason(): void
    {
        $ticket = $this->newTicket();
        $client = $this->loggedInClient(self::$adminEmail);

        $response = $client->postJson('/api/tickets/close/' . $ticket->id, [
            'notes' => 'no reason supplied',
        ]);

        $this->assertSame(422, $response['status'], 'close_reason should be required');

        $ticket->refresh();
        $this->assertSame('open', $ticket->status, 'ticket status changed despite a missing close_reason');
    }

    public function testCloseOnUnknownTicketReturns404(): void
    {
        $client = $this->loggedInClient(self::$adminEmail);

        $response = $client->postJson('/api/tickets/close/999999999', [
            'close_reason' => 'should not apply',
        ]);

        $this->assertSame(404, $response['status']);
    }

    public function testCloseRequiresPost(): void
    {
        $ticket = $this->newTicket();
        $client = $this->loggedInClient(self::$adminEmail);

        $response = $client->get('/api/tickets/close/' . $ticket->id);

        $this->assertSame(405, $response['status']);

        $ticket->refresh();
        $this->assertSame('open', $ticket->status);
    }
}
