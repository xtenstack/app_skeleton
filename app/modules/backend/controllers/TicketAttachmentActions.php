<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

use App_skeleton\Audit;

/**
 * Ticket attachment upload/download/delete, split out of
 * TicketsController (project audit, 2026-08-04, Tier 2 — that file was
 * 677 lines, the only one in app/ over the 500-line flag threshold, and
 * attachments were the module with the most recent churn: storage
 * ownership and upload-limit fixes both landed here in Session 12).
 * A trait, not a separate controller — these actions are still
 * reached as tickets/upload-attachment/download-attachment/
 * delete-attachment routes on TicketsController itself (see
 * app/config/routes.php's generic module-route pattern), just moved out
 * of that file's body. Requires the consuming class to be a
 * TicketsController-shaped ControllerBase (uses $this->request/flash/
 * dispatcher/response/session, same as any other action here).
 */
trait TicketAttachmentActions
{
    /**
     * Deliberately narrower than the avatar upload's image-only allowlist —
     * a ticket attachment is routinely a phpinfo() dump or a log file, not
     * just a screenshot.
     */
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

    /**
     * Uploads allowed per user within RATE_LIMIT_WINDOW before further
     * uploads are blocked outright — same principle as Auth::isRateLimited(),
     * adapted for an abusable-but-not-failure-prone action (project audit,
     * Tier 2: this endpoint had no rate limiting at all, only login did).
     * Counts TicketAttachments rows directly rather than audit_log, since
     * uploaded_by_user_id/created_at already give an exact count with no
     * JSON-matching needed.
     */
    private const UPLOAD_RATE_LIMIT_MAX    = 20;
    private const UPLOAD_RATE_LIMIT_WINDOW = '-15 minutes';

    public function uploadAttachmentAction($id)
    {
        $ticket = \Tickets::findFirstById($id);

        if (!$ticket) {
            $this->flash->error('Ticket was not found');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        if (!$this->request->isPost() || !$this->request->hasFiles()) {
            $this->flash->error('No file was uploaded');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $userId = $this->session->get('auth')['id'];

        if ($this->isUploadRateLimited($userId)) {
            $this->flash->error('Too many uploads — please wait a few minutes and try again');
            Audit::recordEvent('attachment_upload_blocked', $userId, ['ticket_id' => $ticket->id]);

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $file = $this->request->getUploadedFiles()[0];
        $mime = $file->getRealType();

        if (!isset(self::ALLOWED_ATTACHMENT_TYPES[$mime])) {
            $this->flash->error('Unsupported file type — allowed: images, PDF, plain text, HTML');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        if ($file->getSize() > self::MAX_ATTACHMENT_BYTES) {
            $this->flash->error('File must be under 10MB');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $dir = BASE_PATH . '/storage/ticket-attachments/' . $ticket->id;

        // !is_dir() re-checked after a failed mkdir() to tolerate two
        // concurrent uploads to the same new ticket racing to create it —
        // not just the permission failure this was actually caught by
        // (storage/ticket-attachments arriving root-owned on a fresh
        // deploy, see entrypoint.sh).
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->flash->error('Could not save the attachment — storage directory is not writable');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_ATTACHMENT_TYPES[$mime];

        if (!$file->moveTo($dir . '/' . $filename)) {
            $this->flash->error('Could not save the attachment — file upload failed');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $attachment                      = new \TicketAttachments();
        $attachment->ticket_id           = $ticket->id;
        $attachment->filename            = $filename;
        $attachment->original_filename   = $file->getName();
        $attachment->mime_type           = $mime;
        $attachment->size_bytes          = $file->getSize();
        $attachment->uploaded_by_user_id = $this->session->get('auth')['id'];

        if (!$attachment->save()) {
            unlink($dir . '/' . $filename);

            foreach ($attachment->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('File attached');
        }

        return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
    }

    public function downloadAttachmentAction($id, $attachmentId)
    {
        $attachment = \TicketAttachments::findFirst([
            'conditions' => 'id = :attachment_id: AND ticket_id = :ticket_id:',
            'bind'       => ['attachment_id' => $attachmentId, 'ticket_id' => $id],
        ]);

        if (!$attachment) {
            $this->flash->error('Attachment was not found');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $path = BASE_PATH . '/storage/ticket-attachments/' . $attachment->ticket_id . '/' . $attachment->filename;

        if (!is_file($path)) {
            $this->flash->error('Attachment file is missing on disk');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        // setFileToSend() streams via Response::send()'s own readfile() call,
        // not the content string getContent() returns — bootstrap_web.php
        // only ever calls getContent() on the response an action returns
        // (echo $application->handle(...)->getContent()), so a file
        // response has to send()+exit itself here rather than following the
        // usual "return $this->response" pattern, the same way
        // ControllerBase's 401/403 early exits already do.
        $this->response->setContentType($attachment->mime_type);
        $this->response->setFileToSend($path, str_replace('"', '', $attachment->original_filename), true);
        $this->response->send();
        exit;
    }

    public function deleteAttachmentAction($id, $attachmentId)
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $attachment = \TicketAttachments::findFirst([
            'conditions' => 'id = :attachment_id: AND ticket_id = :ticket_id:',
            'bind'       => ['attachment_id' => $attachmentId, 'ticket_id' => $id],
        ]);

        if (!$attachment) {
            $this->flash->error('Attachment was not found');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $path = BASE_PATH . '/storage/ticket-attachments/' . $attachment->ticket_id . '/' . $attachment->filename;

        if (!$attachment->delete()) {
            foreach ($attachment->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        if (is_file($path)) {
            unlink($path);
        }

        $this->flash->success('Attachment removed');

        return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
    }

    private function isUploadRateLimited(int $userId): bool
    {
        $since = date('Y-m-d H:i:s', strtotime(self::UPLOAD_RATE_LIMIT_WINDOW));

        $count = \TicketAttachments::count([
            'conditions' => 'uploaded_by_user_id = :user_id: AND created_at >= :since:',
            'bind'       => ['user_id' => $userId, 'since' => $since],
        ]);

        return $count >= self::UPLOAD_RATE_LIMIT_MAX;
    }
}
