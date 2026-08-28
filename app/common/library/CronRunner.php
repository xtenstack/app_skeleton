<?php
declare(strict_types=1);

namespace App_skeleton;

use Phalcon\Di\Injectable;

/**
 * Shared by the CLI runner (CronTask, meant to be invoked by an OS cron
 * entry every minute) and the backend "Run now" button (manual mode) so
 * both do exactly the same due-check-and-execute logic.
 */
class CronRunner extends Injectable
{
    /**
     * @return array<int, array{job: string, status: string, output: string}>
     */
    public function runDueJobs(): array
    {
        $results = [];

        foreach (\CronJobs::find(['conditions' => 'enabled = 1']) as $job) {
            if (!$job->isDue()) {
                continue;
            }

            // CronTask is meant to be invoked fresh by an OS cron tick every
            // minute (see BackupTask's own docblock), and CronJobs::isDue()
            // only ever sees last_run_at as of the last completed run --
            // execute() doesn't persist it until the task returns. A job
            // that runs longer than the tick interval therefore still looks
            // "due" to the next tick's freshly-loaded row, and would
            // otherwise fire a second, overlapping execution of itself.
            // Confirmed live 2026-08-28: BackupTask's pg_dump ran past 60s
            // and the every-minute tick re-triggered it six times in ~10
            // minutes before one run finally errored out.
            //
            // A Postgres advisory lock keyed by the job's own id closes
            // this without a schema change or a lock file that could go
            // stale on a hard crash -- it's tied to this CLI process's own
            // database connection and Postgres releases it automatically
            // the moment that connection closes, crash or not.
            $locked = (bool) $this->db->fetchOne(
                'SELECT pg_try_advisory_lock(:id)::int AS locked',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['id' => $job->id]
            )['locked'];

            if (!$locked) {
                continue;
            }

            try {
                $results[] = $this->execute($job);
            } finally {
                $this->db->execute('SELECT pg_advisory_unlock(:id)', ['id' => $job->id]);
            }
        }

        return $results;
    }

    private function execute(\CronJobs $job): array
    {
        $taskClass = '\\App_skeleton\\Modules\\Cli\\Tasks\\' . $this->studly($job->task) . 'Task';
        $method    = $job->task_action . 'Action';

        $status = 'success';

        ob_start();

        try {
            if (!class_exists($taskClass) || !method_exists($taskClass, $method)) {
                throw new \RuntimeException("Unknown cron job target: {$job->task}/{$job->task_action}");
            }

            $task = new $taskClass();
            $task->setDI($this->getDI());
            $task->$method();
        } catch (\Throwable $e) {
            $status = 'error';
            echo $e->getMessage();
        }

        $output = trim(ob_get_clean());
        $ranAt  = date('Y-m-d H:i:s');

        $job->last_run_at = $ranAt;
        $job->last_status = $status;
        $job->last_output = $output;
        $job->save();

        $log              = new \CronRunLog();
        $log->cron_job_id = $job->id;
        $log->job_name    = $job->name;
        $log->status      = $status;
        $log->output      = $output;
        $log->ran_at      = $ranAt;
        $log->save();

        return ['job' => $job->name, 'status' => $status, 'output' => $output];
    }

    private function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }
}
