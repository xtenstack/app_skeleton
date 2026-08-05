<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

/**
 * Human-facing ticket triage. Assignment, consolidation, manual close, and
 * QA review only happen here — never via the API (see
 * App_skeleton\Modules\Api\Controllers\TicketsController) — ticket triage
 * authority stays with humans even though agents can create tickets and
 * eventually resolve them via retest.
 */
class TicketsController extends ControllerBase
{
    // uploadAttachmentAction/downloadAttachmentAction/deleteAttachmentAction
    // and their two ALLOWED_ATTACHMENT_TYPES/MAX_ATTACHMENT_BYTES consts —
    // see TicketAttachmentActions.php's own docblock for why this file
    // was split (project audit, Tier 2).
    use TicketAttachmentActions;

    protected ?array $allowedRoles = null; // resolved at runtime, see RBAC section — not hardcoded ids

    private const TICKET_TYPES = ['bug' => 'Bug', 'issue' => 'Issue', 'feature' => 'Feature', 'support' => 'Support'];

    protected function onConstruct()
    {
        $this->allowedRoles = \Roles::idsByNames(['admin', 'operator']);

        parent::onConstruct();
    }

    public function indexAction()
    {
        $conditions = [];
        $bind       = [];

        $filter = (string) $this->request->getQuery('filter', 'string', '');
        $status = (string) $this->request->getQuery('status', 'string', '');

        if ($filter === 'needs_qa') {
            $conditions[] = 'auto_closed_at IS NOT NULL AND qa_reviewed_at IS NULL';
        } elseif ($status !== '') {
            $conditions[]   = 'status = :status:';
            $bind['status'] = $status;
        }

        $assignedTo = $this->request->getQuery('assigned_to', 'int');

        if ($assignedTo) {
            $conditions[]         = 'assigned_to_user_id = :assigned_to:';
            $bind['assigned_to']  = (int) $assignedTo;
        }

        $params = ['order' => 'id DESC'];

        if ($conditions) {
            $params['conditions'] = implode(' AND ', $conditions);
            $params['bind']       = $bind;
        }

        $this->view->tickets       = \Tickets::find($params);
        $this->view->currentFilter = $filter;
        $this->view->currentStatus = $status;

        // Spot-check banner: what share of this week's auto-closes haven't
        // been QA-reviewed yet — a human-driven filtered queue rather than
        // an automated sampler (see plan section 6).
        $autoClosedThisWeek = \Tickets::find([
            'conditions' => "auto_closed_at >= :since:",
            'bind'       => ['since' => date('Y-m-d H:i:s', strtotime('-7 days'))],
        ]);

        $totalAutoClosed = count($autoClosedThisWeek);
        $needsQaCount    = 0;

        foreach ($autoClosedThisWeek as $ticket) {
            if (!$ticket->qa_reviewed_at) {
                $needsQaCount++;
            }
        }

        $this->view->needsQaCount   = $needsQaCount;
        $this->view->needsQaPercent = $totalAutoClosed > 0 ? (int) round($needsQaCount / $totalAutoClosed * 100) : 0;
    }

    public function newAction()
    {
        $this->view->ticket = new \Tickets();
    }

    public function createAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        $title = trim((string) $this->request->getPost('title', 'string'));

