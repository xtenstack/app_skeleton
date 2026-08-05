<?php
declare(strict_types=1);

class CronRunLog extends \Phalcon\Mvc\Model
{
    public $id;
    public $cron_job_id;
    public $job_name;
    public $status;
    public $output;
    public $ran_at;

    public function initialize()
    {
        $this->setSource('cron_run_log');
    }
}
