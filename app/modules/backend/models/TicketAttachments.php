<?php
declare(strict_types=1);

/**
 * A file attached to a ticket. See migration 013_ticket_attachments.sql —
 * stored outside public/ and served through
 * TicketsController::downloadAttachmentAction() rather than statically, since
 * attachment contents (e.g. a customer's phpinfo() dump) can be sensitive.
 *
 * @method static TicketAttachments|null findFirstById(int $id)
 */
class TicketAttachments extends \Phalcon\Mvc\Model
{
    public $id;
    public $ticket_id;
    public $filename;
    public $original_filename;
    public $mime_type;
    public $size_bytes;
    public $uploaded_by_user_id;
    public $created_at;

    /**
     * Caller-supplied retry key, unique per ticket (migration 030). Set only
     * by API uploads; see Api\Controllers\TicketsController::uploadAttachmentAction().
     */
    public $external_ref;

    public function initialize(): void
    {
        $this->setSource('ticket_attachments');
        $this->belongsTo('ticket_id', 'Tickets', 'id', ['alias' => 'Ticket']);
        $this->belongsTo('uploaded_by_user_id', 'Users', 'id', ['alias' => 'UploadedBy']);
    }
}
