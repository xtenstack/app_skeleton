<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Game-stream default assignee (Travis, 31 Oct 2026): the pure rule
 * behind Tickets::beforeValidationOnCreate(). Relayed tickets carry a
 * source_ref whose first segment names the game; hand-filed ones are
 * recognised by a game word in the title. No DB needed.
 */
final class TicketGameStreamRuleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Model autoloading only (same slice SoftDeleteTest takes); no DI
        // service is touched by the pure rule under test.
        if (!class_exists(\Tickets::class, false)) {
            require APP_PATH . '/config/loader.php';
        }
    }

    public function testRelayedSourceRefPrefixesMatchCaseInsensitively(): void
    {
        $this->assertTrue(\Tickets::isGameStreamTicket('xwar-legacy:2:204:13', 'Array offset error from fleet dispatch'));
        $this->assertTrue(\Tickets::isGameStreamTicket('XWAR:1:7', 'Anything'));
        $this->assertTrue(\Tickets::isGameStreamTicket('games:solitaire:12', 'Anything'));
        $this->assertTrue(\Tickets::isGameStreamTicket('game', 'Anything'));
    }

    public function testOtherSourceRefsDoNotMatchOnPrefixAlone(): void
    {
        $this->assertFalse(\Tickets::isGameStreamTicket('hosting-credentials:unknown account', 'Hosting: relay login details'));
        $this->assertFalse(\Tickets::isGameStreamTicket('directory:claim:41', 'Claim 41 unpaid'));
        $this->assertFalse(\Tickets::isGameStreamTicket(null, 'Ellipsis menu disappears'));
        $this->assertFalse(\Tickets::isGameStreamTicket('', ''));
    }

    public function testHandFiledTitlesWithGameWordsMatch(): void
    {
        $this->assertTrue(\Tickets::isGameStreamTicket(null, 'Add Upload to in game ticketing'));
        $this->assertTrue(\Tickets::isGameStreamTicket(null, 'XWar Legacy #13: fleet'));
        $this->assertTrue(\Tickets::isGameStreamTicket(null, 'Games: Mahjong tiles overlap'));
        $this->assertTrue(\Tickets::isGameStreamTicket(null, 'solitaire drag/drop broken'));
    }

    public function testGameWordsInsideOtherWordsDoNotMatch(): void
    {
        $this->assertFalse(\Tickets::isGameStreamTicket(null, 'Gamechanger pricing page typo'));
        $this->assertFalse(\Tickets::isGameStreamTicket(null, 'Engagement report'));
    }
}
