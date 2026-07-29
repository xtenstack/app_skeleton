<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class IndexController extends ControllerBase
{
    public function indexAction()
    {
        if (!$this->auth->isLoggedIn()) {
            $this->view->pick('index/guest');

            return;
        }

        $menu   = $this->moduleManager->mergedMenu('backend');
        $roleId = $this->session->get('auth')['role_id'] ?? null;

        $this->view->tiles = array_values(array_filter($menu, function ($item) use ($roleId) {
            if ($item['controller'] === 'index') {
                return false;
            }

            return $item['roles'] === null || in_array($roleId, $item['roles'], true);
        }));
    }
}
