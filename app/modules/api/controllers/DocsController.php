<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api\Controllers;

/**
 * GET /api/docs/openapi: the machine-readable API description (OpenAPI 3).
 *
 * Any authenticated caller: an API key (agents and services fetch it the
 * same way they call everything else) or a session (the backend's
 * API Docs page loads it from the browser). It is the generated file
 * app/modules/api/spec/openapi.json; the sources are docs/openapi/ and the
 * build is bin/build-openapi.mjs. The API is described to callers who can
 * authenticate, not to the open internet.
 */
class DocsController extends ControllerBase
{
    public function openapiAction()
    {
        $file = __DIR__ . '/../spec/openapi.json';

        if (!is_file($file)) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'API description not built']);
        }

        $this->response->setHeader('Cache-Control', 'private, max-age=300');

        return $this->response->setContent((string) file_get_contents($file));
    }
}
