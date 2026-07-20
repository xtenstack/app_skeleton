<?php
declare(strict_types=1);

class CronJobs extends \Phalcon\Mvc\Model
{
    public $id;
    public $name;
    public $task;
    public $task_action;
    public $frequency;
    public $enabled;
    public $last_run_at;
    public $last_status;
    public $last_output;
    public $created_at;
    public $updated_at;

    public function initialize()
    {
        $this->setSource('cron_jobs');
        $this->keepSnapshots(true);
    }

    public function beforeSave()
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }

    public function isDue(): bool
    {
        if (!$this->last_run_at) {
            return true;
        }

        $next = strtotime($this->frequency, strtotime($this->last_run_at));

        return $next !== false && time() >= $next;
    }
}
