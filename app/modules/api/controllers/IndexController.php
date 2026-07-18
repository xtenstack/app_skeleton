<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api\Controllers;

class IndexController extends ControllerBase
{
    public function indexAction()
    {
        return $this->response->setJsonContent(['ok' => true, 'service' => 'app_skeleton api']);
    }
}
