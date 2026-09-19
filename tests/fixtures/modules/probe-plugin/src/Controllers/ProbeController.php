<?php
declare(strict_types=1);

namespace SkeletonFixtures\ProbePlugin\Controllers;

use Phalcon\Http\Response;
use Phalcon\Mvc\Controller;

class ProbeController extends Controller
{
    public function indexAction(): Response
    {
        return (new Response())->setJsonContent([
            'dispatchOnly' => $this->getDI()->has('fixtureDispatchOnlyService'),
            'shared'       => $this->getDI()->has('fixtureSharedService'),
        ]);
    }
}
