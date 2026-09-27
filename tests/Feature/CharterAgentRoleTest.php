<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * charter_agent role (MAA-20260927-001): a Charter Agent reads the KB but
 * can't author it, lodges tickets and sees only the ones they reported,
 * and never sees triage controls, the staff list or other tickets. Real
 * HTTP against the running stack, same as RbacTest.
 */
final class CharterAgentRoleTest extends TestCase
{
    private static string $password = 'PhpunitTest123!';
    private static string $agentEmail;
    private static string $otherEmail;
    private static int $ownTicketId;
    private static int $otherTicketId;
    private static string $ownTitle;
    private static string $otherTitle;

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        $roleId = \Roles::idsByNames(['charter_agent'])[0] ?? null;
        self::assertNotNull($roleId, "fixture setup requires the 'charter_agent' role — run ./run migrate run (023_charter_agent_role.sql)");

        $suffix           = bin2hex(random_bytes(6));
        self::$agentEmail = 'phpunit-ca-' . $suffix . '@example.invalid';
        self::$otherEmail = 'phpunit-ca-other-' . $suffix . '@example.invalid';

        $ids = [];
        foreach ([[self::$agentEmail, $roleId], [self::$otherEmail, 1]] as [$email, $rid]) {
            $user                = new \Users();
            $user->email         = $email;
            $user->password_hash = password_hash(self::$password, PASSWORD_DEFAULT);
            $user->first_name    = 'PHPUnit';
            $user->last_name     = 'CharterAgent';
            $user->role_id       = $rid;
            $user->is_active     = 1;
            self::assertTrue($user->save(), 'fixture user failed to save: ' . implode('; ', $user->getMessages()));
            $ids[] = (int) $user->id;
        }

        self::$ownTitle   = 'phpunit-ca-own-' . $suffix;
        self::$otherTitle = 'phpunit-ca-other-' . $suffix;
        foreach ([[self::$ownTitle, $ids[0]], [self::$otherTitle, $ids[1]]] as $n => [$title, $reporter]) {
            $ticket                   = new \Tickets();
            $ticket->title            = $title;
            $ticket->severity         = 'normal';
            $ticket->ticket_type      = 'support';
            $ticket->reporter_user_id = $reporter;
            self::assertTrue($ticket->save(), 'fixture ticket failed to save: ' . implode('; ', $ticket->getMessages()));
            if ($n === 0) {
                self::$ownTicketId = (int) $ticket->id;
            } else {
                self::$otherTicketId = (int) $ticket->id;
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$ownTicketId, self::$otherTicketId] as $id) {
            \Tickets::findFirstById($id)?->delete();
        }
        foreach ([self::$agentEmail, self::$otherEmail] as $email) {
            \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => $email]])?->softDelete();
        }
    }

    private function agentClient(): HttpClient
    {
        $client    = new HttpClient();
        $loginPage = $client->get('/backend/session');
        $csrf      = HttpClient::extractCsrf($loginPage['body']);
        $response  = $client->post('/backend/session/login', [
            'email'      => self::$agentEmail,
            'password'   => self::$password,
            $csrf['key'] => $csrf['token'],
        ]);
        $this->assertStringNotContainsString('Invalid email or password', $response['body'], 'Charter Agent fixture failed to log in');

        return $client;
    }

    public function testKnowledgeBaseIsReadOnly(): void
    {
        $client = $this->agentClient();

        $this->assertSame(200, $client->get('/backend/kb-articles')['status']);
        $this->assertSame(403, $client->get('/backend/kb-articles/new')['status']);
    }

    public function testTicketsAreScopedToTheirOwn(): void
    {
        $client = $this->agentClient();

        $index = $client->get('/backend/tickets');
        $this->assertSame(200, $index['status']);
        $this->assertStringContainsString(self::$ownTitle, $index['body']);
        $this->assertStringNotContainsString(self::$otherTitle, $index['body'], "a Charter Agent saw someone else's ticket in the list");

        $own = $client->get('/backend/tickets/view/' . self::$ownTicketId);
        $this->assertSame(200, $own['status']);
        $this->assertStringNotContainsString('backend/tickets/assign/', $own['body'], 'triage controls shown to a Charter Agent');
        $this->assertStringNotContainsString(self::$otherTitle, $own['body'], 'other tickets listed on a Charter Agent ticket page');

        $this->assertSame(404, $client->get('/backend/tickets/view/' . self::$otherTicketId)['status']);
        $this->assertSame(200, $client->get('/backend/tickets/new')['status']);
        $this->assertSame(403, $client->get('/backend/tickets/edit/' . self::$ownTicketId)['status']);
    }

    public function testStaffOnlyScreensStayClosed(): void
    {
        $client = $this->agentClient();

        $this->assertSame(403, $client->get('/backend/users')['status']);
    }
}
