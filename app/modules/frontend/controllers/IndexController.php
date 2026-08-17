<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Frontend\Controllers;

class IndexController extends ControllerBase
{
    /**
     * Guest landing page — moved here from backend/IndexController's old
     * guest.phtml (REQ-020/031: the base product ships a dedicated
     * frontend module rather than the landing page living inside the
     * admin module). A logged-in user landing on '/' gets bounced
     * straight to their actual home instead of seeing marketing copy —
     * same role check SessionController::loginAction() uses.
     */
    public function indexAction()
    {
        if ($this->auth->isLoggedIn()) {
            $roleId       = $this->session->get('auth')['role_id'] ?? null;
            $memberRoleId = \Roles::idsByNames(['member']);

            $home = in_array($roleId, $memberRoleId, true) ? 'frontend/dashboard' : 'backend';

            return $this->response->redirect($this->url->get($home));
        }
    }

    /**
     * REQ-172: the maintenance-mode view. Reached two ways — directly, if
     * someone bookmarks/links it, or (the real path) via
     * app/bootstrap_web.php overriding the request URI to this route for
     * any non-admin visitor while maintenance_mode is on. 'index' is
     * already guest-reachable (see frontend\ControllerBase's
     * UNAUTHENTICATED_CONTROLLERS), so no further exemption is needed
     * here for a logged-out visitor.
     */
    public function maintenanceAction(): void
    {
        $until = (string) $this->settings->get('maintenance_mode_until', '');
        $timestamp = $until !== '' ? strtotime($until) : false;

        $this->view->maintenanceUntilIso  = $timestamp !== false ? date('c', $timestamp) : null;
        $this->view->maintenanceUntilText = $timestamp !== false ? date('j M Y, g:i A', $timestamp) : null;
    }
}
