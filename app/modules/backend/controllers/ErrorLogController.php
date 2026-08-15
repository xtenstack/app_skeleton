<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

/**
 * Browsable view of app/config/services_web.php's dispatch:beforeException
 * capture — lightweight self-hosted error monitoring (project audit,
 * Tier 3). Not a Sentry-equivalent (no alerting, no grouping/dedup) —
 * deliberately simple, matches what this project actually needs today.
 */
class ErrorLogController extends ControllerBase
{
    protected ?array $allowedRoles = [1];

    public function indexAction()
    {
        $status = (string) $this->request->getQuery('status', 'string', '');

        $conditions = [];
        $bind       = [];

        if ($status === 'unresolved') {
            $conditions[] = 'resolved_at IS NULL';
        } elseif ($status === 'resolved') {
            $conditions[] = 'resolved_at IS NOT NULL';
        }

        $list = \App_skeleton\ListView::paginate(
            $this->request,
            \ErrorLog::class,
            ['exception_class', 'message', 'file', 'request_uri'],
            ['created' => 'id', 'exception' => 'exception_class'],
            $conditions,
            $bind
        );

        $this->view->entries       = $list['results'];
        $this->view->currentStatus = $status;
        $this->view->listState     = $list;
        $this->view->preserveQuery = array_merge($list['preserve'], array_filter([
            'status' => $status !== '' ? $status : null,
        ], fn ($v) => $v !== null));
    }

    public function viewAction($id)
    {
        $entry = \ErrorLog::findFirstById($id);

        if (!$entry) {
            $this->flash->error('Error log entry was not found');

            return $this->dispatcher->forward(['controller' => 'error-log', 'action' => 'index']);
        }

        $this->view->entry = $entry;
    }

    public function resolveAction($id)
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'error-log', 'action' => 'view', 'params' => [$id]]);
        }

        $entry = \ErrorLog::findFirstById($id);

        if (!$entry) {
            $this->flash->error('Error log entry was not found');

            return $this->dispatcher->forward(['controller' => 'error-log', 'action' => 'index']);
        }

        $entry->resolved_at = $entry->resolved_at ? null : date('Y-m-d H:i:s');

        if ($entry->save()) {
            $this->flash->success($entry->resolved_at ? 'Marked resolved' : 'Marked unresolved');
        } else {
            foreach ($entry->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        }

        return $this->dispatcher->forward(['controller' => 'error-log', 'action' => 'view', 'params' => [$id]]);
    }
}
