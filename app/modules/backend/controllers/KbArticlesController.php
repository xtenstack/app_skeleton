<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

/**
 * Human-facing KB article authoring (Knowledge-Base-Module-Plan.md v0.1
 * sections 5-7). admin/operator only, same roles/reasoning as
 * TicketsController — authoring and publishing stays human, agents get
 * read-only API access (see App_skeleton\Modules\Api\Controllers\
 * KbArticlesController). List/create/edit/view/delete follow the
 * TicketsController pattern; publishAction() is the one KB-specific
 * side-effecting transition (sets status + published_at, same shape as
 * TicketTriageActions::closeAction() setting status + closed_at).
 */
class KbArticlesController extends ControllerBase
{
    protected ?array $allowedRoles = null; // resolved at runtime, see onConstruct()

    protected function onConstruct()
    {
        $this->allowedRoles = \Roles::idsByNames(['admin', 'operator']);

        parent::onConstruct();
    }

    public function indexAction(): void
    {
        $conditions = [];
        $bind       = [];

        $status = (string) $this->request->getQuery('status', 'string', '');

        if ($status !== '') {
            $conditions[]   = 'status = :status:';
            $bind['status'] = $status;
        }

        $enquiryTypeId = $this->request->getQuery('enquiry_type_id', 'int');

        if ($enquiryTypeId) {
            $conditions[]           = 'enquiry_type_id = :enquiry_type_id:';
            $bind['enquiry_type_id'] = (int) $enquiryTypeId;
        }

        // Search/sort/pagination (list-view convention, RB-03).
        $list = \App_skeleton\ListView::paginate(
            $this->request,
            \KbArticles::class,
            ['title', 'summary', 'body'],
            ['created' => 'id', 'title' => 'title', 'status' => 'status'],
            $conditions,
            $bind
        );

        $this->view->articles      = $list['results'];
        $this->view->currentStatus = $status;
        $this->view->enquiryTypeId = $enquiryTypeId;
        $this->view->listState     = $list;
        $this->view->preserveQuery = array_merge($list['preserve'], array_filter([
            'status'          => $status !== '' ? $status : null,
            'enquiry_type_id' => $enquiryTypeId ?: null,
        ], fn ($v) => $v !== null));

        $this->view->enquiryTypes = \KbEnquiryTypes::find(['order' => 'name']);
    }

    public function newAction(): void
    {
        $this->view->article      = new \KbArticles();
        $this->view->enquiryTypes = \KbEnquiryTypes::find(['order' => 'name']);
    }

