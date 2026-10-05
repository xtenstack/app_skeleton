<?php
declare(strict_types=1);

namespace App_skeleton;

/**
 * Thrown by ModuleManager::enableModule() when a module can't be enabled
 * yet: something it declares in module.json 'dependsOn' isn't installed
 * and enabled, or the manifest itself is invalid. The message is written
 * for the admin and names what the module is waiting on.
 */
class ModuleDependencyException extends \RuntimeException
{
}
