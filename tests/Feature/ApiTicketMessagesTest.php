<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * GX-SPEC-05 S1-S3: a ticket's conversation. Staff (admin/operator) reply
 * publicly or leave an internal note; the service key that raised the
 * ticket reads back only the public messages, only on its own tickets.
 * Real HTTP against the running stack, like ApiTicketsCloseControllerTest.
 */
final class ApiTicketMessagesTest extends TestCase
{
    private static string $password = 'PhpunitTest123!';

    private static string $operatorEmail;
    private static int $operatorId;
    private static int $serviceUserId;
    private static int $otherUserId;
    private static string $serviceToken;
    private static string $otherToken;
    private static int $serviceKeyId;
    private static int $otherKeyId;

    /** @var int[] */
    private static array $ticketIds = [];

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        $operatorRoleId = \Roles::idsByNames(['operator'])[0] ?? null;
        self::assertNotNull($operatorRoleId, "fixture setup requires an 'operator' role — run ./run seed");

        $tag                  = bin2hex(random_bytes(6));
        self::$operatorEmail  = "phpunit-msg-operator-$tag@example.invalid";
        self::$operatorId     = self::makeUser(self::$operatorEmail, (int) $operatorRoleId);
        self::$serviceUserId  = self::makeUser("phpunit-msg-service-$tag@example.invalid", 2);
        self::$otherUserId    = self::makeUser("phpunit-msg-other-$tag@example.invalid", 2);

