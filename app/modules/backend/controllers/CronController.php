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