        if ($title === '') {
            $this->flash->error('Title is required');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'new']);
        }

        $ticketType = (string) $this->request->getPost('ticket_type', 'string');

        $ticket              = new \Tickets();
        $ticket->title       = $title;
        $ticket->description = (string) $this->request->getPost('description', 'string') ?: null;
        $ticket->severity    = (string) $this->request->getPost('severity', 'string') ?: 'normal';
        $ticket->ticket_type = isset(self::TICKET_TYPES[$ticketType]) ? $ticketType : 'bug';

        // Staff filing via the backend UI — reporter is always whoever's
        // logged in, never client-supplied, same principle as the API
        // controller's own createAction (see its docblock note re:
        // reporter_api_key_id staying null for backend-filed tickets).
        $ticket->reporter_user_id = $this->session->get('auth')['id'];

        if (!$ticket->save()) {
            foreach ($ticket->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            $this->view->ticket = $ticket;

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'new']);
        }

        $this->flash->success('Ticket created');

        return $this->response->redirect($this->url->get('backend/tickets/view/' . $ticket->id));
    }

    public function viewAction($id)
    {
        $ticket = \Tickets::findFirstById($id);

        if (!$ticket) {
            $this->flash->error('Ticket was not found');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        $this->view->ticket           = $ticket;
        $this->view->assignableUsers  = $this->humanUsers();
        $this->view->otherOpenTickets = \Tickets::find([
            'conditions' => 'id != :id:',
            'bind'       => ['id' => $ticket->id],
            'order'      => 'id DESC',
        ]);
    }

    public function editAction($id)
    {
        $ticket = \Tickets::findFirstById($id);

        if (!$ticket) {
            $this->flash->error('Ticket was not found');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        $this->view->ticket = $ticket;
    }

    public function updateAction($id)
    {
        $ticket = \Tickets::findFirstById($id);

        if (!$ticket) {
            $this->flash->error('Ticket was not found');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $title = trim((string) $this->request->getPost('title', 'string'));

        if ($title === '') {
            $this->flash->error('Title is required');
            $this->view->ticket = $ticket;

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'edit', 'params' => [$id]]);
        }

        $ticketType = (string) $this->request->getPost('ticket_type', 'string');

        $ticket->title       = $title;
        $ticket->description = (string) $this->request->getPost('description', 'string') ?: null;
        $ticket->severity    = (string) $this->request->getPost('severity', 'string') ?: 'normal';
        $ticket->ticket_type = isset(self::TICKET_TYPES[$ticketType]) ? $ticketType : $ticket->ticket_type;
        $ticket->notes       = (string) $this->request->getPost('notes', 'string') ?: null;

        if (!$ticket->save()) {
            foreach ($ticket->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            $this->view->ticket = $ticket;

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'edit', 'params' => [$id]]);
        }

        $this->flash->success('Ticket updated');

        return $this->response->redirect($this->url->get('backend/tickets/view/' . $ticket->id));
    }

    public function deleteAction($id)
    {
        $ticket = \Tickets::findFirstById($id);

        if (!$ticket) {
            $this->flash->error('Ticket was not found');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        if (!$ticket->softDelete()) {
            foreach ($ticket->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $this->flash->success('Ticket deleted');

        return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
    }

    /**
     * "With selected" bulk actions from the list view (delete, or update
     * severity/type/status across every checked ticket). Bulk status is
     * deliberately limited to close/reopen — the same two side-effecting
     * transitions closeAction/reopenAction already define — rather than a
     * raw status dropdown, since 'consolidated' needs a single merge
     * target and doesn't make sense applied to a batch.
     */
    public function bulkAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        $ids = array_filter(array_map('intval', (array) $this->request->getPost('ticket_ids', null, [])));

        if (!$ids) {
            $this->flash->error('No tickets were selected');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        $tickets = \Tickets::find([
            'conditions' => 'id IN ({ids:array})',
            'bind'       => ['ids' => $ids],
        ]);

        $bulkAction = (string) $this->request->getPost('bulk_action', 'string');

        if ($bulkAction === 'delete') {
            $count = 0;

            foreach ($tickets as $ticket) {
                if ($ticket->softDelete()) {
                    $count++;
                }
            }

            $this->flash->success($count . ' ticket(s) deleted');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        $ticketType = (string) $this->request->getPost('ticket_type', 'string');
        $severity   = (string) $this->request->getPost('severity', 'string');
        $status     = (string) $this->request->getPost('status', 'string');

        if ($ticketType === '' && $severity === '' && $status === '') {
            $this->flash->error('Choose at least one field to bulk-update');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        $count = 0;

        foreach ($tickets as $ticket) {
            if ($ticketType !== '' && isset(self::TICKET_TYPES[$ticketType])) {
                $ticket->ticket_type = $ticketType;
            }

            if ($severity !== '' && in_array($severity, ['low', 'normal', 'high', 'critical'], true)) {
                $ticket->severity = $severity;
            }

            if ($status === 'closed') {
                $ticket->status       = 'closed';
                $ticket->closed_at    = date('Y-m-d H:i:s');
                $ticket->close_reason = 'manual';
            } elseif ($status === 'open') {
                $ticket->status         = 'open';
                $ticket->closed_at      = null;
                $ticket->auto_closed_at = null;
                $ticket->close_reason   = null;
            }

            if ($ticket->save()) {
                $count++;
            }
        }

        $this->flash->success($count . ' ticket(s) updated');

        return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
    }

    public function assignAction($id)
    {
        $ticket = \Tickets::findFirstById($id);

        if (!$ticket) {
            $this->flash->error('Ticket was not found');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $assignedToUserId = (int) $this->request->getPost('assigned_to_user_id', 'int');
        $agentRoleId      = \Roles::idsByNames(['agent'])[0] ?? null;
        $assignee         = $assignedToUserId ? \Users::findFirstById($assignedToUserId) : null;

        // Agents are ordinary `users` rows in this revision, so excluding
        // them from being a valid assignee has to be explicit here — it
        // doesn't fall out for free the way a separate identity type
        // would have.
        if (!$assignee || ($agentRoleId !== null && (int) $assignee->role_id === $agentRoleId)) {
            $this->flash->error('Choose a valid human assignee');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $ticket->assigned_to_user_id = $assignee->id;

        if (!$ticket->save()) {
            foreach ($ticket->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('Ticket assigned to ' . $assignee->email);
        }

        return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
    }

    public function consolidateAction($id)
    {
        $ticket = \Tickets::findFirstById($id);

        if (!$ticket) {
            $this->flash->error('Ticket was not found');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $targetId = (int) $this->request->getPost('consolidated_into_ticket_id', 'int');
        $target   = $targetId ? \Tickets::findFirstById($targetId) : null;

        if (!$target || (int) $target->id === (int) $ticket->id) {
            $this->flash->error('Choose a valid ticket to consolidate into');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $ticket->consolidated_into_ticket_id = $target->id;
        $ticket->status                      = 'consolidated';

        if (!$ticket->save()) {
            foreach ($ticket->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('Ticket consolidated into #' . $target->id);
        }

        return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
    }

    public function closeAction($id)
    {
        $ticket = \Tickets::findFirstById($id);

        if (!$ticket) {
            $this->flash->error('Ticket was not found');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $ticket->status       = 'closed';
        $ticket->closed_at    = date('Y-m-d H:i:s');
        $ticket->close_reason = 'manual';

        if (!$ticket->save()) {
            foreach ($ticket->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('Ticket closed');
        }

        return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
    }

    public function reopenAction($id)
    {
        $ticket = \Tickets::findFirstById($id);

        if (!$ticket) {
            $this->flash->error('Ticket was not found');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $ticket->status         = 'open';
        $ticket->closed_at      = null;
        $ticket->auto_closed_at = null;
        $ticket->close_reason   = null;

        if (!$ticket->save()) {
            foreach ($ticket->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('Ticket reopened');
        }

        return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
    }

    public function qaReviewAction($id)
    {
        $ticket = \Tickets::findFirstById($id);

        if (!$ticket) {
            $this->flash->error('Ticket was not found');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $outcome = (string) $this->request->getPost('outcome', 'string');

        if (!in_array($outcome, ['confirmed', 'reopened'], true)) {
            $this->flash->error('Choose a valid QA outcome');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $ticket->qa_reviewed_at = date('Y-m-d H:i:s');
        $ticket->qa_reviewed_by = $this->session->get('auth')['id'];
        $ticket->qa_outcome     = $outcome;

        if ($outcome === 'reopened') {
            $ticket->status         = 'open';
            $ticket->closed_at      = null;
            $ticket->auto_closed_at = null;
            $ticket->close_reason   = null;
        }

        if (!$ticket->save()) {
            foreach ($ticket->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('QA review recorded');
        }

        return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
    }

    /**
     * Users eligible to be an assignee — every human role, explicitly
     * excluding `agent` (see class docblock: agents are ordinary `users`
     * rows now, so this exclusion doesn't fall out for free).
     */
    private function humanUsers(): \Phalcon\Mvc\Model\ResultsetInterface
    {
        $agentRoleId = \Roles::idsByNames(['agent'])[0] ?? null;

        $params = ['order' => 'email'];

        if ($agentRoleId !== null) {
            $params['conditions'] = 'role_id != :agent_role_id:';
            $params['bind']       = ['agent_role_id' => $agentRoleId];
        }

        return \Users::find($params);
    }
}
