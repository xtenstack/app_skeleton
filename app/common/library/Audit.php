<?php
declare(strict_types=1);

namespace App_skeleton;

use Phalcon\Di\Injectable;
use Phalcon\Events\Event;
use Phalcon\Mvc\ModelInterface;

/**
 * Attached as the default models events manager listener (see services.php)
 * so every model that opts into keepSnapshots(true) gets audited without
 * each controller having to remember to call anything.
 */
class Audit extends Injectable
{
    public function afterCreate(Event $event, ModelInterface $model): void
    {
        $this->record($model, 'insert', null, $model->toArray());
    }

    public function beforeUpdate(Event $event, ModelInterface $model): void
    {
        if (!method_exists($model, 'getChangedFields')) {
            return;
        }

        $changed = $model->getChangedFields();

        if (empty($changed)) {
            return;
        }

        $old = array_intersect_key($model->getSnapshotData(), array_flip($changed));
        $new = array_intersect_key($model->toArray(), array_flip($changed));

        $this->record($model, 'update', $old, $new);
    }

    public function beforeDelete(Event $event, ModelInterface $model): void
    {
        $this->record($model, 'delete', $model->toArray(), null);
    }

    private function record(ModelInterface $model, string $action, ?array $old, ?array $new): void
    {
        if ($model instanceof \AuditLog) {
            return;
        }

        $auth = $this->session->get('auth');

        $log                = new \AuditLog();
        $log->entity_type   = $model->getSource();
        $log->entity_id     = (int) $model->readAttribute('id');
        $log->action        = $action;
        $log->actor_user_id = $auth['id'] ?? null;
        $log->old_values    = $old !== null ? json_encode($old) : null;
        $log->new_values    = $new !== null ? json_encode($new) : null;
        $log->save();
    }

    public static function recordEvent(string $action, ?int $actorUserId, array $meta = []): void
    {
        $log                = new \AuditLog();
        $log->entity_type   = 'auth';
        $log->entity_id     = $actorUserId;
        $log->action        = $action;
        $log->actor_user_id = $actorUserId;
        $log->new_values    = $meta ? json_encode($meta) : null;
        $log->save();
    }
}
