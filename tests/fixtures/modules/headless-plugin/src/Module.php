<?php
declare(strict_types=1);

namespace SkeletonFixtures\HeadlessPlugin;

use Phalcon\Di\DiInterface;
use Phalcon\Mvc\ModuleDefinitionInterface;

/**
 * A service-only plugin: no controllers, no views, no menu. It exists to
 * be consumed by other modules, which is the shape Profile Assurance
 * (PAA) has in the game platform.
 */
class Module implements ModuleDefinitionInterface
{
    public function registerAutoloaders(?DiInterface $di = null)
    {
    }

    /**
     * Phalcon calls this only when a request is dispatched to THIS
     * module, so nothing registered here is visible to any other module.
     */
    public function registerServices(DiInterface $di)
    {
        $di->setShared('fixtureDispatchOnlyService', fn () => new \stdClass());
    }

    /**
     * Called by ModuleManager::registerSharedServices() for every enabled
     * module on every request, whichever module ends up handling it.
     */
    public function registerSharedServices(DiInterface $di): void
    {
        $di->setShared('fixtureSharedService', fn () => new \stdClass());
    }
}
