<?php
declare(strict_types=1);

namespace XtenRequirements\Controllers;

/**
 * Backend UI for the REQ-NNN log (see requirements-module's plan doc).
 * Status transitions happen here via a plain dropdown on the edit form —
 * this entity doesn't need Tickets' richer assign/consolidate/QA flow.
 */
class RequirementsController extends ControllerBase
{
    private const STATUSES = [
        'open'                            => 'Open',
        'in_progress'                     => 'In progress',
        'done_pending_changelog_decision' => 'Done — pending changelog decision',
        'complete'                        => 'Complete',
    ];

    private const CHANGELOG_DECISIONS = [
        'included'             => 'Included in a changelog',
        'excluded_deliberately' => 'Excluded deliberately',
    ];

    protected function onConstruct()
    {
        $this->allowedRoles = \Roles::idsByNames(['admin', 'operator']);

        parent::onConstruct();
    }

    public function indexAction()
    {
        $status = (string) $this->request->getQuery('status', 'string', '');

        $params = ['order' => 'id DESC'];

        if ($status !== '' && isset(self::STATUSES[$status])) {
            $params['conditions'] = 'status = :status:';
            $params['bind']       = ['status' => $status];
        }

        $this->view->requirements  = \Requirement::find($params);
        $this->view->currentStatus = $status;
        $this->view->statuses      = self::STATUSES;
    }

    public function newAction()
    {
        $this->view->requirement = new \Requirement();
        $this->view->statuses    = self::STATUSES;
    }

