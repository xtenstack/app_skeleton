<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api\Controllers;

use App_skeleton\Audit;

/**
 * Scope: create + list + view + retest-result reporting, plus (as of
 * REQ-168) a manual closeAction() for admin/operator callers only, and
 * (as of the Tim/SSA integration, 2026-09-05) a selfAssignAction() for
 * any authenticated caller. Reassignment, consolidation, and QA review
 * stay human-UI-only via the backend
 * (App_skeleton\Modules\Backend\Controllers\TicketsController) — but
 * closeAction() and selfAssignAction() are each a deliberate, scoped
 * exception to this controller's original "ticket triage authority
 * stays with humans" docblock. closeAction(): Session 18 extended
 * closing authority to the API for admin/operator callers, provided
 * they supply a real close_reason (not a generic 'manual' stamp) and,
 * optionally, root-cause/fix notes. Agents are excluded — their
 * resolution path is retestResultAction()'s auto_retest close, not
 * this. selfAssignAction(): narrower than general assignment — a
 * caller may only claim a ticket for themselves, and only while it is
 * unassigned, so this does not reopen the "agents are never a valid
 * assignee" question TicketTriageActions::assignAction() still
 * enforces for human-driven reassignment; it only lets an agent (e.g.
 * Tim, the SSA) that receives a support request with no clear human
 * owner claim it and start triaging, rather than the ticket sitting
 * unowned until a human notices it. This is otherwise still the entire
 * ticket-side runner hook; it does not build or assume a specific
 * task-runner.
 */
class TicketsController extends ControllerBase
{
    /**
     * Attachment types the API accepts — the same allowlist as the backend
     * upload (TicketAttachmentActions::ALLOWED_ATTACHMENT_TYPES); keep the
     * two in step. Type is detected from the file's content, never from
     * the client's filename.
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
     * Uploads per principal user within UPLOAD_RATE_LIMIT_WINDOW before a
     * 429. Higher than the backend's 20 because one relay key (a game's
     * ticket bridge, e.g. XWar Legacy) uploads on behalf of all of that
     * game's players.
     */
    private const UPLOAD_RATE_LIMIT_MAX    = 60;
    private const UPLOAD_RATE_LIMIT_WINDOW = '-15 minutes';

    public function indexAction()
    {
        $conditions = [];
        $bind       = [];

        $status = (string) $this->request->getQuery('status', 'string', '');

        if ($status !== '') {
            $conditions[]   = 'status = :status:';
            $bind['status'] = $status;
        }

        $reporterUserId = $this->request->getQuery('reporter_user_id', 'int');

        if ($reporterUserId) {
            $conditions[]             = 'reporter_user_id = :reporter_user_id:';
            $bind['reporter_user_id'] = (int) $reporterUserId;
        }

        // Anyone who is not admin/operator/agent (a member account, a game's
        // service key) lists only the tickets they raised themselves.
        if (!$this->canSeeAllTickets()) {
            $apiKeyId = $this->currentPrincipal->apiKeyId();

            if ($apiKeyId !== null) {
                $conditions[]       = 'reporter_api_key_id = :own_key:';
                $bind['own_key']    = $apiKeyId;
            } else {
                $conditions[]       = 'reporter_user_id = :own_user:';
                $bind['own_user']   = (int) $this->currentPrincipal->userId();
            }
        }

        $params = ['order' => 'id DESC'];

        if ($conditions) {
            $params['conditions'] = implode(' AND ', $conditions);
            $params['bind']       = $bind;
        }

        $tickets = \Tickets::find($params);

        return $this->response->setJsonContent([
            'tickets' => array_map([$this, 'serialize'], iterator_to_array($tickets)),
        ]);
    }

