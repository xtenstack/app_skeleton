<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

/**
 * Ticket assignment, consolidation, close/reopen, and QA review — split
 * out of TicketsController (project audit, 2026-08-11, Tier 2 — that
 * file was 537 lines, the sole recurring Project Structure flag across
 * every audit report since 2026-08-04, even after TicketAttachmentActions
 * was split out). A trait, not a separate controller, same reasoning as
 * TicketAttachmentActions.php's own docblock: these are still reached as
 * plain tickets/assign/consolidate/close/reopen/qa-review routes on
 * TicketsController itself (app/config/routes.php's generic module-route
 * pattern), just moved out of that file's body. Requires the consuming
 * class to be a TicketsController-shaped ControllerBase (uses
 * $this->request/flash/dispatcher/session, same as any other action
 * here) and to define TICKET_TYPES (assignAction doesn't need it, but
 * humanUsers() and the class's own onConstruct role gate are shared
 * context this trait assumes is present).
 */
trait TicketTriageActions
{
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
        // them from being a valid *reassignment* target has to be
        // explicit here — it doesn't fall out for free the way a
        // separate identity type would have. This doesn't cover
        // self-assignment: an agent can still claim an unassigned
        // ticket for itself via the API's selfAssignAction() (see
        // Tickets model docblock) — this human-driven action just never
        // lets someone else hand a ticket to an agent.
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

        $outcome = (string) $this->request->getPost('outcome');

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
     * excluding `agent` (see this trait's own docblock: agents are
     * ordinary `users` rows now, so this exclusion doesn't fall out for
     * free). Also used by TicketsController::viewAction() to populate
     * the assignee picker, not just assignAction() here.
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
