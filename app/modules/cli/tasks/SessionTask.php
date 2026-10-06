<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

use App_skeleton\LazySession;
use App_skeleton\SessionGc;

/**
 * Usage: ./run session gc
 *
 * Deletes session files (BASE_PATH/sessions) that nobody has used for
 * longer than the session lifetime (config session.lifetime, 7 days by
 * default) and reports how many it removed. Scheduled daily as the
 * "Session clean-up" cron job (seeded by ./run seed run); PHP's own
 * session garbage collection is off in the Docker image and not relied on.
 */
class SessionTask extends \Phalcon\Cli\Task
{
    public function mainAction(): void
    {
        echo 'Usage: ./run session gc' . PHP_EOL;
    }

    public function gcAction(): void
    {
        $dir      = LazySession::savePath();
        $lifetime = (int) ($this->config->session->lifetime ?? 7 * 24 * 60 * 60);

        if (!is_dir($dir)) {
            echo "No session directory at {$dir}; nothing to clean." . PHP_EOL;

            return;
        }

        $result  = SessionGc::purge($dir, $lifetime);
        $message = sprintf(
            'Removed %d session file(s) not used for more than %s.',
            $result['removed'],
            $lifetime % 86400 === 0 ? ($lifetime / 86400) . ' day(s)' : $lifetime . ' second(s)'
        );

        if ($result['failed'] > 0) {
            $message .= sprintf(' Could not remove %d.', $result['failed']);
        }

        echo $message . PHP_EOL;
    }
}
