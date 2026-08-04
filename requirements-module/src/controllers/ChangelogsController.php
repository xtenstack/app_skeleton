<?php
declare(strict_types=1);

namespace XtenRequirements\Controllers;

/**
 * One release's changelog, rendered from whichever `requirements` rows
 * are linked to it — no separate free-text changelog editor, see the
 * plan doc's design decision 4. Only requirements already at
 * 'done_pending_changelog_decision' are pickable, and linking one here
 * is what actually resolves that pending decision to 'included'.
 */
class ChangelogsController extends ControllerBase
{
    protected function onConstruct()
    {
        $this->allowedRoles = \Roles::idsByNames(['admin', 'operator']);

        parent::onConstruct();
    }

    public function indexAction()
    {
        $this->view->changelogs = \Changelog::find(['order' => 'id DESC']);
    }

    public function newAction()
    {
        $this->view->changelog = new \Changelog();
    }

    public function createAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'changelogs', 'action' => 'index']);
        }

        $version = trim((string) $this->request->getPost('version', 'string'));

        if ($version === '') {
            $this->flash->error('Version is required');

            return $this->dispatcher->forward(['controller' => 'changelogs', 'action' => 'new']);
        }

        $releasedAt = trim((string) $this->request->getPost('released_at', 'string'));

        $changelog             = new \Changelog();
        $changelog->version    = $version;
        $changelog->released_at = $releasedAt !== '' ? $releasedAt : null;

        if (!$changelog->save()) {
            foreach ($changelog->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            $this->view->changelog = $changelog;

            return $this->dispatcher->forward(['controller' => 'changelogs', 'action' => 'new']);
        }

        $this->flash->success('Changelog ' . $changelog->version . ' created');

        return $this->response->redirect($this->url->get('requirements/changelogs/view/' . $changelog->id));
    }

    public function viewAction($id)
    {
        $changelog = \Changelog::findFirstById($id);

        if (!$changelog) {
            $this->flash->error('Changelog was not found');

            return $this->dispatcher->forward(['controller' => 'changelogs', 'action' => 'index']);
        }

        $this->view->changelog       = $changelog;
        $this->view->linked          = $changelog->getRequirements(['order' => 'id']);
        $this->view->pickable        = \Requirement::find([
            'conditions' => "status = 'done_pending_changelog_decision'",
            'order'      => 'id',
        ]);
        $this->view->markdownPreview = $this->renderMarkdown($changelog);
    }

    /**
     * Links selected pending requirements into this changelog — the
     * human review-and-decide step from the plan's Context section.
     * Resolves each one's changelog_decision to 'included'.
     */
    public function addRequirementsAction($id)
    {
        $changelog = \Changelog::findFirstById($id);

        if (!$changelog) {
            $this->flash->error('Changelog was not found');

            return $this->dispatcher->forward(['controller' => 'changelogs', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'changelogs', 'action' => 'view', 'params' => [$id]]);
        }

        $ids = array_filter(array_map('intval', (array) $this->request->getPost('requirement_ids', null, [])));

        if (!$ids) {
            $this->flash->error('No requirements were selected');

            return $this->dispatcher->forward(['controller' => 'changelogs', 'action' => 'view', 'params' => [$id]]);
        }

        $requirements = \Requirement::find([
            'conditions' => 'id IN ({ids:array}) AND status = :status:',
            'bind'       => ['ids' => $ids, 'status' => 'done_pending_changelog_decision'],
        ]);

        $count = 0;

        foreach ($requirements as $requirement) {
            $requirement->changelog_id       = $changelog->id;
            $requirement->changelog_decision = 'included';
            $requirement->status             = 'complete';

            if ($requirement->save()) {
                $count++;
            }
        }

        $this->flash->success($count . ' requirement(s) added to ' . $changelog->version);

        return $this->dispatcher->forward(['controller' => 'changelogs', 'action' => 'view', 'params' => [$id]]);
    }

    /**
     * Unlinks a wrongly-added requirement — puts it back to
     * 'done_pending_changelog_decision' rather than leaving it stuck as
     * 'complete'/'included' with no changelog to point to.
     */
    public function removeRequirementAction($id, $requirementId)
    {
        $changelog = \Changelog::findFirstById($id);

        if (!$changelog) {
            $this->flash->error('Changelog was not found');

            return $this->dispatcher->forward(['controller' => 'changelogs', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'changelogs', 'action' => 'view', 'params' => [$id]]);
        }

        $requirement = \Requirement::findFirst([
            'conditions' => 'id = :requirement_id: AND changelog_id = :changelog_id:',
            'bind'       => ['requirement_id' => $requirementId, 'changelog_id' => $changelog->id],
        ]);

        if (!$requirement) {
            $this->flash->error('Requirement was not found on this changelog');

            return $this->dispatcher->forward(['controller' => 'changelogs', 'action' => 'view', 'params' => [$id]]);
        }

        $requirement->changelog_id       = null;
        $requirement->changelog_decision = null;
        $requirement->status             = 'done_pending_changelog_decision';

        if (!$requirement->save()) {
            foreach ($requirement->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success($requirement->display_id . ' removed from ' . $changelog->version);
        }

        return $this->dispatcher->forward(['controller' => 'changelogs', 'action' => 'view', 'params' => [$id]]);
    }

    public function exportAction($id)
    {
        $changelog = \Changelog::findFirstById($id);

        if (!$changelog) {
            $this->flash->error('Changelog was not found');

            return $this->dispatcher->forward(['controller' => 'changelogs', 'action' => 'index']);
        }

        $this->view->disable();

        $markdown = $this->renderMarkdown($changelog);
        $filename = 'CHANGELOG-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $changelog->version) . '.md';

        $this->response->setContentType('text/markdown', 'UTF-8');
        $this->response->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $this->response->setContent($markdown);

        return $this->response;
    }

    /**
     * Flat list, one line per linked requirement (changelog_note if set,
     * else title) — matches this project's own CHANGELOG.md conventions
     * closely enough not to need a feature/fix/breaking taxonomy in v1.
     */
    private function renderMarkdown(\Changelog $changelog): string
    {
        $heading = '## [' . $changelog->version . '] - ' . ($changelog->released_at ?: 'Unreleased');
        $lines   = [$heading, ''];

        foreach ($changelog->getRequirements(['order' => 'id']) as $requirement) {
            $lines[] = '- ' . ($requirement->changelog_note ?: $requirement->title);
        }

        if (count($lines) === 2) {
            $lines[] = '- (no requirements linked yet)';
        }

        return implode("\n", $lines) . "\n";
    }
}
