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
    protected ?array $allowedRoles = null; // resolved at runtime, see RBAC section — not hardcoded ids

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
