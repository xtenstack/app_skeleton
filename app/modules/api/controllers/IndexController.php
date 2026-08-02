<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api\Controllers;

class IndexController extends ControllerBase
{
    public function indexAction()
    {
        return $this->response->setJsonContent(['ok' => true, 'service' => 'app_skeleton api']);
    }

    /**
     * Forwarded here by the dispatcher's beforeException listener (see
     * services_web.php) for an /api/... URL whose controller/action doesn't
     * exist. ControllerBase::onConstruct() still runs first — an
     * unauthenticated caller gets its usual 401 rather than reaching this,
     * same as any other api action, so this only fires for an authenticated
     * caller hitting a genuinely unknown endpoint.
     */
    public function notFoundAction()
    {
        $this->response->setStatusCode(404, 'Not Found');

        return $this->response->setJsonContent(['error' => 'Not Found']);
    }

    /**
     * Forwarded here by the dispatcher's beforeException listener for any
     * other exception an api action lets escape. Already logged by that
     * listener before forwarding.
     */
    public function serverErrorAction()
    {
        $this->response->setStatusCode(500, 'Internal Server Error');

        return $this->response->setJsonContent(['error' => 'Internal server error']);
    }
}
