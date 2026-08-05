<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Phalcon\Di\FactoryDefault;

/**
 * The SoftDeletes trait (app/common/library/SoftDeletes.php) is the one
 * mechanism CLAUDE.md's "no bespoke is_deleted/deleted_at column" rule
 * depends on actually working — a regression here (find()/findFirst()
 * quietly stopping excluding trashed rows) is exactly the kind of thing
 * that's easy to reintroduce silently, per the project audit's own
 * reasoning for prioritizing this test. Uses the real `Tickets` model
 * (which `use SoftDeletes;`) against a real Postgres connection — no
 * mocking the ORM, same "test the real thing" rule the Feature tests
 * follow for HTTP. reporter_user_id is left null deliberately: it has
 * no NOT NULL constraint (db/migrations/postgresql/011_tickets.sql),
 * so this test needs no Users fixture at all.
 */
final class SoftDeleteTest extends TestCase
{
    private static \Phalcon\Di\DiInterface $di;

    public static function setUpBeforeClass(): void
    {
        $di = new FactoryDefault();
        require APP_PATH . '/config/services.php';
        require APP_PATH . '/config/loader.php';

        self::$di = $di;
        \Phalcon\Di\Di::setDefault($di);
    }

    private function makeTicket(string $title): \Tickets
    {
        $ticket        = new \Tickets();
        $ticket->title = $title;

        $this->assertTrue($ticket->save(), 'fixture ticket failed to save: ' . implode('; ', $ticket->getMessages()));

        return $ticket;
    }

    protected function tearDown(): void
    {
        // Hard delete — SoftDeletes doesn't override delete() itself,
        // only find()/findFirst()/softDelete()/restore() — so this is a
        // real cleanup of the fixture row, not another soft-delete.
        foreach (\Tickets::findWithTrashed(['conditions' => "title LIKE 'SoftDeleteTest fixture%'"]) as $leftover) {
            $leftover->delete();
        }
    }

    public function testSoftDeletedRecordExcludedFromDefaultFind(): void
    {
        $ticket = $this->makeTicket('SoftDeleteTest fixture — find()');

        $this->assertTrue($ticket->softDelete(), 'softDelete() failed: ' . implode('; ', $ticket->getMessages()));
        $this->assertNotNull($ticket->deleted_at, 'softDelete() did not set deleted_at');

        $found = \Tickets::findFirst(['conditions' => 'id = :id:', 'bind' => ['id' => $ticket->id]]);

        $this->assertNull($found, 'findFirst() returned a soft-deleted record — the trait is not excluding it');

        $foundInList = \Tickets::find(['conditions' => 'id = :id:', 'bind' => ['id' => $ticket->id]]);

        $this->assertCount(0, $foundInList, 'find() returned a soft-deleted record — the trait is not excluding it');
    }

    public function testSoftDeletedRecordStillReachableWithTrashed(): void
    {
        $ticket = $this->makeTicket('SoftDeleteTest fixture — withTrashed');
        $ticket->softDelete();

        $found = \Tickets::findFirstWithTrashed(['conditions' => 'id = :id:', 'bind' => ['id' => $ticket->id]]);

        $this->assertNotNull($found, 'findFirstWithTrashed() should still reach a soft-deleted record');
        $this->assertNotNull($found->deleted_at);
    }

    public function testUntouchedRecordStillReturnedByDefaultFind(): void
    {
        // The trait rewriting every conditions clause is the kind of
        // change that can accidentally exclude everything, not just
        // trashed rows — this is the "did we break the common case"
        // guard, not a soft-delete-specific assertion.
        $ticket = $this->makeTicket('SoftDeleteTest fixture — untouched');

        $found = \Tickets::findFirst(['conditions' => 'id = :id:', 'bind' => ['id' => $ticket->id]]);

        $this->assertNotNull($found, 'findFirst() failed to return a record that was never soft-deleted');
    }
}
