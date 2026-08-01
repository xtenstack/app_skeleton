<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api\Controllers;

use Phalcon\Mvc\Controller;

class ControllerBase extends Controller
{
    /**
     * Role ids allowed to use this controller, or null for "any
     * authenticated user". Set per-subclass to restrict further.
     */
    protected ?array $allowedRoles = null;

    /**
     * The authenticated caller, normalized regardless of how it
     * authenticated — session cookie or API key. Every principal is a real
     * `users` row (agents included, see docs/ticketing-module-plan.md), so
     * role checks below apply uniformly no matter which auth path resolved
     * it. Null until onConstruct() runs (never null afterwards — a request
     * that can't resolve a principal is rejected before reaching an
     * action).
     */
    protected ?array $principal = null;

    protected function onConstruct()
    {
        $this->response->setContentType('application/json', 'UTF-8');

        if ($this->dispatcher->getControllerName() === 'session') {
            return;
        }

        if ($this->auth->isLoggedIn()) {
            $auth = $this->session->get('auth');
            $this->principal = ['type' => 'user', 'user_id' => $auth['id'], 'role_id' => $auth['role_id']];
        } else {
            $token = $this->apiKeyAuth->tokenFromRequest($this->request);
            $this->principal = $token ? $this->apiKeyAuth->resolve($token) : null;
        }

        if (!$this->principal) {
            $this->response->setStatusCode(401, 'Unauthorized');
            $this->response->setJsonContent(['error' => 'Not authenticated']);
            $this->response->send();
            exit;
        }

        if ($this->allowedRoles !== null && !in_array($this->principal['role_id'], $this->allowedRoles, true)) {
            $this->response->setStatusCode(403, 'Forbidden');
            $this->response->setJsonContent(['error' => 'Forbidden']);
            $this->response->send();
            exit;
        }
    }

    protected function currentUserPayload(): array
    {
        $auth = $this->session->get('auth');

        return [
            'id'      => $auth['id'] ?? null,
            'email'   => $auth['email'] ?? null,
            'role_id' => $auth['role_id'] ?? null,
        ];
    }

    /**
     * JSON request body as an assoc array, or [] for an empty/unparseable
     * body — shared by SessionController and TicketsController so both
     * parse the same way rather than duplicating this.
     */
    protected function getJsonBody(): array
    {
        $raw = $this->request->getRawBody();

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
