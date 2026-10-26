<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

/**
 * Staff replies and internal notes on a ticket (GX-SPEC-05). A trait, not
 * a separate controller, for the same reason as TicketTriageActions: these
 * stay plain tickets/reply and tickets/note routes on TicketsController.
 * Staff-only by construction — neither action is in
 * TicketsController::CHARTER_AGENT_ACTIONS, so onConstruct() already
 * limits both to admin/operator. The public/internal choice is made by the
 * route, never by a form field, so a note cannot be turned into a reply
 * by editing a request.
 */
trait TicketMessageActions
{
    public function replyAction($id)
    {
        return $this->saveTicketMessage($id, \TicketMessages::VISIBILITY_PUBLIC, 'Reply sent to the client');
    }

    public function noteAction($id)
    {
        return $this->saveTicketMessage($id, \TicketMessages::VISIBILITY_INTERNAL, 'Internal note saved');
    }

    private function saveTicketMessage($id, string $visibility, string $success)
    {
        $ticket = \Tickets::findFirstById($id);

        if (!$ticket) {
            $this->flash->error('Ticket was not found');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'index']);
        }

        if (!$this->request->isPost()) {
            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $body = trim((string) $this->request->getPost('body', 'string'));

        if ($body === '' || mb_strlen($body) > \TicketMessages::MAX_BODY_LENGTH) {
            $this->flash->error('Write a message of up to ' . \TicketMessages::MAX_BODY_LENGTH . ' characters');

            return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
        }

        $message                 = new \TicketMessages();
        $message->ticket_id      = (int) $ticket->id;
        $message->author_user_id = (int) ($this->session->get('auth')['id'] ?? 0) ?: null;
        $message->visibility     = $visibility;
        $message->body           = $body;

        if (!$message->save()) {
            foreach ($message->getMessages() as $error) {
                $this->flash->error((string) $error);
            }
        } else {
            $ticket->save();
            $this->flash->success($success);
        }

        return $this->dispatcher->forward(['controller' => 'tickets', 'action' => 'view', 'params' => [$id]]);
    }
}
