<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class AuditLogController extends ControllerBase
{
    protected ?array $allowedRoles = [1];

    public function indexAction()
    {
        // Search/sort/pagination (list-view convention, RB-03). No bulk
        // operations on this list — the audit trail is immutable by
        // design (no Edit, no Delete, even for an admin), so there's
        // nothing to batch-apply. Search columns are restricted to the
        // audit_log table's own columns (entity_type/action) — the actor
        // email lives on a joined table, which ListView::paginate's ILIKE
        // search doesn't reach.
        $list = \App_skeleton\ListView::paginate(
            $this->request,
            \AuditLog::class,
            ['entity_type', 'action'],
            ['created' => 'id', 'entity_type' => 'entity_type', 'action' => 'action']
        );

        $this->view->entries      = $list['results'];
        $this->view->listState    = $list;
        $this->view->preserveQuery = [];
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
