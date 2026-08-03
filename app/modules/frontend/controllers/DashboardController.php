<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Frontend\Controllers;

/**
 * The non-admin member's own home — kept deliberately generic (not
 * role-gated to 'member' specifically) so any authenticated user can
 * land here, same "serves whoever's looking at it" spirit as the guest
 * landing page. Admin/operator/agent accounts are redirected to
 * `backend` instead by IndexController and SessionController, but
 * nothing stops them visiting this URL directly.
 */
class DashboardController extends ControllerBase
{
    public function indexAction()
    {
        $userId = (int) ($this->session->get('auth')['id'] ?? 0);

        $this->view->user = \Users::findFirstById($userId);
    }
}
