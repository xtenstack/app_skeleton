<?php
declare(strict_types=1);

namespace XtenRequirements\Controllers;

/**
 * Just the module's routing landing spot (bare /requirements) plus the
 * shared dispatcher's notFound/serverError forward targets (see
 * app/config/services_web.php's beforeException listener) — the actual
 * UI lives in RequirementsController/ChangelogsController.
 */
class IndexController extends ControllerBase
{
    public function indexAction()
    {
        return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'index']);
    }

    public function notFoundAction()
    {
        $this->view->disable();
        $this->response->setStatusCode(404, 'Not Found');
        $this->response->setContent('<h1>404 Not Found</h1>');
    }

    public function serverErrorAction()
    {
        $this->view->disable();
        $this->response->setStatusCode(500, 'Internal Server Error');
        $this->response->setContent('<h1>Something went wrong</h1>');
    }
}
