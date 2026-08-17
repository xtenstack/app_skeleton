<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

/**
 * Usage: ./run cron run
 *
 * Meant to be called by an OS cron entry every minute, e.g.:
 *
 *   * * * * * cd /opt/local/www/apache2/html/app_skeleton && ./run cron run >> logs/cron.log 2>&1
 *
 * Only relevant when the 'cron_mode' setting is 'auto' — in 'manual' mode
 * the same logic runs from the backend's "Run now" button instead, and
 * nothing should be scheduling this at the OS level. Claude won't add the
 * crontab entry itself (see AuditTask's docblock) — that's still on you.
 */
class CronTask extends \Phalcon\Cli\Task
{
    public function mainAction(): void
    {
        echo 'Usage: ./run cron run' . PHP_EOL;
    }

    /**
     * @return void
     */
    public function runAction()
    {
        $results = $this->getDI()->get('cronRunner')->runDueJobs();

        if (!$results) {
            echo 'No jobs due.' . PHP_EOL;

            return;
        }

        foreach ($results as $result) {
            echo "  {$result['job']}: {$result['status']}" . ($result['output'] ? " — {$result['output']}" : '') . PHP_EOL;
        }
    }
}
