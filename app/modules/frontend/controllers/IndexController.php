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
}
