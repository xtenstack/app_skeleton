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

    /**
     * Tables a reversal is allowed to write back into. Deliberately a
     * whitelist (not derived from entity_type at call time) even though
     * entity_type always comes from our own models, not user input.
     */
    private const REVERSIBLE_TABLES = [
        'users', 'items', 'roles', 'settings', 'api_keys', 'user_profiles', 'user_settings', 'tickets',
    ];

    /**
     * True if this entry can still be reversed: it's a real data-change
     * entry (not an auth event or an existing reversal) with a known table
     * and something to restore, and nothing has reversed it yet.
     */
    public function isReversible(\AuditLog $entry): bool
    {
        if ($entry->action === 'reversal' || !in_array($entry->entity_type, self::REVERSIBLE_TABLES, true)) {
            return false;
        }

        if ($entry->action === 'update' || $entry->action === 'delete') {
            if (!$entry->old_values) {
                return false;
            }
        } elseif ($entry->action !== 'insert') {
            return false;
        }

        $alreadyReversed = \AuditLog::findFirst([
            'conditions' => 'reversed_audit_log_id = :id:',
            'bind'       => ['id' => $entry->id],
        ]);

        return $alreadyReversed === null;
    }

    /**
     * Restores the entity a given audit_log entry describes, then writes a
     * new 'reversal' row pointing back at it. The original row is never
     * touched. Restoring is done with direct SQL (not the model layer) so
     * it bypasses normal validation/business logic — this is an admin
     * override, not a regular save.
     */
    public function reverse(\AuditLog $entry): bool
    {
        if (!$this->isReversible($entry)) {
            return false;
        }

        $table   = $entry->entity_type;
        $old     = $entry->old_values ? json_decode($entry->old_values, true) : null;
        $restored = null;

        switch ($entry->action) {
            case 'update':
                $this->applyUpdate($table, (int) $entry->entity_id, $old);
                $restored = $old;
                break;

            case 'delete':
                $this->applyInsert($table, $old);
                $restored = $old;
                break;

            case 'insert':
                $this->applyDelete($table, (int) $entry->entity_id);
                $restored = ['id' => $entry->entity_id, '_removed' => true];
                break;
        }

        $auth = $this->session->get('auth');

        $log                        = new \AuditLog();
        $log->entity_type           = $table;
        $log->entity_id             = $entry->entity_id;
        $log->action                = 'reversal';
        $log->actor_user_id         = $auth['id'] ?? null;
        $log->new_values            = json_encode($restored);
        $log->reversed_audit_log_id = $entry->id;
        $log->save();

        return true;
    }

    private function applyUpdate(string $table, int $id, array $values): void
    {
        $set  = implode(', ', array_map(fn ($col) => "{$col} = :{$col}", array_keys($values)));
        $bind = $values + ['__id' => $id];

        $this->db->execute("UPDATE {$table} SET {$set} WHERE id = :__id", $bind);
    }

    private function applyInsert(string $table, array $values): void
    {
        $columns = array_keys($values);
        $placeholders = array_map(fn ($col) => ":{$col}", $columns);

        $this->db->execute(
            "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')',
            $values
        );
    }

    private function applyDelete(string $table, int $id): void
    {
        $this->db->execute("DELETE FROM {$table} WHERE id = :id", ['id' => $id]);
    }
}
