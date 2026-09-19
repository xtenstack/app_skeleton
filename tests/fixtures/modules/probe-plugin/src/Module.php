<?php
declare(strict_types=1);

namespace SkeletonFixtures\ProbePlugin;

use Phalcon\Di\DiInterface;
use Phalcon\Mvc\ModuleDefinitionInterface;

/**
 * An ordinary routable plugin: it has a controller, so it keeps the
 * generic /<key>/... routes. Its one controller reports which services
 * it can see, which is how ModuleServicesAndRoutesTest observes the
 * container from inside a request handled by "some other module".
 */
class Module implements ModuleDefinitionInterface
{
    public function registerAutoloaders(?DiInterface $di = null)
    {
    }

    public function registerServices(DiInterface $di)
    {
    }
}
