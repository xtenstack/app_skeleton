<?php
declare(strict_types=1);

namespace XtenRequirements\Controllers;

use Phalcon\Mvc\Controller;

/**
 * Mirrors app/modules/backend/controllers/ControllerBase.php's auth/CSRF/
 * role gate — this module is a separate Phalcon application-tier module
 * (its own routing namespace under /requirements/...), so it can't extend
 * the backend module's class directly, but every controller here is
 * reached only by an already-authenticated backend user (no guest/login
 * pages of its own — those stay in `backend`), so the unauthenticated-
 * controller exemptions that class needs don't apply here.
 */
class ControllerBase extends Controller
{
    protected ?array $allowedRoles = null;

    protected function onConstruct()
    {
        $this->preventCaching();
        $this->enforceCsrf();

        if (!$this->auth->isLoggedIn()) {
            $this->response->redirect($this->url->get('backend/session'))->send();
            exit;
        }

        if ($this->allowedRoles !== null) {
            $roleId = $this->session->get('auth')['role_id'] ?? null;

            if (!in_array($roleId, $this->allowedRoles, true)) {
                $this->response->setStatusCode(403, 'Forbidden');
                $this->response->setContent('<h1>403 Forbidden</h1><p>You do not have access to this page.</p>');
                $this->response->send();
                exit;
            }
        }
    }

    private function preventCaching(): void
    {
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Pragma', 'no-cache');
    }

    private function enforceCsrf(): void
    {
        if (!$this->request->isPost()) {
            return;
        }

        if ($this->security->getSessionToken() && $this->security->checkToken(null, null, false)) {
            return;
        }

        $this->flash->error('Your session expired or the form was resubmitted — please try again.');

        $referer = $this->request->getHTTPReferer();
        $this->response->redirect($referer ?: $this->url->get('backend'))->send();
        exit;
    }
}
