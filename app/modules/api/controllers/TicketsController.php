<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api\Controllers;

/**
 * Scope: create + list + view + retest-result reporting only. Assignment,
 * consolidation, manual close, and QA review stay human-only via the
 * backend UI (App_skeleton\Modules\Backend\Controllers\TicketsController)
 * — ticket *triage authority* stays with humans even though agents can
 * create and eventually resolve-via-retest. This is the entire
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

        $ticket                   = new \Tickets();
        $ticket->title            = $title;
        $ticket->description      = isset($body['description']) ? (string) $body['description'] : null;
        $ticket->severity         = isset($body['severity']) ? (string) $body['severity'] : 'normal';
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

    private function serialize(\Tickets $ticket): array
    {
        return [
            'id'                          => (int) $ticket->id,
            'title'                       => $ticket->title,
            'description'                 => $ticket->description,
            'severity'                    => $ticket->severity,
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
