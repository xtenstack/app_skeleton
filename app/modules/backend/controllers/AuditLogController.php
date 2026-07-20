<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class AuditLogController extends ControllerBase
{
    protected ?array $allowedRoles = [1];

    public function indexAction()
    {
        $this->view->entries = \AuditLog::find([
            'order' => 'id DESC',
            'limit' => 200,
        ]);
    }

    public function viewAction($id)
    {
        $entry = \AuditLog::findFirstById($id);

        if (!$entry) {
            $this->flash->error('Audit log entry was not found');

            return $this->dispatcher->forward(['controller' => 'audit-log', 'action' => 'index']);
        }

        $this->view->entry = $entry;
        $this->view->isReversible = $this->audit->isReversible($entry);
    }

    public function reverseAction($id)
    {
        $entry = \AuditLog::findFirstById($id);

        if (!$entry) {
            $this->flash->error('Audit log entry was not found');

            return $this->dispatcher->forward(['controller' => 'audit-log', 'action' => 'index']);
        }

        if ($this->audit->reverse($entry)) {
            $this->flash->success('Audit entry #' . $entry->id . ' was reversed');
        } else {
            $this->flash->error('This entry cannot be reversed (already reversed, or not a reversible change)');
        }

        return $this->dispatcher->forward([
            'controller' => 'audit-log',
            'action'     => 'view',
            'params'     => [$id],
        ]);
    }
}
