<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api\Controllers;

/**
 * Public, unauthenticated — receives Data Restore Audit intake-form
 * submissions from a static landing page on a different origin
 * (deploy.xten.au), hence CORS. Mirrors XTen.Marketing's
 * PublicLeadCaptureController (same shape: dedicated controller bypassing
 * the normal API-key/session principal requirement every other endpoint
 * in this module enforces via ControllerBase::onConstruct(), registered
 * directly rather than through the generic controller/action route).
 *
 * Deliberately does NOT accept a raw ticket id from the client. A REQ's
 * id is sequential and guessable — trusting it alone would let anyone
 * enumerate ids and write into other clients' tickets with no auth at
 * all, exactly the class of mistake this project's CLAUDE.md's "a new
 * endpoint with no explicit allowedRoles decision — temporarily public
 * is not a state this project ships" rule exists to catch. Instead, the
 * ticket-creation caller (Tim's fulfillment.py) opts in to generating a
 * random `intake_token` (TicketsController::createAction(),
 * generate_intake_token=true) and embeds it in the link sent to the
 * customer; this endpoint requires an exact token match before writing
 * anything, and only once — a submitted ticket's token is cleared so the
 * same link can't be replayed to overwrite a later submission.
 */
class PublicIntakeController extends \Phalcon\Mvc\Controller
{
    protected function onConstruct()
    {
        $this->view->disable();

        $origin = (string) $this->request->getHeader('Origin');

        if (in_array($origin, ['https://deploy.xten.au', 'https://xten.au'], true)) {
            $this->response->setHeader('Access-Control-Allow-Origin', $origin);
        }

        $this->response->setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $this->response->setHeader('Access-Control-Allow-Headers', 'Content-Type, Accept');
        $this->response->setContentType('application/json', 'UTF-8');

        if ($this->request->getMethod() === 'OPTIONS') {
            $this->response->setStatusCode(204);
            $this->response->send();
            exit;
        }
    }

    public function dataRestoreAuditAction()
    {
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'POST required']);
        }

        $body = $this->getJsonBody();

        // Honeypot, same convention as PublicLeadCaptureController — a
        // real submitter never sees this field (hidden via CSS on the
        // page); checked server-side since a bot skipping the page's own
        // JS would otherwise sail through a client-only check.
        if (($body['company_website'] ?? '') !== '') {
            return $this->response->setJsonContent(['ok' => true]);
        }

        $token = trim((string) ($body['token'] ?? ''));

        if ($token === '') {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'token is required']);
        }

        $ticket = \Tickets::findFirst([
            'conditions' => 'intake_token = :token:',
            'bind'       => ['token' => $token],
        ]);

        // \Tickets::findFirst() is typed against Phalcon's own
        // ModelInterface, not the concrete Tickets class — an explicit
        // instanceof (true in practice; Tickets::findFirst() cannot
        // return anything else) narrows it back so intake_data/
        // intake_submitted_at/intake_token below resolve as real
        // properties, not an interface access.
        if (!$ticket instanceof \Tickets) {
            // Same response whether the token never existed or was
            // already consumed — never confirm to an untrusted caller
            // which case it was.
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Invalid or already-used intake link']);
        }

        $intake = $body['intake'] ?? null;

        if (!is_array($intake) || $intake === []) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'intake is required']);
        }

        $ticket->intake_data         = json_encode($intake, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $ticket->intake_submitted_at = date('Y-m-d H:i:s');
        $ticket->intake_token        = null; // single-use — the link this consumed can't be replayed

        if (!$ticket->save()) {
            error_log('PublicIntakeController: failed to save intake — ' . implode(', ', $ticket->getMessages()));
            $this->response->setStatusCode(500, 'Internal Server Error');

            return $this->response->setJsonContent(['error' => 'Could not save — please email deploy@xten.au directly']);
        }

        $this->response->setStatusCode(201, 'Created');

        return $this->response->setJsonContent(['ok' => true]);
    }

    private function getJsonBody(): array
    {
        $raw = $this->request->getRawBody();

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
