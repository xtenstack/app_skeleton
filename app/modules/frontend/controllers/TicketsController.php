<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Frontend\Controllers;

/**
 * Customer-facing support requests — a member raises, views, and edits
 * only their own tickets (reporter_user_id = the logged-in user, never
 * client-supplied). Triage fields (status, assignment, consolidation,
 * QA, internal notes) are staff-only and live exclusively in
 * backend\TicketsController — this controller never reads or writes
 * them. Every lookup is scoped by reporter_user_id in the query itself,
 * not just filtered after the fact, so a customer can't view, edit, or
 * download an attachment from another customer's ticket by guessing an
 * id. Attachment validation (allowed types, max size, storage path
 * convention) intentionally mirrors backend\TicketsController's own —
 * duplicated rather than shared, since cross-module reach-ins are
 * forbidden (CLAUDE.md) and a private class const can't cross that
 * boundary anyway.
 */
class TicketsController extends ControllerBase
{
    private const TICKET_TYPES = ['bug' => 'Bug', 'issue' => 'Issue', 'feature' => 'Feature', 'support' => 'Support'];
    private const SEVERITIES   = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'critical' => 'Critical'];

    private const ALLOWED_ATTACHMENT_TYPES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/gif'       => 'gif',
        'text/plain'      => 'txt',
        'text/html'       => 'html',
        'application/pdf' => 'pdf',
    ];

    private const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

    public function indexAction()
    {
        $this->view->tickets = \Tickets::find([
            'conditions' => 'reporter_user_id = :uid:',
            'bind'       => ['uid' => $this->currentUserId()],
            'order'      => 'id DESC',
        ]);
    }

    public function newAction()
    {
        $this->view->ticket = new \Tickets();
    }

    /**
     * Attachment is optional and handled inline here, not as a separate
     * upload-after-create step — a customer raising a support request
     * with a screenshot in hand shouldn't have to create the ticket
     * first and then remember to come back and attach it (Session 13).
     * A failed attachment (bad type/too large/write failure) doesn't
     * roll back the ticket itself — it's already a real, saved request
     * at that point — the view page's own upload form (see
     * uploadAttachmentAction) is the fallback if this step fails.
     */
    public function createAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        $title = trim((string) $this->request->getPost('title'));

        if ($title === '') {
            $this->flash->error('Title is required');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'new']);
        }

        $ticketType = (string) $this->request->getPost('ticket_type');

        $ticket                   = new \Tickets();
        $ticket->title            = $title;
        $ticket->description      = (string) $this->request->getPost('description') ?: null;
        $ticket->severity         = (string) $this->request->getPost('severity') ?: 'normal';
        $ticket->ticket_type      = isset(self::TICKET_TYPES[$ticketType]) ? $ticketType : 'support';
        $ticket->project          = (string) $this->request->getPost('project') ?: null;
        $ticket->reporter_user_id = $this->currentUserId();

        if (!$ticket->save()) {
            foreach ($ticket->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            $this->view->ticket = $ticket;

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'new']);
        }

        if ($this->request->hasFiles()) {
            $this->attachUploadedFile($ticket);
        }

        $this->flash->success('Support request created');

        return $this->response->redirect($this->url->get('frontend/tickets/view/' . $ticket->id));
    }

    public function viewAction($id)
    {
        $ticket = $this->findOwnTicket($id);

        if (!$ticket) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        $this->view->ticket = $ticket;
    }

    public function editAction($id)
    {
        $ticket = $this->findOwnTicket($id);

        if (!$ticket) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        $this->view->ticket = $ticket;
    }

    public function updateAction($id)
    {
        $ticket = $this->findOwnTicket($id);

        if (!$ticket) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->response->redirect($this->url->get('frontend/tickets/view/' . $id));
        }

        $title = trim((string) $this->request->getPost('title'));

        if ($title === '') {
            $this->flash->error('Title is required');
            $this->view->ticket = $ticket;

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'edit', 'params' => [$id]]);
        }

        $ticketType = (string) $this->request->getPost('ticket_type');

        // Only the fields a customer actually owns — status, assignment,
        // consolidation, QA, and internal notes stay staff-only (see class
        // docblock) and are never read from the request here. project is
        // customer-owned (unlike backend's staff-only edit path) — a
        // ticket needs a valid Project ID from the customer themselves to
        // qualify for SLA timing (Support-Operations-Pack RB-18).
        $ticket->title       = $title;
        $ticket->description = (string) $this->request->getPost('description') ?: null;
        $ticket->severity    = (string) $this->request->getPost('severity') ?: $ticket->severity;
        $ticket->ticket_type = isset(self::TICKET_TYPES[$ticketType]) ? $ticketType : $ticket->ticket_type;
        $ticket->project     = (string) $this->request->getPost('project') ?: null;

        if (!$ticket->save()) {
            foreach ($ticket->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            $this->view->ticket = $ticket;

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'edit', 'params' => [$id]]);
        }

        $this->flash->success('Support request updated');

        return $this->response->redirect($this->url->get('frontend/tickets/view/' . $ticket->id));
    }

    /**
     * Fallback path for a customer adding an attachment after the fact
     * (or retrying one that failed at creation) — same ownership scoping
     * as every other action here.
     */
    public function uploadAttachmentAction($id)
    {
        $ticket = $this->findOwnTicket($id);

        if (!$ticket) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        if (!$this->request->hasFiles()) {
            $this->flash->error('No file was uploaded');
        } else {
            $this->attachUploadedFile($ticket);
        }

        return $this->response->redirect($this->url->get('frontend/tickets/view/' . $id));
    }

    public function downloadAttachmentAction($id, $attachmentId)
    {
        $ticket = $this->findOwnTicket($id);

        if (!$ticket) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        $attachment = \TicketAttachments::findFirst([
            'conditions' => 'id = :attachment_id: AND ticket_id = :ticket_id:',
            'bind'       => ['attachment_id' => $attachmentId, 'ticket_id' => $ticket->id],
        ]);

        if (!$attachment) {
            $this->flash->error('Attachment was not found');

            return $this->response->redirect($this->url->get('frontend/tickets/view/' . $id));
        }

        $path = BASE_PATH . '/storage/ticket-attachments/' . $attachment->ticket_id . '/' . $attachment->filename;

        if (!is_file($path)) {
            $this->flash->error('Attachment file is missing on disk');

            return $this->response->redirect($this->url->get('frontend/tickets/view/' . $id));
        }

        // setFileToSend() streams via Response::send()'s own readfile()
        // call, not getContent() — same reason backend's own
        // downloadAttachmentAction sends+exits directly rather than
        // returning $this->response.
        $this->response->setContentType($attachment->mime_type);
        $this->response->setFileToSend($path, str_replace('"', '', $attachment->original_filename), true);
        $this->response->send();
        exit;
    }

    private function attachUploadedFile(\Tickets $ticket): void
    {
        $file = $this->request->getUploadedFiles()[0];
        $mime = $file->getRealType();

        if (!isset(self::ALLOWED_ATTACHMENT_TYPES[$mime])) {
            $this->flash->error('Unsupported file type — allowed: images, PDF, plain text, HTML');

            return;
        }

        if ($file->getSize() > self::MAX_ATTACHMENT_BYTES) {
            $this->flash->error('File must be under 10MB');

            return;
        }

        $dir = BASE_PATH . '/storage/ticket-attachments/' . $ticket->id;

        // !is_dir() re-checked after a failed mkdir() to tolerate two
        // concurrent uploads to the same new ticket racing to create it —
        // same reasoning as backend's own uploadAttachmentAction.
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->flash->error('Could not save the attachment — storage directory is not writable');

            return;
        }

        $filename = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_ATTACHMENT_TYPES[$mime];

        if (!$file->moveTo($dir . '/' . $filename)) {
            $this->flash->error('Could not save the attachment — file upload failed');

            return;
        }

        $attachment                      = new \TicketAttachments();
        $attachment->ticket_id           = $ticket->id;
        $attachment->filename            = $filename;
        $attachment->original_filename   = $file->getName();
        $attachment->mime_type           = $mime;
        $attachment->size_bytes          = $file->getSize();
        $attachment->uploaded_by_user_id = $this->currentUserId();

        if (!$attachment->save()) {
            unlink($dir . '/' . $filename);

            foreach ($attachment->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return;
        }

        $this->flash->success('File attached');
    }

    private function findOwnTicket($id): ?\Tickets
    {
        $ticket = \Tickets::findFirst([
            'conditions' => 'id = :id: AND reporter_user_id = :uid:',
            'bind'       => ['id' => (int) $id, 'uid' => $this->currentUserId()],
        ]);

        if (!$ticket) {
            // Same message whether the ticket doesn't exist or belongs to
            // someone else — confirming "that id exists, just not yours"
            // is exactly the kind of detail an IDOR probe is fishing for.
            $this->flash->error('Ticket was not found');
        }

        return $ticket;
    }

    private function currentUserId(): int
    {
        return (int) ($this->session->get('auth')['id'] ?? 0);
    }
}
