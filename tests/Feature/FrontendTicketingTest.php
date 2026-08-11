<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Phalcon\Di\FactoryDefault;

/**
 * The frontend ticketing surface (project audit's other named priority,
 * alongside requirements-module) had zero automated coverage before this.
 * The highest-value thing to actually verify here isn't the happy path
 * (create a ticket, see it) so much as the access-scoping guarantee
 * frontend\TicketsController::findOwnTicket()'s own docblock claims: "a
 * customer can't view... another customer's ticket by guessing an id."
 * That's a real security boundary, distinct from RBAC (both accounts
 * here are the same role — member) — an IDOR check, not a role check.
 * Real HTTP against the real running stack — see HttpClient's own
 * docblock for why this isn't dispatched in-process.
 */
final class FrontendTicketingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $di = new FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);
    }

    /** @return array{client: HttpClient, email: string} */
    private function signUpAndLogIn(string $label): array
    {
        $client = new HttpClient();
        $email  = 'phpunit-frontend-' . $label . '-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $signupPage = $client->get('/backend/signup');
        $csrf       = HttpClient::extractCsrf($signupPage['body']);

        $client->post('/backend/signup/create', [
            'email'      => $email,
            'password'   => 'PhpunitTest123!',
            'first_name' => 'PHPUnit',
            'last_name'  => $label,
            $csrf['key'] => $csrf['token'],
        ]);

        $loginPage = $client->get('/backend/session');
        $csrf      = HttpClient::extractCsrf($loginPage['body']);

        $response = $client->post('/backend/session/login', [
            'email'      => $email,
            'password'   => 'PhpunitTest123!',
            $csrf['key'] => $csrf['token'],
        ]);

        $this->assertStringNotContainsString('Invalid email or password', $response['body']);

        return ['client' => $client, 'email' => $email];
    }

    private function cleanupUser(string $email): void
    {
        $user = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => $email]]);
        $user?->softDelete();
    }

    public function testMemberCanCreateAndSeeOwnTicketInTheirOwnList(): void
    {
        $fixture = $this->signUpAndLogIn('owncheck');
        $client  = $fixture['client'];

        $newPage = $client->get('/frontend/tickets/new');
        $csrf    = HttpClient::extractCsrf($newPage['body']);

        $title = 'PHPUnit FrontendTicketingTest fixture — ' . bin2hex(random_bytes(4));

        $client->post('/frontend/tickets/create', [
            'title'        => $title,
            'description'  => 'Created by FrontendTicketingTest',
            'ticket_type'  => 'support',
            $csrf['key']   => $csrf['token'],
        ]);

        $ticket = \Tickets::findFirst(['conditions' => 'title = :title:', 'bind' => ['title' => $title]]);
        $this->assertNotNull($ticket, 'ticket was not created via the frontend create flow');

        $list = $client->get('/frontend/tickets');
        $this->assertStringContainsString($title, $list['body'], 'the reporter\'s own ticket did not appear in their own list');

        $ticket->delete();
        $this->cleanupUser($fixture['email']);
    }

    public function testMemberCannotViewAnotherMembersTicketByGuessingId(): void
    {
        $owner  = $this->signUpAndLogIn('idorowner');
        $intruder = $this->signUpAndLogIn('idorintruder');

        $newPage = $owner['client']->get('/frontend/tickets/new');
        $csrf    = HttpClient::extractCsrf($newPage['body']);

        $secretTitle = 'PHPUnit FrontendTicketingTest fixture — secret ' . bin2hex(random_bytes(4));

        $owner['client']->post('/frontend/tickets/create', [
            'title'        => $secretTitle,
            'ticket_type'  => 'support',
            $csrf['key']   => $csrf['token'],
        ]);

        $ticket = \Tickets::findFirst(['conditions' => 'title = :title:', 'bind' => ['title' => $secretTitle]]);
        $this->assertNotNull($ticket, 'owner\'s ticket was not created — can\'t test cross-account access without it');

        // The actual IDOR probe: a different logged-in member requesting
        // the owner's ticket id directly.
        $response = $intruder['client']->get('/frontend/tickets/view/' . $ticket->id);

        $this->assertSame(200, $response['status'], 'expected a redirect-with-flash, not a hard error');
        $this->assertStringNotContainsString($secretTitle, $response['body'], 'the other member\'s ticket title leaked to a non-owner');
        $this->assertStringContainsString('Ticket was not found', $response['body'], 'expected findOwnTicket()\'s own not-found flash message');

        $ticket->delete();
        $this->cleanupUser($owner['email']);
        $this->cleanupUser($intruder['email']);
    }
}
