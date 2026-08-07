<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

class ItemsController extends ControllerBase
{
    public function indexAction()
    {
        $this->view->items = \Items::find(['order' => 'id DESC']);
    }

    public function newAction()
    {
        $this->view->item  = new \Items();
        $this->view->users = \Users::find(['order' => 'email']);
    }

    public function editAction($id)
    {
        $item = \Items::findFirstById($id);

        if (!$item) {
            $this->flash->error('Item was not found');

            return $this->dispatcher->forward([
                'controller' => 'items',
                'action'     => 'index',
            ]);
        }

        $this->view->item  = $item;
        $this->view->users = \Users::find(['order' => 'email']);
    }

    public function createAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward([
                'controller' => 'items',
                'action'     => 'index',
            ]);
        }

        $item = new \Items();
        $item->user_id     = (int) $this->request->getPost('user_id', 'int');
        $item->title       = (string) $this->request->getPost('title');
        $item->description = (string) $this->request->getPost('description');
        $item->status      = (string) $this->request->getPost('status');

        if (!$item->save()) {
            foreach ($item->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward([
                'controller' => 'items',
                'action'     => 'new',
            ]);
        }

        $this->flash->success('Item was created successfully');

        return $this->dispatcher->forward([
            'controller' => 'items',
            'action'     => 'index',
        ]);
    }

    public function saveAction()
    {
        if (!$this->request->isPost()) {
            return $this->dispatcher->forward([
                'controller' => 'items',
                'action'     => 'index',
            ]);
        }

        $id   = $this->request->getPost('id', 'int');
        $item = \Items::findFirstById($id);

        if (!$item) {
            $this->flash->error('Item does not exist ' . $id);

            return $this->dispatcher->forward([
                'controller' => 'items',
                'action'     => 'index',
            ]);
        }

        $item->user_id     = (int) $this->request->getPost('user_id', 'int');
        $item->title       = (string) $this->request->getPost('title');
        $item->description = (string) $this->request->getPost('description');
        $item->status      = (string) $this->request->getPost('status');

        if (!$item->save()) {
            foreach ($item->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->dispatcher->forward([
                'controller' => 'items',
                'action'     => 'edit',
                'params'     => [$item->id],
            ]);
        }

        $this->flash->success('Item was updated successfully');

        return $this->dispatcher->forward([
            'controller' => 'items',
            'action'     => 'index',
        ]);
    }

    public function deleteAction($id)
    {
        $item = \Items::findFirstById($id);

        if (!$item) {
            $this->flash->error('Item was not found');

            return $this->dispatcher->forward([
                'controller' => 'items',
                'action'     => 'index',
            ]);
        }

        if (!$item->softDelete()) {
            foreach ($item->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('Item was deleted successfully');
        }

        return $this->dispatcher->forward([
            'controller' => 'items',
            'action'     => 'index',
        ]);
    }
}
