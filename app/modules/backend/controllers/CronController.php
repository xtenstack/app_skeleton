<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

use App_skeleton\Audit;

class CronController extends ControllerBase
{
    protected ?array $allowedRoles = [1];

    public function indexAction()
    {
        $this->view->jobs     = \CronJobs::find(['order' => 'name']);
        $this->view->cronMode = $this->settings->get('cron_mode', 'manual');
    }

    /**
     * Full run history, most recent first — unlike cron_jobs' own
     * last_run_at/last_status (which only ever hold the latest run),
     * every past execution here has its own timestamp. $jobId optionally
     * scopes to one job (linked from that row's own "Log" action); with
     * no id, shows every job's history interleaved, including entries
     * with no cron_job_id at all (e.g. the daily backup script, which
     * writes here directly — see cron_run_log's migration comment).
     */
    public function logAction($jobId = null)
    {
        $conditions = [];
        $bind       = [];

        if ($jobId !== null) {
            $conditions[]    = 'cron_job_id = :jobId:';
            $bind['jobId']   = (int) $jobId;
            $this->view->job = \CronJobs::findFirst($jobId);
        } else {
            $this->view->job = null;
        }

        $params = ['order' => 'ran_at DESC', 'limit' => 200];

        if ($conditions) {
            $params['conditions'] = implode(' AND ', $conditions);
            $params['bind']       = $bind;
        }

        $this->view->entries = \CronRunLog::find($params);
    }

    public function runNowAction()
    {
        $cronMode = $this->settings->get('cron_mode', 'manual');

        if ($cronMode !== 'manual') {
            $this->flash->error("Cron mode is set to 'auto' — the scheduled CLI runner handles this, manual runs are disabled.");

            return $this->dispatcher->forward(['controller' => 'cron', 'action' => 'index']);
        }

        $auth = $this->session->get('auth');
        Audit::recordEvent('cron_run_triggered', $auth['id'] ?? null);

        $results = $this->cronRunner->runDueJobs();

        if (!$results) {
            $this->flash->success('No jobs were due.');
        } else {
            foreach ($results as $result) {
                if ($result['status'] === 'success') {
                    $this->flash->success($result['job'] . ': ran successfully');
                } else {
                    $this->flash->error($result['job'] . ': ' . $result['output']);
                }
            }
        }

        return $this->dispatcher->forward(['controller' => 'cron', 'action' => 'index']);
    }
}
