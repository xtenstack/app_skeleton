<?php
declare(strict_types=1);

namespace SkeletonFixtures\UnflaggedHeadlessPlugin;

use Phalcon\Di\DiInterface;
use Phalcon\Mvc\ModuleDefinitionInterface;

/**
 * Headless like HeadlessPlugin, but its manifest forgets "routes": false.
 * The missing controllers directory is the safety net.
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
