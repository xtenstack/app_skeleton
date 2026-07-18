<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Frontend\Controllers;

use Phalcon\Mvc\Controller;

class ControllerBase extends Controller
{
    protected function onConstruct()
    {
        if ($this->dispatcher->getControllerName() === 'session') {
            return;
        }

        if (!$this->auth->isLoggedIn()) {
            $this->response->redirect($this->url->get('frontend/session'))->send();
            exit;
        }
    }
}
