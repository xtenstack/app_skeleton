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
     * Controllers reachable without being logged in — login itself, and
     * signup/email-verification/forgot-password (which by definition
     * can't require auth). The guest landing page now lives in the
     * `frontend` module (REQ-020/031), so 'index' as a whole no longer
     * needs a blanket exemption here — a guest hitting /backend directly
     * gets the normal redirect-to-login below, same as any other
     * protected controller.
     */
    private const UNAUTHENTICATED_CONTROLLERS = ['session', 'signup', 'password'];

    /**
     * Unlike the controller-wide exemptions above, these two actions on
     * 'index' must stay reachable by guests specifically — they're the
     * general 404/500 handler (REQ-024/026), reached via the router's
     * unmatched-route fallback and the dispatcher's beforeException
     * listener. Gating them behind login would mean a guest hitting a
     * mistyped URL gets bounced to the login page instead of a themed
     * error page — the exact bug REQ-024 fixed, reintroduced by
     * over-broadly removing 'index' from the controller list above.
     */
    private const UNAUTHENTICATED_INDEX_ACTIONS = ['notFound', 'serverError'];

    protected function onConstruct()
    {
        $this->preventCaching();
        $this->enforceCsrf();

        $controllerName = $this->dispatcher->getControllerName();

        if (in_array($controllerName, self::UNAUTHENTICATED_CONTROLLERS, true)) {
            return;
        }

        if ($controllerName === 'index' && in_array($this->dispatcher->getActionName(), self::UNAUTHENTICATED_INDEX_ACTIONS, true)) {
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
        // destroyIfValid: false is intentional, not the library default —
        // pages with more than one form (e.g. My Profile: avatar upload,
        // theme, and profile-details are three separate <form>s sharing one
        // embedded token) need that token to survive being used once, or
        // every form after the first 500s with a bogus "session expired" on
        // the very page it was issued for. preventCaching()'s no-store
        // headers are what actually stop the stale-token bfcache-replay
        // scenario a destroy-on-use token was guarding against; the token
        // itself still only lives until the next real page render.
        if ($this->security->getSessionToken() && $this->security->checkToken(null, null, false)) {
            return;
        }

        // Diagnostic for production ticket #5 (intermittent "session expired"
        // on login, no confirmed root cause as of Session 13) — the working
        // theory is a session-write race on very fast autofill+Enter submits,
        // not the token-reuse bug destroyIfValid:false already rules out.
        // No sensitive values logged (no token, no credentials), just enough
        // to correlate a future occurrence: whether a token existed at all
        // (a completely empty session points at the write-race theory; a
        // present-but-mismatched token points elsewhere).
        error_log(sprintf(
            'CSRF check failed: controller=%s action=%s session_id=%s had_token=%s referer=%s',
            $this->dispatcher->getControllerName(),
            $this->dispatcher->getActionName(),
            $this->session->getId(),
            $this->security->getSessionToken() ? 'yes' : 'no',
            $this->request->getHTTPReferer() ?: '(none)'
        ));

        $this->flash->error('Your session expired or the form was resubmitted — please try again.');

        $referer = $this->request->getHTTPReferer();
        $this->response->redirect($referer ?: $this->url->get('backend'))->send();
        exit;
    }
}
