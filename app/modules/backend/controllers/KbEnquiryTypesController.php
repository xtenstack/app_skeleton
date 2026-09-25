<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

/**
 * Simple CRUD over the shared enquiry-type taxonomy (Knowledge-Base-
 * Module-Plan.md v0.1 section 4) — admin only, same as RolesController,
 * since this is a shared reference table both KbArticles and Tickets
 * depend on (migration 022) rather than a per-article concern. Delete
 * goes through SoftDeletes, unlike RolesController's hard delete, per
 * CLAUDE.md's forbidden-patterns rule and because kb_articles/tickets
 * rows reference a type by id and shouldn't suddenly dangle — and for
 * the same reason a type that is still referenced by any article or
 * ticket can't be deleted at all (single or bulk): the API resolves
 * enquiry_type by name through the trait-filtered findFirst(), so a
 * soft-deleted type would make every article under it silently vanish
 * from index/match rather than error. Remove or retype the referencing
 * rows first.
 */
class KbEnquiryTypesController extends ControllerBase
{
    protected ?array $allowedRoles = [1];

    public function indexAction(): void
    {
        $list = \App_skeleton\ListView::paginate(
            $this->request,
            \KbEnquiryTypes::class,
            ['name', 'description'],
            ['name' => 'name', 'created' => 'id'],
            [],
            [],
            25,
            'asc'
        );

        $this->view->enquiryTypes  = $list['results'];
        $this->view->listState     = $list;
        $this->view->preserveQuery = $list['preserve'];
    }

    public function newAction(): void
    {
        $this->view->enquiryType = new \KbEnquiryTypes();
    }

    public function editAction($id)
    {
        $enquiryType = \KbEnquiryTypes::findFirstById($id);

        if (!$enquiryType) {
            $this->flash->error('Enquiry type was not found');

            return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'index']);
        }

        $this->view->enquiryType = $enquiryType;
    }

    public function createAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'index']);
        }

        $name = trim((string) $this->request->getPost('name'));

        if ($name === '') {
            $this->flash->error('Name is required');

            return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'new']);
        }

        $enquiryType              = new \KbEnquiryTypes();
        $enquiryType->name        = $name;
        $enquiryType->description = (string) $this->request->getPost('description') ?: null;

        if (!$enquiryType->save()) {
            foreach ($enquiryType->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'new']);
        }

        $this->flash->success('Enquiry type created');

        return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'index']);
    }

    public function saveAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'index']);
        }

        $id          = $this->request->getPost('id', 'int');
        $enquiryType = \KbEnquiryTypes::findFirstById($id);

        if (!$enquiryType) {
            $this->flash->error('Enquiry type was not found');

            return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'index']);
        }

        $name = trim((string) $this->request->getPost('name'));

        if ($name === '') {
            $this->flash->error('Name is required');
            $this->view->enquiryType = $enquiryType;

            return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'edit', 'params' => [$id]]);
        }

        $enquiryType->name        = $name;
        $enquiryType->description = (string) $this->request->getPost('description') ?: null;

        if (!$enquiryType->save()) {
            foreach ($enquiryType->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'edit', 'params' => [$id]]);
        }

        $this->flash->success('Enquiry type updated');

        return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'index']);
    }

    public function deleteAction($id)
    {
        $enquiryType = \KbEnquiryTypes::findFirstById($id);

        if (!$enquiryType) {
            $this->flash->error('Enquiry type was not found');

            return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'index']);
        }

        if ($enquiryType->isReferenced()) {
            $this->flash->error($this->inUseMessage($enquiryType));

            return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'index']);
        }

        if (!$enquiryType->softDelete()) {
            foreach ($enquiryType->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('Enquiry type deleted');
        }

        return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'index']);
    }

    /**
     * "With selected" bulk delete (list-view convention, RB-03) —
     * delete-only, same reasoning as RolesController::bulkAction(): the
     * only other field, description, isn't something that makes sense
     * applied identically across a batch. All-or-nothing: if any selected
     * type is still referenced, nothing is deleted and the error names
     * each one, rather than deleting the rest and leaving the admin to
     * work out which ones survived.
     */
    public function bulkAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'index']);
        }

        $ids = array_filter(array_map('intval', (array) $this->request->getPost('kb_enquiry_type_ids', null, [])));

        if (!$ids) {
            $this->flash->error('No enquiry types were selected');

            return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'index']);
        }

        $enquiryTypes = \KbEnquiryTypes::find([
            'conditions' => 'id IN ({ids:array})',
            'bind'       => ['ids' => $ids],
        ]);

        $inUse = [];

        foreach ($enquiryTypes as $enquiryType) {
            if ($enquiryType->isReferenced()) {
                $inUse[] = $this->inUseMessage($enquiryType);
            }
        }

        if ($inUse) {
            $this->flash->error('Nothing was deleted. ' . implode(' ', $inUse));

            return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'index']);
        }

        $count = 0;

        foreach ($enquiryTypes as $enquiryType) {
            if ($enquiryType->softDelete()) {
                $count++;
            }
        }

        $this->flash->success($count . ' enquiry type(s) deleted');

        return $this->dispatcher->forward(['controller' => 'kb-enquiry-types', 'action' => 'index']);
    }

    private function inUseMessage(\KbEnquiryTypes $enquiryType): string
    {
        $counts = $enquiryType->referenceCounts();

        return sprintf(
            '"%s" is still referenced by %d article(s) and %d ticket(s) and cannot be deleted — retype or delete those first.',
            $enquiryType->name,
            $counts['articles'],
            $counts['tickets']
        );
    }
}