    public function createAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'index']);
        }

        $title = trim((string) $this->request->getPost('title', 'string'));

        if ($title === '') {
            $this->flash->error('Title is required');

            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'new']);
        }

        $requirement                 = new \Requirement();
        $requirement->display_id     = $this->nextDisplayId();
        $requirement->title          = $title;
        $requirement->description    = (string) $this->request->getPost('description', 'string') ?: null;
        $requirement->origin_session = (string) $this->request->getPost('origin_session', 'string') ?: null;
        $requirement->status         = 'open';

        if (!$requirement->save()) {
            foreach ($requirement->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            $this->view->requirement = $requirement;
            $this->view->statuses    = self::STATUSES;

            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'new']);
        }

        $this->flash->success($requirement->display_id . ' created');

        return $this->response->redirect($this->url->get('requirements/requirements/view/' . $requirement->id));
    }

    public function viewAction($id)
    {
        $requirement = \Requirement::findFirstById($id);

        if (!$requirement) {
            $this->flash->error('Requirement was not found');

            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'index']);
        }

        $this->view->requirement = $requirement;
    }

    public function editAction($id)
    {
        $requirement = \Requirement::findFirstById($id);

        if (!$requirement) {
            $this->flash->error('Requirement was not found');

            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'index']);
        }

        $this->view->requirement = $requirement;
        $this->view->statuses    = self::STATUSES;
        $this->view->decisions   = self::CHANGELOG_DECISIONS;
    }

    public function updateAction($id)
    {
        $requirement = \Requirement::findFirstById($id);

        if (!$requirement) {
            $this->flash->error('Requirement was not found');

            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'view', 'params' => [$id]]);
        }

        $title = trim((string) $this->request->getPost('title', 'string'));

        if ($title === '') {
            $this->flash->error('Title is required');
            $this->view->requirement = $requirement;
            $this->view->statuses    = self::STATUSES;
            $this->view->decisions   = self::CHANGELOG_DECISIONS;

            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'edit', 'params' => [$id]]);
        }

        $status = (string) $this->request->getPost('status', 'string');

        if (!isset(self::STATUSES[$status])) {
            $this->flash->error('Choose a valid status');

            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'edit', 'params' => [$id]]);
        }

        $requirement->title          = $title;
        $requirement->description    = (string) $this->request->getPost('description', 'string') ?: null;
        $requirement->origin_session = (string) $this->request->getPost('origin_session', 'string') ?: null;
        $requirement->changelog_note = (string) $this->request->getPost('changelog_note', 'string') ?: null;
        $requirement->status         = $status;

        if ($status === 'complete') {
            $decision = (string) $this->request->getPost('changelog_decision', 'string');

            // A requirement already linked to a changelog (changelog_id
            // set by ChangelogsController's picker) is implicitly
            // 'included' — this form has no way to unlink it, so its
            // decision can't be flipped to 'excluded_deliberately' here.
            if ($requirement->changelog_id) {
                $decision = 'included';
            }

            if (!isset(self::CHANGELOG_DECISIONS[$decision])) {
                $this->flash->error('Choose whether this requirement was included in a changelog or excluded deliberately');
                $this->view->requirement = $requirement;
                $this->view->statuses    = self::STATUSES;
                $this->view->decisions   = self::CHANGELOG_DECISIONS;

                return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'edit', 'params' => [$id]]);
            }

            $requirement->changelog_decision = $decision;
        } else {
            // Leaving 'complete' means the decision no longer applies —
            // and un-linking here (rather than leaving a stale
            // changelog_id behind) keeps ChangelogsController's "already
            // linked" picker exclusion correct.
            $requirement->changelog_decision = null;
            $requirement->changelog_id       = null;
        }

        if (!$requirement->save()) {
            foreach ($requirement->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            $this->view->requirement = $requirement;
            $this->view->statuses    = self::STATUSES;
            $this->view->decisions   = self::CHANGELOG_DECISIONS;

            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'edit', 'params' => [$id]]);
        }

        $this->flash->success($requirement->display_id . ' updated');

        return $this->response->redirect($this->url->get('requirements/requirements/view/' . $requirement->id));
    }

    public function deleteAction($id)
    {
        $requirement = \Requirement::findFirstById($id);

        if (!$requirement) {
            $this->flash->error('Requirement was not found');

            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'view', 'params' => [$id]]);
        }

        if (!$requirement->softDelete()) {
            foreach ($requirement->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'view', 'params' => [$id]]);
        }

        $this->flash->success($requirement->display_id . ' deleted');

        return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'index']);
    }

    /**
     * "With selected" bulk delete/status-update from the list view — see
     * the list-view convention doc. Status is the only bulk-editable
     * field here (no richer per-record fields worth batch-editing).
     */
    public function bulkAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'index']);
        }

        $ids = array_filter(array_map('intval', (array) $this->request->getPost('requirement_ids', null, [])));

        if (!$ids) {
            $this->flash->error('No requirements were selected');

            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'index']);
        }

        $requirements = \Requirement::find([
            'conditions' => 'id IN ({ids:array})',
            'bind'       => ['ids' => $ids],
        ]);

        $bulkAction = (string) $this->request->getPost('bulk_action', 'string');

        if ($bulkAction === 'delete') {
            $count = 0;

            foreach ($requirements as $requirement) {
                if ($requirement->softDelete()) {
                    $count++;
                }
            }

            $this->flash->success($count . ' requirement(s) deleted');

            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'index']);
        }

        $status = (string) $this->request->getPost('status', 'string');

        // 'complete' needs a changelog_decision this form has no field
        // for — bulk-completing isn't a supported transition, only the
        // per-record edit form (or ChangelogsController's picker) can
        // move a requirement to 'complete'.
        if ($status === '' || $status === 'complete' || !isset(self::STATUSES[$status])) {
            $this->flash->error('Choose a status to bulk-update to');

            return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'index']);
        }

        $count = 0;

        foreach ($requirements as $requirement) {
            $requirement->status             = $status;
            $requirement->changelog_decision = null;
            $requirement->changelog_id       = null;

            if ($requirement->save()) {
                $count++;
            }
        }

        $this->flash->success($count . ' requirement(s) updated');

        return $this->dispatcher->forward(['controller' => 'requirements', 'action' => 'index']);
    }

    /**
     * REQ-NNN: sprintf('REQ-%03d', $n) zero-pads up to 3 digits and
     * naturally stops padding past it (REQ-1000, REQ-1547...). Based on
     * the highest existing numeric suffix (including soft-deleted rows —
     * ids are never reused), not row count, so a deleted requirement
     * doesn't free up its number.
     */
    private function nextDisplayId(): string
    {
        $highest = 0;

        foreach (\Requirement::findWithTrashed(['columns' => 'display_id']) as $row) {
            if (preg_match('/^REQ-(\d+)$/', (string) $row->display_id, $matches)) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return sprintf('REQ-%03d', $highest + 1);
    }
}