        [self::$serviceKeyId, self::$serviceToken] = self::makeKey(self::$serviceUserId, 'service');
        [self::$otherKeyId, self::$otherToken]     = self::makeKey(self::$otherUserId, 'other');
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$ticketIds as $ticketId) {
            foreach (\TicketMessages::find(['conditions' => 'ticket_id = :t:', 'bind' => ['t' => $ticketId]]) as $message) {
                $message->delete();
            }
            \Tickets::findFirstById($ticketId)?->delete();
        }

        foreach ([self::$serviceKeyId, self::$otherKeyId] as $keyId) {
            \ApiKeys::findFirstById($keyId)?->delete();
        }

        foreach ([self::$operatorId, self::$serviceUserId, self::$otherUserId] as $userId) {
            \Users::findFirstById($userId)?->softDelete();
        }
    }

    private static function makeUser(string $email, int $roleId): int
    {
        $user                = new \Users();
        $user->email         = $email;
        $user->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
        $user->first_name    = 'PHPUnit';
        $user->last_name     = 'TicketMessages';
        $user->role_id       = $roleId;
        $user->is_active     = 1;
        self::assertTrue($user->save(), 'fixture user failed to save: ' . implode('; ', $user->getMessages()));

        return (int) $user->id;
    }

    /** @return array{0: int, 1: string} */
    private static function makeKey(int $userId, string $label): array
    {
        $token = "phpunit-msg-$label-" . bin2hex(random_bytes(16));

        $key               = new \ApiKeys();
        $key->user_id      = $userId;
        $key->name         = "phpunit-msg-$label-key";
        $key->token_hash   = hash('sha256', $token);
        $key->token_prefix = substr($token, 0, 10);
        self::assertTrue($key->save(), 'fixture api key failed to save: ' . implode('; ', $key->getMessages()));

        return [(int) $key->id, $token];
    }

    private function ticketRaisedBy(int $keyId, int $userId, string $status = 'open'): \Tickets
    {
        $ticket                      = new \Tickets();
        $ticket->title               = 'phpunit-msg-fixture-' . bin2hex(random_bytes(4));
        $ticket->description         = 'fixture ticket for ApiTicketMessagesTest';
        $ticket->severity            = 'normal';
        $ticket->ticket_type         = 'support';
        $ticket->status              = $status;
        $ticket->source_ref          = 'phpunit:' . bin2hex(random_bytes(4));
        $ticket->reporter_user_id    = $userId;
        $ticket->reporter_api_key_id = $keyId;
        self::assertTrue($ticket->save(), 'fixture ticket failed to save: ' . implode('; ', $ticket->getMessages()));
        self::$ticketIds[] = (int) $ticket->id;

        return $ticket;
    }

    private function operator(): HttpClient
    {
        $client    = new HttpClient();
        $loginPage = $client->get('/backend/session');
        $csrf      = HttpClient::extractCsrf($loginPage['body']);

        $client->post('/backend/session/login', [
            'email'      => self::$operatorEmail,
            'password'   => self::$password,
            $csrf['key'] => $csrf['token'],
        ]);

        return $client;
    }

    private function asService(string $path, ?string $token = null): array
    {
        return (new HttpClient())->getWithHeaders($path, ['X-Api-Key: ' . ($token ?? self::$serviceToken)]);
    }

    public function testPublicReplyIsReadableByTheServiceKeyThatRaisedTheTicketButInternalNoteIsNot(): void
    {
        $ticket   = $this->ticketRaisedBy(self::$serviceKeyId, self::$serviceUserId);
        $operator = $this->operator();

        $reply = $operator->postJson("/api/tickets/reply/{$ticket->id}", ['body' => 'We have fixed your colony.']);
        $note  = $operator->postJson("/api/tickets/note/{$ticket->id}", ['body' => 'Player had a duplicate account.']);

        $this->assertSame(201, $reply['status'], $reply['body']);
        $this->assertSame(201, $note['status'], $note['body']);

        $read = $this->asService("/api/tickets/messages/{$ticket->id}");
        $this->assertSame(200, $read['status'], $read['body']);
        $payload = json_decode($read['body'], true);

        $this->assertCount(1, $payload['messages'], 'a client must see only the public message');
        $this->assertSame('We have fixed your colony.', $payload['messages'][0]['body']);
        $this->assertSame('public', $payload['messages'][0]['visibility']);
        $this->assertSame('XTen Support', $payload['messages'][0]['author_label']);
        $this->assertArrayNotHasKey('author_user_id', $payload['messages'][0], 'a client must not see staff identities');
        $this->assertStringNotContainsString('duplicate account', $read['body'], 'internal note leaked to a client');
        $this->assertSame($ticket->source_ref, $payload['ticket']['source_ref']);
        $this->assertFalse($payload['has_more']);

        $staff = $operator->getWithHeaders("/api/tickets/messages/{$ticket->id}");
        $this->assertCount(2, json_decode($staff['body'], true)['messages'], 'staff must see both messages');
    }

    public function testAnotherKeyAndAnUnrelatedTicketAreIndistinguishableFromMissing(): void
    {
        $ticket = $this->ticketRaisedBy(self::$serviceKeyId, self::$serviceUserId);

        $this->assertSame(404, $this->asService("/api/tickets/messages/{$ticket->id}", self::$otherToken)['status']);
        $this->assertSame(404, $this->asService('/api/tickets/messages/999999999')['status']);
    }

    public function testClientCannotWriteReplyOrNote(): void
    {
        $ticket = $this->ticketRaisedBy(self::$serviceKeyId, self::$serviceUserId);
        $client = new HttpClient();

        foreach (['reply', 'note'] as $verb) {
            $response = $client->postJson("/api/tickets/$verb/{$ticket->id}", ['body' => 'nope'], ['X-Api-Key: ' . self::$serviceToken]);
            $this->assertSame(403, $response['status'], "$verb must be staff-only");
        }

        $this->assertSame(0, \TicketMessages::count(['conditions' => 'ticket_id = :t:', 'bind' => ['t' => (int) $ticket->id]]));
    }

    public function testCursorReturnsOnlyLaterMessagesAndRejectsAnUnknownCursor(): void
    {
        $ticket   = $this->ticketRaisedBy(self::$serviceKeyId, self::$serviceUserId);
        $operator = $this->operator();

        $first  = json_decode($operator->postJson("/api/tickets/reply/{$ticket->id}", ['body' => 'one'])['body'], true)['message']['id'];
        $note   = json_decode($operator->postJson("/api/tickets/note/{$ticket->id}", ['body' => 'hidden'])['body'], true)['message']['id'];
        $second = json_decode($operator->postJson("/api/tickets/reply/{$ticket->id}", ['body' => 'two'])['body'], true)['message']['id'];

        $later = json_decode($this->asService("/api/tickets/messages/{$ticket->id}?after_id=$first")['body'], true);
        $this->assertSame(['two'], array_column($later['messages'], 'body'));
        $this->assertSame($second, $later['last_id']);

        $this->assertSame(422, $this->asService("/api/tickets/messages/{$ticket->id}?after_id=999999999")['status'], 'unknown cursor must be an error, not a silent skip');
        $this->assertSame(422, $this->asService("/api/tickets/messages/{$ticket->id}?after_id=$note")['status'], 'a client must not be able to probe internal message ids');
        $this->assertSame(422, $this->asService("/api/tickets/messages/{$ticket->id}?after_id=abc")['status']);

        $paged = json_decode($this->asService("/api/tickets/messages/{$ticket->id}?limit=1")['body'], true);
        $this->assertCount(1, $paged['messages']);
        $this->assertTrue($paged['has_more']);
        $this->assertSame($first, $paged['last_id']);
    }

    public function testExternalRefMakesARetryReturnTheOriginalMessage(): void
    {
        $ticket   = $this->ticketRaisedBy(self::$serviceKeyId, self::$serviceUserId);
        $operator = $this->operator();
        $body     = ['body' => 'Same message', 'external_ref' => 'retry-1'];

        $created = $operator->postJson("/api/tickets/reply/{$ticket->id}", $body);
        $repeat  = $operator->postJson("/api/tickets/reply/{$ticket->id}", $body);

        $this->assertSame(201, $created['status']);
        $this->assertSame(200, $repeat['status']);
        $this->assertTrue(json_decode($repeat['body'], true)['duplicate']);
        $this->assertSame(
            json_decode($created['body'], true)['message']['id'],
            json_decode($repeat['body'], true)['message']['id']
        );
        $this->assertSame(1, \TicketMessages::count(['conditions' => 'ticket_id = :t:', 'bind' => ['t' => (int) $ticket->id]]));

        $conflict = $operator->postJson("/api/tickets/reply/{$ticket->id}", ['body' => 'Different text', 'external_ref' => 'retry-1']);
        $this->assertSame(409, $conflict['status']);
    }

    public function testReplyValidatesTheBodyAndLeavesTheTicketStatusAlone(): void
    {
        $ticket   = $this->ticketRaisedBy(self::$serviceKeyId, self::$serviceUserId, 'closed');
        $operator = $this->operator();

        $this->assertSame(422, $operator->postJson("/api/tickets/reply/{$ticket->id}", ['body' => '   '])['status']);
        $this->assertSame(422, $operator->postJson("/api/tickets/reply/{$ticket->id}", ['body' => str_repeat('x', 10001)])['status']);
        $this->assertSame(404, $operator->postJson('/api/tickets/reply/999999999', ['body' => 'x'])['status']);

        $this->assertSame(201, $operator->postJson("/api/tickets/reply/{$ticket->id}", ['body' => 'Fixed, closing note'])['status']);
        $ticket->refresh();
        $this->assertSame('closed', $ticket->status, 'a staff reply must not reopen a closed ticket');

        $read = json_decode($this->asService("/api/tickets/messages/{$ticket->id}")['body'], true);
        $this->assertSame('closed', $read['ticket']['status'], 'the client sees "closed, reply available"');
        $this->assertCount(1, $read['messages']);
    }

    public function testUnauthenticatedCallersAreRejected(): void
    {
        $ticket = $this->ticketRaisedBy(self::$serviceKeyId, self::$serviceUserId);

        $this->assertSame(401, (new HttpClient())->getWithHeaders("/api/tickets/messages/{$ticket->id}")['status']);
        $this->assertSame(401, (new HttpClient())->postJson("/api/tickets/reply/{$ticket->id}", ['body' => 'x'])['status']);
    }
}
