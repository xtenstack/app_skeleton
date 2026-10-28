<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

/**
 * Swagger UI over GET /api/docs/openapi. Any signed-in backend user; the
 * spec itself is served by the api module to any authenticated caller.
 */
class ApiDocsController extends ControllerBase
{
    public function indexAction(): void
    {
    }
}