    public function createAction()
    {
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'POST required']);
        }

        $body  = $this->getJsonBody();
        $title = trim((string) ($body['title'] ?? ''));

        if ($title === '') {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'title is required']);
        }

        $ticketType = isset($body['ticket_type']) ? (string) $body['ticket_type'] : 'bug';

        $ticket                   = new \Tickets();
        $ticket->title            = $title;
        $ticket->description      = isset($body['description']) ? (string) $body['description'] : null;
        $ticket->severity         = isset($body['severity']) ? (\Tickets::normalizeSeverity((string) $body['severity']) ?? 'normal') : 'normal';
        $ticket->ticket_type      = in_array($ticketType, ['bug', 'issue', 'feature', 'support'], true) ? $ticketType : 'bug';
        $ticket->source_ref       = isset($body['source_ref']) ? (string) $body['source_ref'] : null;
        $ticket->retest_agent_key = isset($body['retest_agent_key']) ? (string) $body['retest_agent_key'] : null;

        // Opt-in only (REQ-197): a plain bug/issue ticket has no business
        // getting a public-form write-back token. Set only when the
        // caller explicitly asks — currently the SSA close/complete
        // pipeline, wiring a customer-facing intake form to this REQ.
        // Random, not derived from the ticket id, so a raw sequential id
        // in a URL can never be enough to write into someone else's
        // ticket — see PublicIntakeController's docblock.
        $intakeToken = null;

        if (!empty($body['generate_intake_token'])) {
            $intakeToken        = bin2hex(random_bytes(32));
            $ticket->intake_token = $intakeToken;
        }

        // Never from client-supplied fields — always the authenticated
        // caller, so nobody can spoof someone else's identity as reporter.
        $ticket->reporter_user_id    = $this->principal['user_id'];
        $ticket->reporter_api_key_id = $this->principal['api_key_id'] ?? null;

        if (!$ticket->save()) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => implode(', ', $ticket->getMessages())]);
        }

        // created_at is DB-defaulted (see migration 011_tickets.sql), so
        // the in-memory object right after an insert still holds the raw
        // 'CURRENT_TIMESTAMP' default expression, not the value Postgres
        // actually computed — reload before returning it.
        $ticket->refresh();

        $this->response->setStatusCode(201, 'Created');

        $payload = ['ticket' => $this->serialize($ticket)];

        // Only ever returned here, at creation — never via serialize(),
        // same staff-only-field convention as notes/project (012/015).
        // The caller must capture it now; there is no read-it-back path.
        if ($intakeToken !== null) {
            $payload['intake_token'] = $intakeToken;
        }

        return $this->response->setJsonContent($payload);
    }

    public function viewAction($id)
    {
        $ticket = \Tickets::findFirstById($id);

        // Not found rather than forbidden for someone else's ticket, so ids cannot be probed.
        if (!$ticket || (!$this->canSeeAllTickets() && !$this->raisedByCaller($ticket))) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Not found']);
        }

        return $this->response->setJsonContent(['ticket' => $this->serialize($ticket)]);
    }

    /**
     * Accept one attachment from the service that raised a ticket. The
     * service API key is scoped to its own ticket, while staff may attach a
     * file to any ticket they can triage. The browser-facing frontend already
     * has the same allowlist and storage convention; this endpoint is the
     * server-to-server bridge used by XWar Legacy's in-game ticket form.
     */
    /**
     * Attach a file to a ticket (stack-internal ticket 99: XWar Legacy
     * relays in-game ticket uploads here). Multipart POST; the first
     * uploaded file is used. Who: staff (admin, operator) on any ticket;
     * anyone else only on a ticket they raised (same key, or the reporting
     * session user) — any other ticket is 404 so ids cannot be probed.
     * Optional `external_ref` (up to 100 chars, unique per ticket) makes a
     * retry after a timeout safe: a repeat with the same reference returns
     * the attachment stored the first time (200, duplicate true) rather
     * than a second copy.
     */
    public function uploadAttachmentAction($id)
    {
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'POST required']);
        }

        $ticket = \Tickets::findFirstById($id);
        $staff  = $this->isStaff();

        if (!$ticket || (!$staff && !$this->raisedByCaller($ticket))) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Not found']);
        }

        $externalRef = trim((string) $this->request->getPost('external_ref', 'string', ''));

        if (strlen($externalRef) > 100) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'external_ref must be 100 characters or fewer']);
        }

        if ($externalRef !== '') {
            $existing = $this->findAttachmentByRef((int) $ticket->id, $externalRef);

            if ($existing) {
                return $this->response->setJsonContent(['attachment' => $this->serializeAttachment($existing, $staff), 'duplicate' => true]);
            }
        }

        $userId = (int) $this->principal['user_id'];

        if ($this->isApiUploadRateLimited($userId)) {
            $this->response->setStatusCode(429, 'Too Many Requests');
            Audit::recordEvent('attachment_upload_blocked', $userId, ['ticket_id' => (int) $ticket->id, 'via' => 'api']);

            return $this->response->setJsonContent(['error' => 'Too many uploads — wait a few minutes and try again']);
        }

        if (!$this->request->hasFiles()) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'attachment is required']);
        }

        $file = $this->request->getUploadedFiles()[0] ?? null;

        if (!$file) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'attachment is required']);
        }

        $mime = $file->getRealType();
        $size = (int) $file->getSize();

        if (!isset(self::ALLOWED_ATTACHMENT_TYPES[$mime])) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'Unsupported file type']);
        }

        if ($size < 1 || $size > self::MAX_ATTACHMENT_BYTES) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'File must be between 1 byte and 10MB']);
        }

        /** @psalm-suppress UndefinedConstant BASE_PATH is defined by the web bootstrap. */
        $dir = BASE_PATH . '/storage/ticket-attachments/' . (int) $ticket->id;

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->response->setStatusCode(500, 'Internal Server Error');

            return $this->response->setJsonContent(['error' => 'Attachment storage is not writable']);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_ATTACHMENT_TYPES[$mime];
        $path     = $dir . '/' . $filename;

        if (!$file->moveTo($path)) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'Could not save attachment']);
        }

        $attachment                      = new \TicketAttachments();
        $attachment->ticket_id           = (int) $ticket->id;
        $attachment->filename            = $filename;
        $attachment->original_filename   = str_replace('"', '', $file->getName());
        $attachment->mime_type           = $mime;
        $attachment->size_bytes          = $size;
        $attachment->uploaded_by_user_id = $userId;
        $attachment->external_ref        = $externalRef !== '' ? $externalRef : null;

        try {
            $saved = $attachment->save();
        } catch (\Throwable $e) {
            // The unique (ticket_id, external_ref) index is the usual cause:
            // a concurrent retry stored the same reference between the
            // lookup above and this insert. Resolved below like a repeat.
            $saved = false;
        }

        if (!$saved) {
            unlink($path);

            if ($externalRef !== '') {
                $existing = $this->findAttachmentByRef((int) $ticket->id, $externalRef);

                if ($existing) {
                    return $this->response->setJsonContent(['attachment' => $this->serializeAttachment($existing, $staff), 'duplicate' => true]);
                }
            }

            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => implode(', ', array_map('strval', $attachment->getMessages()))]);
        }

        $this->response->setStatusCode(201, 'Created');

        return $this->response->setJsonContent(['attachment' => $this->serializeAttachment($attachment, $staff), 'duplicate' => false]);
    }

    /**
     * A ticket's attachments, ascending by id. Same scoping as
     * messagesAction(): staff on any ticket, otherwise only a ticket the
     * caller raised (404 for any other).
     */
    public function attachmentsAction($id)
    {
        if (!$this->request->isGet()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'GET required']);
        }

        $ticket = \Tickets::findFirstById($id);
        $staff  = $this->isStaff();

        if (!$ticket || (!$staff && !$this->raisedByCaller($ticket))) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Not found']);
        }

        return $this->response->setJsonContent([
            'ticket_id'   => (int) $ticket->id,
            'attachments' => $this->serializeAttachments((int) $ticket->id, $staff),
        ]);
    }

    /**
     * Streams one attachment's bytes with its stored mime type and the
     * original filename as a download. Scoping as attachmentsAction().
     */
    public function downloadAttachmentAction($id, $attachmentId)
    {
        if (!$this->request->isGet()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'GET required']);
        }

        $ticket = \Tickets::findFirstById($id);

        if (!$ticket || (!$this->isStaff() && !$this->raisedByCaller($ticket))) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Not found']);
        }

        $attachment = \TicketAttachments::findFirst([
            'conditions' => 'id = :attachment_id: AND ticket_id = :ticket_id:',
            'bind'       => ['attachment_id' => (int) $attachmentId, 'ticket_id' => (int) $ticket->id],
        ]);

        if (!$attachment instanceof \TicketAttachments) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Not found']);
        }

        $path = BASE_PATH . '/storage/ticket-attachments/' . (int) $attachment->ticket_id . '/' . $attachment->filename;

        if (!is_file($path)) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Attachment file is missing on disk']);
        }

        // setFileToSend() streams via Response::send()'s own readfile(), not
        // the content string the bootstrap echoes, so this sends and exits
        // itself — same as the backend/frontend download actions.
        $this->response->setContentType((string) $attachment->mime_type);
        $this->response->setHeader('X-Content-Type-Options', 'nosniff');
        $this->response->setFileToSend($path, str_replace('"', '', (string) $attachment->original_filename), true);
        $this->response->send();
        exit;
    }

    public function retestResultAction($id)
    {
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'POST required']);
        }

        if (!$this->canSeeAllTickets()) {
            $this->response->setStatusCode(403, 'Forbidden');

            return $this->response->setJsonContent(['error' => 'Forbidden']);
        }

        $ticket = \Tickets::findFirstById($id);

        if (!$ticket) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Not found']);
        }

        $body   = $this->getJsonBody();
        $result = $body['result'] ?? null;

        if (!in_array($result, ['pass', 'fail'], true)) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => "result must be 'pass' or 'fail'"]);
        }

        $now = date('Y-m-d H:i:s');

        $ticket->last_retest_result = $result;
        $ticket->last_retest_at     = $now;
        $ticket->last_retest_notes  = isset($body['notes']) ? (string) $body['notes'] : null;

        if ($result === 'pass') {
            $ticket->status         = 'closed';
            $ticket->closed_at      = $now;
            $ticket->auto_closed_at = $now;
            $ticket->close_reason   = 'auto_retest';
        } else {
            $ticket->status = 'in_review';
        }

        if (!$ticket->save()) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => implode(', ', $ticket->getMessages())]);
        }

        return $this->response->setJsonContent(['ticket' => $this->serialize($ticket)]);
    }

    /**
     * REQ-168: manual close via the API, admin/operator callers only —
     * see this class's own docblock for the Session 18 authority
     * decision. Gated per-action (not via ControllerBase's
     * $allowedRoles/onConstruct(), which would apply to every action in
     * this controller including createAction()/retestResultAction(),
     * both meant to stay reachable by agent-role callers).
     *
     * Side effects mirror App_skeleton\Modules\Backend\Controllers\
     * TicketTriageActions::closeAction() exactly (status/closed_at) with
     * one deliberate difference: close_reason is caller-supplied here
     * instead of the backend action's hardcoded 'manual', and required
     * — a short code (<=20 chars, matching the column and the existing
     * 'manual'/'auto_retest' values, see the check below), not the
     * root-cause narrative itself. notes is optional and, when given, is
     * stored on the ticket's existing notes field (TEXT, the same field
     * the backend edit form writes to, "staff-only... never returned by
     * the API module's serialize()" per migration 012's own comment —
     * this endpoint writes it but still never returns it, same as
     * serialize() already didn't) as the accompanying root-cause/fix
     * note Session 18 conditioned this authority on.
     */
    public function closeAction($id)
    {
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'POST required']);
        }

        if (!$this->currentPrincipal->hasAnyRole(\Roles::idsByNames(['admin', 'operator']))) {
            $this->response->setStatusCode(403, 'Forbidden');

            return $this->response->setJsonContent(['error' => 'Forbidden']);
        }

        $ticket = \Tickets::findFirstById($id);

        if (!$ticket) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Not found']);
        }

        $body        = $this->getJsonBody();
        $closeReason = trim((string) ($body['close_reason'] ?? ''));

        // close_reason is a short code, not the root-cause narrative —
        // tickets.close_reason is VARCHAR(20) (migration 011_tickets.sql),
        // matching the existing values this column already holds
        // ('manual' from the backend close action, 'auto_retest' from
        // retestResultAction() above). The actual root-cause/fix note
        // Session 18 conditioned this endpoint on belongs in `notes`
        // (TEXT, unbounded) below, not here.
        if ($closeReason === '') {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'close_reason is required']);
        }

        if (strlen($closeReason) > 20) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'close_reason must be 20 characters or fewer']);
        }

        $ticket->status       = 'closed';
        $ticket->closed_at    = date('Y-m-d H:i:s');
        $ticket->close_reason = $closeReason;

        if (isset($body['notes'])) {
            $ticket->notes = (string) $body['notes'];
        }

        if (!$ticket->save()) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => implode(', ', $ticket->getMessages())]);
        }

        return $this->response->setJsonContent(['ticket' => $this->serialize($ticket)]);
    }

    /**
     * Claim an unassigned ticket for the calling principal. Deliberately
     * self-only (no target user id accepted) and unassigned-only (won't
     * steal a ticket already assigned to someone else) — see this
     * class's docblock for why this doesn't reopen the general
     * agents-can't-be-assignees question. Idempotent if the caller has
     * already claimed it.
     */
    public function selfAssignAction($id)
    {
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'POST required']);
        }

        $ticket = \Tickets::findFirstById($id);

        if (!$ticket) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Not found']);
        }

        $callerId = (int) $this->principal['user_id'];

        if ($ticket->assigned_to_user_id !== null && (int) $ticket->assigned_to_user_id !== $callerId) {
            $this->response->setStatusCode(409, 'Conflict');

            return $this->response->setJsonContent(['error' => 'Ticket is already assigned to a different user']);
        }

        $ticket->assigned_to_user_id = $callerId;

        if (!$ticket->save()) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => implode(', ', $ticket->getMessages())]);
        }

        return $this->response->setJsonContent(['ticket' => $this->serialize($ticket)]);
    }

    /**
     * Staff reply on a ticket, visible to the game/service client that
     * raised it (GX-SPEC-05). admin/operator only. Body is a JSON
     * {"body": "...", "external_ref": "..."}; external_ref is optional and
     * makes a retry return the original message instead of a duplicate.
     * Does not change the ticket's status — a reply to a closed ticket
     * stays "closed, reply available" rather than silently reopening it.
     */
    public function replyAction($id)
    {
        return $this->createMessage($id, \TicketMessages::VISIBILITY_PUBLIC);
    }

    /**
     * Staff-only internal note. Same shape as replyAction(), but the
     * message is never returned to a client by messagesAction().
     */
    public function noteAction($id)
    {
        return $this->createMessage($id, \TicketMessages::VISIBILITY_INTERNAL);
    }

    /**
     * Public follow-up from the service/game that originally raised the
     * ticket. The API key must be the ticket's reporter key, so a client can
     * add context to its own ticket without gaining staff reply or note
     * authority. A follow-up on a closed ticket reopens it (same field reset
     * as the staff "Reopen" action); other statuses are left alone.
     */
    public function clientReplyAction($id)
    {
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'POST required']);
        }

        $ticket = \Tickets::findFirstById($id);

        if (!$ticket || !$this->raisedByCaller($ticket)) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Not found']);
        }

        return $this->createMessage($id, \TicketMessages::VISIBILITY_PUBLIC, false);
    }

    /**
     * The ticket's conversation, ascending by message id, after an
     * optional ?after_id= cursor (and ?limit=, default 100, max 200).
     * Staff see public and internal messages. Anyone else sees only the
     * public ones, and only on a ticket they raised (the API key that
     * created it, or the reporting user for a session caller); any other
     * ticket is a 404 so a sequential id never confirms a ticket exists.
     * An after_id that is not a message of this ticket (or, for a client,
     * not a visible one) is a 422, never a silent skip.
     */
    public function messagesAction($id)
    {
        if (!$this->request->isGet()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'GET required']);
        }

        $ticket = \Tickets::findFirstById($id);
        $staff  = $this->isStaff();

        if (!$ticket || (!$staff && !$this->raisedByCaller($ticket))) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Not found']);
        }

        $afterRaw = $this->request->getQuery('after_id');
        $limitRaw = $this->request->getQuery('limit');

        if (($afterRaw !== null && $afterRaw !== '' && !ctype_digit((string) $afterRaw))
            || ($limitRaw !== null && $limitRaw !== '' && (!ctype_digit((string) $limitRaw) || (int) $limitRaw < 1))) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'after_id and limit must be positive integers']);
        }

        $afterId = ($afterRaw === null || $afterRaw === '') ? 0 : (int) $afterRaw;
        $limit   = ($limitRaw === null || $limitRaw === '') ? 100 : min((int) $limitRaw, 200);

        if ($afterId > 0) {
            $cursor = \TicketMessages::findFirst([
                'conditions' => 'id = :id: AND ticket_id = :ticket_id:',
                'bind'       => ['id' => $afterId, 'ticket_id' => (int) $ticket->id],
            ]);

            if (!$cursor || (!$staff && $cursor->visibility !== \TicketMessages::VISIBILITY_PUBLIC)) {
                $this->response->setStatusCode(422, 'Unprocessable Entity');

                return $this->response->setJsonContent(['error' => 'after_id is not a message of this ticket']);
            }
        }

        $messages = \TicketMessages::forTicket((int) $ticket->id, $staff, $afterId, $limit + 1);
        $hasMore  = count($messages) > $limit;

        if ($hasMore) {
            array_pop($messages);
        }

        $last = $messages ? (int) end($messages)->id : $afterId;

        return $this->response->setJsonContent([
            'ticket'   => [
                'id'           => (int) $ticket->id,
                'status'       => $ticket->status,
                'source_ref'   => $ticket->source_ref,
                'closed_at'    => $ticket->closed_at,
                'close_reason' => $ticket->close_reason,
            ],
            'messages'    => array_map(fn (\TicketMessages $m) => $this->serializeMessage($m, $staff), $messages),
            'last_id'     => $last,
            'has_more'    => $hasMore,
            // Ticket-level, not paged: a file belongs to the ticket, not to
            // one message, and this lets a staff reply's files reach the
            // client that raised the ticket.
            'attachments' => $this->serializeAttachments((int) $ticket->id, $staff),
        ]);
    }

    /** admin, operator and agent callers work every ticket; anyone else sees only what they raised. */
    private function canSeeAllTickets(): bool
    {
        return $this->currentPrincipal->hasAnyRole(\Roles::idsByNames(['admin', 'operator', 'agent']));
    }

    private function isStaff(): bool
    {
        return $this->currentPrincipal->hasAnyRole(\Roles::idsByNames(['admin', 'operator']));
    }

    private function raisedByCaller(\Tickets $ticket): bool
    {
        $apiKeyId = $this->currentPrincipal->apiKeyId();

        if ($apiKeyId !== null) {
            return $ticket->reporter_api_key_id !== null && (int) $ticket->reporter_api_key_id === $apiKeyId;
        }

        $userId = $this->currentPrincipal->userId();

        return $userId !== null && $ticket->reporter_user_id !== null && (int) $ticket->reporter_user_id === $userId;
    }

    private function createMessage($id, string $visibility, bool $staffOnly = true)
    {
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'POST required']);
        }

        if ($staffOnly && !$this->isStaff()) {
            $this->response->setStatusCode(403, 'Forbidden');

            return $this->response->setJsonContent(['error' => 'Forbidden']);
        }

        $ticket = \Tickets::findFirstById($id);

        if (!$ticket) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Not found']);
        }

        $json = $this->getJsonBody();
        $body = trim((string) ($json['body'] ?? ''));
        $ref  = isset($json['external_ref']) ? trim((string) $json['external_ref']) : '';

        if ($body === '' || mb_strlen($body) > \TicketMessages::MAX_BODY_LENGTH) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'body is required and must be at most ' . \TicketMessages::MAX_BODY_LENGTH . ' characters']);
        }

        if (strlen($ref) > 100) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'external_ref must be 100 characters or fewer']);
        }

        $authorUserId = $this->currentPrincipal->userId();

        if ($ref !== '') {
            $existing = \TicketMessages::findFirst([
                'conditions' => 'ticket_id = :ticket_id: AND author_user_id = :author: AND external_ref = :ref:',
                'bind'       => ['ticket_id' => (int) $ticket->id, 'author' => $authorUserId, 'ref' => $ref],
            ]);

            if ($existing) {
                if ($existing->visibility !== $visibility || $existing->body !== $body) {
                    $this->response->setStatusCode(409, 'Conflict');

                    return $this->response->setJsonContent(['error' => 'external_ref was already used for a different message']);
                }

                return $this->response->setJsonContent(['message' => $this->serializeMessage($existing, $staffOnly), 'duplicate' => true]);
            }
        }

        $message                    = new \TicketMessages();
        $message->ticket_id         = (int) $ticket->id;
        $message->author_user_id    = $authorUserId;
        $message->author_api_key_id = $this->currentPrincipal->apiKeyId();
        $message->visibility        = $visibility;
        $message->body              = $body;
        $message->external_ref      = $ref !== '' ? $ref : null;

        if (!$message->save()) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => implode(', ', $message->getMessages())]);
        }

        if (!$staffOnly && $ticket->status === 'closed') {
            $ticket->status         = 'open';
            $ticket->closed_at      = null;
            $ticket->auto_closed_at = null;
            $ticket->close_reason   = null;
        }

        $ticket->save(); // bumps updated_at so the ticket sorts as recently active

        $this->response->setStatusCode(201, 'Created');

        return $this->response->setJsonContent(['message' => $this->serializeMessage($message, $staffOnly), 'duplicate' => false]);
    }

    /**
     * Staff see authorship ids and external_ref; a client sees only what
     * it needs. A client never sees a staff member's name or email — every
     * public message reads as "XTen Support".
     */
    private function serializeMessage(\TicketMessages $message, bool $staffView): array
    {
        $out = [
            'id'           => (int) $message->id,
            'ticket_id'    => (int) $message->ticket_id,
            'visibility'   => $message->visibility,
            'author_label' => 'XTen Support',
            'body'         => $message->body,
            'created_at'   => $message->created_at,
        ];

        if ($staffView) {
            $out['external_ref']       = $message->external_ref;
            $out['author_user_id']     = $message->author_user_id !== null ? (int) $message->author_user_id : null;
            $out['author_api_key_id']  = $message->author_api_key_id !== null ? (int) $message->author_api_key_id : null;
        }

        return $out;
    }

    private function findAttachmentByRef(int $ticketId, string $externalRef): ?\TicketAttachments
    {
        $found = \TicketAttachments::findFirst([
            'conditions' => 'ticket_id = :ticket_id: AND external_ref = :external_ref:',
            'bind'       => ['ticket_id' => $ticketId, 'external_ref' => $externalRef],
        ]);

        return $found instanceof \TicketAttachments ? $found : null;
    }

    private function isApiUploadRateLimited(int $userId): bool
    {
        $since = date('Y-m-d H:i:s', strtotime(self::UPLOAD_RATE_LIMIT_WINDOW));

        return \TicketAttachments::count([
            'conditions' => 'uploaded_by_user_id = :user_id: AND created_at >= :since:',
            'bind'       => ['user_id' => $userId, 'since' => $since],
        ]) >= self::UPLOAD_RATE_LIMIT_MAX;
    }

    /** @return array<int, array<string, mixed>> */
    private function serializeAttachments(int $ticketId, bool $staffView): array
    {
        $rows = \TicketAttachments::find([
            'conditions' => 'ticket_id = :ticket_id:',
            'bind'       => ['ticket_id' => $ticketId],
            'order'      => 'id ASC',
        ]);

        return array_map(fn (\TicketAttachments $a) => $this->serializeAttachment($a, $staffView), iterator_to_array($rows, false));
    }

    private function serializeAttachment(\TicketAttachments $attachment, bool $staffView): array
    {
        $out = [
            'id'                => (int) $attachment->id,
            'ticket_id'         => $attachment->ticket_id,
            'original_filename' => $attachment->original_filename,
            'mime_type'         => $attachment->mime_type,
            'size_bytes'        => $attachment->size_bytes,
            'created_at'        => $attachment->created_at,
            'download_path'     => '/api/tickets/download-attachment/' . (int) $attachment->ticket_id . '/' . (int) $attachment->id,
        ];

        if ($staffView) {
            $out['external_ref']        = $attachment->external_ref;
            $out['uploaded_by_user_id'] = $attachment->uploaded_by_user_id !== null ? (int) $attachment->uploaded_by_user_id : null;
        }

        return $out;
    }

    private function serialize(\Tickets $ticket): array
    {
        return [
            'id'                          => (int) $ticket->id,
            'title'                       => $ticket->title,
            'description'                 => $ticket->description,
            'severity'                    => $ticket->severity,
            'ticket_type'                 => $ticket->ticket_type,
            'status'                      => $ticket->status,
            'source_ref'                  => $ticket->source_ref,
            'reporter_user_id'            => $ticket->reporter_user_id !== null ? (int) $ticket->reporter_user_id : null,
            'reporter_api_key_id'         => $ticket->reporter_api_key_id !== null ? (int) $ticket->reporter_api_key_id : null,
            'assigned_to_user_id'         => $ticket->assigned_to_user_id !== null ? (int) $ticket->assigned_to_user_id : null,
            'consolidated_into_ticket_id' => $ticket->consolidated_into_ticket_id !== null ? (int) $ticket->consolidated_into_ticket_id : null,
            'retest_ref'                  => $ticket->retest_ref,
            'retest_agent_key'            => $ticket->retest_agent_key,
            'last_retest_result'          => $ticket->last_retest_result,
            'last_retest_at'              => $ticket->last_retest_at,
            'last_retest_notes'           => $ticket->last_retest_notes,
            'closed_at'                   => $ticket->closed_at,
            'close_reason'                => $ticket->close_reason,
            'auto_closed_at'              => $ticket->auto_closed_at,
            'created_at'                  => $ticket->created_at,
            'updated_at'                  => $ticket->updated_at,
        ];
    }
}
