<?php
declare(strict_types=1);

/**
 * One message in a ticket's conversation (migration 028, GX-SPEC-05).
 * 'public' messages are what a game/service client may read back through
 * the API; 'internal' ones are staff-only notes and are never serialized
 * for a client. Immutable once saved.
 *
 * @method static TicketMessages|null findFirstById(int $id)
 */
class TicketMessages extends \Phalcon\Mvc\Model
{
    public const VISIBILITY_PUBLIC   = 'public';
    public const VISIBILITY_INTERNAL = 'internal';

    public const MAX_BODY_LENGTH = 10000;

    public $id;
    public $ticket_id;
    public $author_user_id;
    public $author_api_key_id;
    public $visibility;
    public $body;
    public $external_ref;
    public $created_at;

    public function initialize(): void
    {
        $this->setSource('ticket_messages');
        $this->belongsTo('ticket_id', 'Tickets', 'id', ['alias' => 'Ticket']);
        $this->belongsTo('author_user_id', 'Users', 'id', ['alias' => 'Author']);
        $this->belongsTo('author_api_key_id', 'ApiKeys', 'id', ['alias' => 'AuthorApiKey']);
    }

    public function beforeValidationOnCreate(): void
    {
        $this->created_at = date('Y-m-d H:i:s');
    }

    public function beforeSave(): void
    {
        if (!in_array($this->visibility, [self::VISIBILITY_PUBLIC, self::VISIBILITY_INTERNAL], true)) {
            throw new \InvalidArgumentException('visibility must be public or internal');
        }
    }

    /**
     * Messages for a ticket in ascending id order, after a cursor.
     *
     * @return TicketMessages[]
     */
    public static function forTicket(int $ticketId, bool $includeInternal, int $afterId = 0, int $limit = 100): array
    {
        $conditions = 'ticket_id = :ticket_id: AND id > :after_id:';
        $bind       = ['ticket_id' => $ticketId, 'after_id' => $afterId];

        if (!$includeInternal) {
            $conditions        .= ' AND visibility = :visibility:';
            $bind['visibility'] = self::VISIBILITY_PUBLIC;
        }

        return iterator_to_array(self::find([
            'conditions' => $conditions,
            'bind'       => $bind,
            'order'      => 'id ASC',
            'limit'      => $limit,
        ]), false);
    }
}
