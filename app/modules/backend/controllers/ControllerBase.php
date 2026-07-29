<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

use Phalcon\Mvc\Controller;

class ControllerBase extends Controller
{
    /**
     * Role ids allowed to use this controller, or null for "any
     * authenticated backend user". Set per-subclass to restrict further,
     * e.g. protected ?array $allowedRoles = [1]; for admin-only.
     */
    protected ?array $allowedRoles = null;

    /**
     * Controllers reachable without being logged in — login itself,
     * signup/email-verification/forgot-password (which by definition can't
     * require auth), and the landing page (serves both guests and logged-in
     * users, branching itself on auth state rather than being gated here).
     */
    private const UNAUTHENTICATED_CONTROLLERS = ['session', 'signup', 'password', 'index'];

    protected function onConstruct()
    {
        $this->enforceCsrf();

        if (in_array($this->dispatcher->getControllerName(), self::UNAUTHENTICATED_CONTROLLERS, true)) {
            return;
        }

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

    /**
     * Every POST across the backend must carry the token the layout embeds
     * via <meta name="csrf-token">/JS auto-injection (see index.phtml).
     * Runs before the auth/role checks so even the unauthenticated
     * controllers (login, signup, forgot-password) are covered.
     */
    private function enforceCsrf(): void
    {
        if (!$this->request->isPost()) {
            return;
        }

        // checkToken() alone passes when the session has no token seeded
        // yet (nothing to compare against) — require one to actually exist
        // first, so a POST can't succeed without having loaded a real page.
        if ($this->security->getSessionToken() && $this->security->checkToken()) {
            return;
        }

        $this->flash->error('Your session expired or the form was resubmitted — please try again.');

        $referer = $this->request->getHTTPReferer();
        $this->response->redirect($referer ?: $this->url->get('backend'))->send();
        exit;
    }
}
