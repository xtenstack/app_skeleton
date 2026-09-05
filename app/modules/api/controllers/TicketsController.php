<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api\Controllers;

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
        $ticket->severity         = isset($body['severity']) ? (string) $body['severity'] : 'normal';
        $ticket->ticket_type      = in_array($ticketType, ['bug', 'issue', 'feature', 'support'], true) ? $ticketType : 'bug';
        $ticket->source_ref       = isset($body['source_ref']) ? (string) $body['source_ref'] : null;
        $ticket->retest_agent_key = isset($body['retest_agent_key']) ? (string) $body['retest_agent_key'] : null;

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

        return $this->response->setJsonContent(['ticket' => $this->serialize($ticket)]);
    }

    public function viewAction($id)
    {
        $ticket = \Tickets::findFirstById($id);

        if (!$ticket) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Not found']);
        }

        return $this->response->setJsonContent(['ticket' => $this->serialize($ticket)]);
    }

    public function retestResultAction($id)
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

        $allowedRoleIds = \Roles::idsByNames(['admin', 'operator']);

        if (!in_array($this->principal['role_id'], $allowedRoleIds, true)) {
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
