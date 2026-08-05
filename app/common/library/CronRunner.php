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
            if ($job->isDue()) {
                $results[] = $this->execute($job);
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
