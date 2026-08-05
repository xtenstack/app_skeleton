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

    public function newAction()
    {
        $this->view->job = new \CronJobs();
    }

    public function editAction($id)
    {
        $job = \CronJobs::findFirstById($id);

        if (!$job) {
            $this->flash->error('Cron job was not found');

            return $this->dispatcher->forward(['controller' => 'cron', 'action' => 'index']);
        }

        $this->view->job = $job;
    }

    public function createAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'cron', 'action' => 'index']);
        }

        $job = new \CronJobs();

        $this->fillFromRequest($job);

        if (!$job->save()) {
            foreach ($job->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward(['controller' => 'cron', 'action' => 'new']);
        }

        $this->flash->success('Cron job was created successfully');

        return $this->dispatcher->forward(['controller' => 'cron', 'action' => 'index']);
    }

    public function updateAction($id)
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'cron', 'action' => 'index']);
        }

        $job = \CronJobs::findFirstById($id);

        if (!$job) {
            $this->flash->error('Cron job was not found');

            return $this->dispatcher->forward(['controller' => 'cron', 'action' => 'index']);
        }

        $this->fillFromRequest($job);

        if (!$job->save()) {
            foreach ($job->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward(['controller' => 'cron', 'action' => 'edit', 'params' => [$job->id]]);
        }

        $this->flash->success('Cron job was updated successfully');

        return $this->dispatcher->forward(['controller' => 'cron', 'action' => 'index']);
    }

    /**
     * No deleteAction — the `enabled` column is the intended way to stop
     * a job running (also lets runDueJobs() skip it with a single WHERE
     * clause rather than a soft-delete check). Nothing currently needs
     * to remove a cron_jobs row outright, and its run history
     * (cron_run_log) is worth keeping even for a job that's since been
     * disabled.
     */
    private function fillFromRequest(\CronJobs $job): void
    {
        $job->name        = (string) $this->request->getPost('name', 'string');
        $job->task        = (string) $this->request->getPost('task', 'string');
        $job->task_action = (string) $this->request->getPost('task_action', 'string');
        $job->frequency   = (string) $this->request->getPost('frequency', 'string');
        $job->enabled     = $this->request->getPost('enabled') ? 1 : 0;
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
