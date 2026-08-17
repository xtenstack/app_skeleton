<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class IndexController extends ControllerBase
{
    /**
     * Unreachable by guests — ControllerBase's onConstruct() already
     * redirects an unauthenticated request to login before this runs
     * (the guest splash moved to the `frontend` module, REQ-020/031).
     */
    public function indexAction(): void
    {
        $menu   = $this->moduleManager->mergedMenu('backend');
        $roleId = $this->session->get('auth')['role_id'] ?? null;

        $this->view->tiles = array_values(array_filter($menu, function ($item) use ($roleId) {
            if ($item['controller'] === 'index') {
                return false;
            }

            return $item['roles'] === null || in_array($roleId, $item['roles'], true);
        }));
    }

    /**
     * The router's default action (see services_web.php) — reached for any
     * URL that doesn't match a registered route at all, plus forwarded here
     * by the dispatcher's beforeException listener for a valid route whose
     * controller/action doesn't exist.
     */
    public function notFoundAction(): void
    {
        $this->response->setStatusCode(404, 'Not Found');
    }

    /**
     * Forwarded here by the dispatcher's beforeException listener for any
     * other exception an action lets escape. The exception itself is
     * already logged by that listener before forwarding — this action just
     * renders the page.
     */
    public function serverErrorAction(): void
    {
        $this->response->setStatusCode(500, 'Internal Server Error');
    }
}
