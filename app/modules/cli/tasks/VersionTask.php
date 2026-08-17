<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

/**
 * Usage: ./run version
 */
class VersionTask extends \Phalcon\Cli\Task
{
    public function mainAction(): void
    {
        $config = $this->getDI()->get('config');

        echo $config['version'];
    }
}
