<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class AuditLogController extends ControllerBase
{
    protected ?array $allowedRoles = [1];

    // Every distinct value written to audit_log.action — see Audit::record()
    // (insert/update/delete from model hooks), Audit::reverse() (reversal),
    // Auth (login/login_failed/login_blocked/logout), CronController::runNowAction()
    // (cron_run_triggered), and TicketAttachmentActions (attachment_upload_blocked).
    // Grepped, not guessed, per RB-03 rollout instructions — keep in sync if a
    // new Audit::recordEvent() call site introduces another value.
    private const ACTIONS = [
        'insert', 'update', 'delete', 'reversal',
        'login', 'login_failed', 'login_blocked', 'logout',
        'cron_run_triggered', 'attachment_upload_blocked',
    ];

    public function indexAction(): void
    {
        $conditions = [];
        $bind       = [];

        // Status-filter button bar (list-view convention, RB-03) —
        // filters on the `action` column.
        $status = (string) $this->request->getQuery('status', 'string', '');

        if (in_array($status, self::ACTIONS, true)) {
            $conditions[]   = 'action = :status:';
            $bind['status'] = $status;
        } else {
            $status = '';
        }

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
            ['created' => 'id', 'entity_type' => 'entity_type', 'action' => 'action'],
            $conditions,
            $bind
        );

        $this->view->entries      = $list['results'];
        $this->view->currentStatus = $status;
        $this->view->listState    = $list;
        // Preserved on every search/sort/pagination link so navigating
        // those doesn't drop the current status filter.
        $this->view->preserveQuery = array_merge($list['preserve'], array_filter([
            'status' => $status !== '' ? $status : null,
        ], fn ($v) => $v !== null));
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
