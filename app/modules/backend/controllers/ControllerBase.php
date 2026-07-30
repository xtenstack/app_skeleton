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
        $this->preventCaching();
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
     * Backend pages embed a one-time CSRF token that's destroyed the moment
     * it's used (see enforceCsrf's destroyIfValid: true below). Safari's
     * back-forward cache can resurrect an already-submitted page verbatim
     * — no request to the server — leaving stale, already-dead tokens in
     * any other form still on that page. That reads to the user as "session
     * expired" on an action they never actually failed. Forbidding the
     * cache is the fix; weakening the token to survive reuse is not.
     */
    private function preventCaching(): void
    {
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Pragma', 'no-cache');
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
        // destroyIfValid: true (also the library default) is intentional —
        // tokens are single-use; see preventCaching() for why that's safe.
        if ($this->security->getSessionToken() && $this->security->checkToken(null, null, true)) {
            return;
        }

        $this->flash->error('Your session expired or the form was resubmitted — please try again.');

        $referer = $this->request->getHTTPReferer();
        $this->response->redirect($referer ?: $this->url->get('backend'))->send();
        exit;
    }
}
