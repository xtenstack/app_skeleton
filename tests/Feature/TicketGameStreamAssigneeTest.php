<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Game-stream default assignee (Travis, 31 Oct 2026):
 * Tickets::beforeValidationOnCreate() fills an empty assigned_to_user_id
 * on a game-stream ticket with the user named by the
 * tickets_game_stream_assignee setting. Exercised through the real model
 * against the running stack's DB: a throwaway assignee user, the setting
 * pointed at it for the duration of the class, then restored.
 */
final class TicketGameStreamAssigneeTest extends TestCase
{
    private static string $assigneeEmail;
    private static int $assigneeId;
    private static ?string $previousSetting = null;

    /** @var int[] */
    private static array $ticketIds = [];

    public static function setUpBeforeClass(): void
    {
        $di = new \Phalcon\Di\FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';
        \Phalcon\Di\Di::setDefault($di);

        self::$assigneeEmail = 'phpunit-gamestream-assignee-' . bin2hex(random_bytes(6)) . '@example.invalid';

        $user                = new \Users();
        $user->email         = self::$assigneeEmail;
        $user->password_hash = password_hash('PhpunitTest123!', PASSWORD_DEFAULT);
        $user->first_name    = 'PHPUnit';
        $user->last_name     = 'GameStreamAssignee';
        $user->role_id       = 2; // member: the rule assigns whoever the setting names, role is not its concern
        $user->is_active     = 1;
        self::assertTrue($user->save(), 'fixture assignee failed to save: ' . implode('; ', $user->getMessages()));
        self::$assigneeId = (int) $user->id;

        $settings              = $di->getShared('settings');
        self::$previousSetting = $settings->get(\Tickets::GAME_STREAM_ASSIGNEE_SETTING);
        $settings->set(\Tickets::GAME_STREAM_ASSIGNEE_SETTING, self::$assigneeEmail);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$ticketIds as $ticketId) {
            \Tickets::findFirstById($ticketId)?->delete();
        }

        $settings = \Phalcon\Di\Di::getDefault()->getShared('settings');
        $settings->set(\Tickets::GAME_STREAM_ASSIGNEE_SETTING, (string) (self::$previousSetting ?? \Tickets::GAME_STREAM_ASSIGNEE_DEFAULT));

        $user = \Users::findFirstWithTrashed(['conditions' => 'email = :email:', 'bind' => ['email' => self::$assigneeEmail]]);
        $user?->softDelete();
    }

    private function createTicket(string $title, ?string $sourceRef, ?int $assignee = null): \Tickets
    {
        $ticket                      = new \Tickets();
        $ticket->title               = $title;
        $ticket->severity            = 'normal';
        $ticket->ticket_type         = 'bug';
        $ticket->source_ref          = $sourceRef;
        $ticket->reporter_user_id    = self::$assigneeId;
        $ticket->assigned_to_user_id = $assignee;
        $this->assertTrue($ticket->save(), 'ticket failed to save: ' . implode('; ', $ticket->getMessages()));
        self::$ticketIds[] = (int) $ticket->id;

        return \Tickets::findFirstById((int) $ticket->id);
    }

    public function testRelayedGameTicketIsAssignedToTheConfiguredUser(): void
    {
        $ticket = $this->createTicket('PHPUnit game-stream relayed', 'xwar-legacy:9:1:1');

        $this->assertSame(self::$assigneeId, (int) $ticket->assigned_to_user_id);
    }

    public function testHandFiledGameTitleIsAssignedToo(): void
    {
        $ticket = $this->createTicket('PHPUnit: Games Solitaire card stuck', null);

        $this->assertSame(self::$assigneeId, (int) $ticket->assigned_to_user_id);
    }

    public function testNonGameTicketStaysUnassigned(): void
    {
        $ticket = $this->createTicket('PHPUnit hosting invoice question', 'hosting-credentials:phpunit');

        $this->assertNull($ticket->assigned_to_user_id);
    }

    public function testAnExplicitAssigneeIsNeverOverridden(): void
    {
        $ticket = $this->createTicket('PHPUnit XWar explicit assignee', 'xwar:1', self::$assigneeId);

        $this->assertSame(self::$assigneeId, (int) $ticket->assigned_to_user_id);
    }

    public function testEmptySettingTurnsTheRuleOff(): void
    {
        $settings = \Phalcon\Di\Di::getDefault()->getShared('settings');
        $settings->set(\Tickets::GAME_STREAM_ASSIGNEE_SETTING, '');

        try {
            $ticket = $this->createTicket('PHPUnit game ticket with rule off', 'games:1');
            $this->assertNull($ticket->assigned_to_user_id);
        } finally {
            $settings->set(\Tickets::GAME_STREAM_ASSIGNEE_SETTING, self::$assigneeEmail);
        }
    }
}
