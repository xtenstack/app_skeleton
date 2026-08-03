<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Frontend\Controllers;

use Phalcon\Mvc\Controller;

class ControllerBase extends Controller
{
    /**
     * Only the guest landing page is reachable without being logged in —
     * everything else in this module (the member dashboard) requires
     * auth, same split as backend's ControllerBase but without role
     * gating: this module has no admin-only surface.
     */
    private const UNAUTHENTICATED_CONTROLLERS = ['index'];

    protected function onConstruct()
    {
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Pragma', 'no-cache');

        $this->enforceCsrf();

        if (in_array($this->dispatcher->getControllerName(), self::UNAUTHENTICATED_CONTROLLERS, true)) {
            return;
        }

        if (!$this->auth->isLoggedIn()) {
            // No separate frontend login — signs in through the same
            // backend/session flow backend itself uses.
            $this->response->redirect($this->url->get('backend/session'))->send();
            exit;
        }
    }

    /**
     * Same rule as backend's ControllerBase::enforceCsrf() — this module
     * has no POST forms yet, but any added later get it for free rather
     * than needing to remember to add the check per-action.
     */
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
        $this->response->redirect($referer ?: $this->url->get('/'))->send();
        exit;
    }
}