    public function createAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'index']);
        }

        $title = trim((string) $this->request->getPost('title'));

        if ($title === '') {
            $this->flash->error('Title is required');

            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'new']);
        }

        $article                     = new \KbArticles();
        $article->title              = $title;
        $article->body               = (string) $this->request->getPost('body');
        $article->summary            = (string) $this->request->getPost('summary') ?: null;
        $article->enquiry_type_id    = $this->request->getPost('enquiry_type_id', 'int') ?: null;
        $article->product_ref        = (string) $this->request->getPost('product_ref') ?: null;
        $article->visibility         = $this->normalizeVisibility((string) $this->request->getPost('visibility'));
        $article->status             = 'draft';
        $article->created_by_user_id = $this->session->get('auth')['id'];
        $article->updated_by_user_id = $this->session->get('auth')['id'];

        if (!$article->save()) {
            foreach ($article->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            $this->view->article      = $article;
            $this->view->enquiryTypes = \KbEnquiryTypes::find(['order' => 'name']);

            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'new']);
        }

        $this->flash->success('Article created');

        return $this->response->redirect($this->url->get('backend/kb-articles/view/' . $article->id));
    }

    public function viewAction($id)
    {
        $article = \KbArticles::findFirstById($id);

        if (!$article) {
            $this->flash->error('Article was not found');

            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'index']);
        }

        $this->view->article  = $article;
        $this->view->bodyHtml = \App_skeleton\Markdown::toHtml((string) $article->body);
    }

    public function editAction($id)
    {
        $article = \KbArticles::findFirstById($id);

        if (!$article) {
            $this->flash->error('Article was not found');

            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'index']);
        }

        $this->view->article      = $article;
        $this->view->enquiryTypes = \KbEnquiryTypes::find(['order' => 'name']);
    }

    public function updateAction($id)
    {
        $article = \KbArticles::findFirstById($id);

        if (!$article) {
            $this->flash->error('Article was not found');

            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'view', 'params' => [$id]]);
        }

        $title = trim((string) $this->request->getPost('title'));

        if ($title === '') {
            $this->flash->error('Title is required');
            $this->view->article      = $article;
            $this->view->enquiryTypes = \KbEnquiryTypes::find(['order' => 'name']);

            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'edit', 'params' => [$id]]);
        }

        $article->title              = $title;
        $article->body               = (string) $this->request->getPost('body');
        $article->summary            = (string) $this->request->getPost('summary') ?: null;
        $article->enquiry_type_id    = $this->request->getPost('enquiry_type_id', 'int') ?: $article->enquiry_type_id;
        $article->product_ref        = (string) $this->request->getPost('product_ref') ?: null;
        $article->visibility         = $this->normalizeVisibility((string) $this->request->getPost('visibility'));
        $article->updated_by_user_id = $this->session->get('auth')['id'];

        if (!$article->save()) {
            foreach ($article->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            $this->view->article      = $article;
            $this->view->enquiryTypes = \KbEnquiryTypes::find(['order' => 'name']);

            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'edit', 'params' => [$id]]);
        }

        $this->flash->success('Article updated');

        return $this->response->redirect($this->url->get('backend/kb-articles/view/' . $article->id));
    }

    public function deleteAction($id)
    {
        $article = \KbArticles::findFirstById($id);

        if (!$article) {
            $this->flash->error('Article was not found');

            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'view', 'params' => [$id]]);
        }

        if (!$article->softDelete()) {
            foreach ($article->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'view', 'params' => [$id]]);
        }

        $this->flash->success('Article deleted');

        return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'index']);
    }

    /**
     * The one KB-specific side-effecting transition — status + published_at,
     * same shape as TicketTriageActions::closeAction() setting status +
     * closed_at. Human-only (this whole controller is admin/operator, per
     * this class's onConstruct()) — agents never reach this even via the
     * backend, matching plan section 5/7.
     */
    public function publishAction($id)
    {
        $article = \KbArticles::findFirstById($id);

        if (!$article) {
            $this->flash->error('Article was not found');

            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'view', 'params' => [$id]]);
        }

        $article->status             = 'published';
        $article->published_at       = date('Y-m-d H:i:s');
        $article->updated_by_user_id = $this->session->get('auth')['id'];

        if (!$article->save()) {
            foreach ($article->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('Article published');
        }

        return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'view', 'params' => [$id]]);
    }

    public function unpublishAction($id)
    {
        $article = \KbArticles::findFirstById($id);

        if (!$article) {
            $this->flash->error('Article was not found');

            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'view', 'params' => [$id]]);
        }

        $article->status             = 'draft';
        $article->published_at       = null;
        $article->updated_by_user_id = $this->session->get('auth')['id'];

        if (!$article->save()) {
            foreach ($article->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('Article moved back to draft');
        }

        return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'view', 'params' => [$id]]);
    }

    /**
     * "With selected" bulk operations (list-view convention, RB-03).
     * Bulk status is limited to publish/unpublish — the same two
     * side-effecting transitions publishAction()/unpublishAction()
     * already define — rather than a raw status dropdown, matching
     * TicketsController::bulkAction()'s own reasoning.
     */
    public function bulkAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'index']);
        }

        $ids = array_filter(array_map('intval', (array) $this->request->getPost('kb_article_ids', null, [])));

        if (!$ids) {
            $this->flash->error('No articles were selected');

            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'index']);
        }

        $articles = \KbArticles::find([
            'conditions' => 'id IN ({ids:array})',
            'bind'       => ['ids' => $ids],
        ]);

        $bulkAction = (string) $this->request->getPost('bulk_action');

        if ($bulkAction === 'delete') {
            $count = 0;

            foreach ($articles as $article) {
                if ($article->softDelete()) {
                    $count++;
                }
            }

            $this->flash->success($count . ' article(s) deleted');

            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'index']);
        }

        $visibility = (string) $this->request->getPost('visibility');
        $status     = (string) $this->request->getPost('status');

        if ($visibility === '' && $status === '') {
            $this->flash->error('Choose at least one field to bulk-update');

            return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'index']);
        }

        $count       = 0;
        $userId      = $this->session->get('auth')['id'];

        foreach ($articles as $article) {
            if ($visibility !== '' && isset(\KbArticles::VISIBILITIES[$visibility])) {
                $article->visibility = $visibility;
            }

            if ($status === 'published') {
                $article->status       = 'published';
                $article->published_at = date('Y-m-d H:i:s');
            } elseif ($status === 'draft') {
                $article->status       = 'draft';
                $article->published_at = null;
            }

            $article->updated_by_user_id = $userId;

            if ($article->save()) {
                $count++;
            }
        }

        $this->flash->success($count . ' article(s) updated');

        return $this->dispatcher->forward(['controller' => 'kb-articles', 'action' => 'index']);
    }

    private function normalizeVisibility(string $value): string
    {
        return isset(\KbArticles::VISIBILITIES[$value]) ? $value : 'internal';
    }
}
