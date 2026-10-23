<?php

/**
 * One row per role a user holds beyond, or including, users.role_id (the
 * primary role). Users::roleIds() is the effective set — the union of the
 * two — and the only thing gates should read. Snapshots on, so the audit
 * listener records every grant and revoke.
 */
class UserRoles extends Phalcon\Mvc\Model
{
    /**
     *
     * @var integer
     */
    public $user_id;

    /**
     *
     * @var integer
     */
    public $role_id;

    /**
     *
     * @var string
     */
    public $created_at;

    /**
     * Initialize method for model.
     */
    public function initialize(): void
    {
        $this->setSource('user_roles');
        $this->keepSnapshots(true);
        $this->belongsTo('user_id', 'Users', 'id', ['alias' => 'User']);
        $this->belongsTo('role_id', 'Roles', 'id', ['alias' => 'Role']);
    }

    /**
     * Role ids held in user_roles alone (not the primary — see
     * Users::roleIds() for the effective set).
     *
     * @return int[]
     */
    public static function roleIdsForUser(int $userId): array
    {
        $ids = [];

        foreach (self::find(['conditions' => 'user_id = :user_id:', 'bind' => ['user_id' => $userId]]) as $row) {
            $ids[] = (int) $row->role_id;
        }

        return $ids;
    }

    /**
     * Ids of the users holding $roleId through user_roles (the primary
     * holders are users.role_id = $roleId; Roles::holderCount() adds both).
     *
     * @return int[]
     */
    public static function userIdsForRole(int $roleId): array
    {
        $ids = [];

        foreach (self::find(['conditions' => 'role_id = :role_id:', 'bind' => ['role_id' => $roleId]]) as $row) {
            $ids[] = (int) $row->user_id;
        }

        return $ids;
    }

    /**
     * Makes user_roles for $userId exactly $roleIds: rows for roles no
     * longer held are deleted, missing ones inserted, the rest untouched
     * (so created_at keeps meaning "since when"). Returns false, with
     * nothing changed, if any write fails.
     *
     * @param int[] $roleIds
     */
    public static function syncForUser(int $userId, array $roleIds): bool
    {
        $wanted  = array_values(array_unique(array_map('intval', $roleIds)));
        $current = self::roleIdsForUser($userId);

        $db = \Phalcon\Di\Di::getDefault()->getShared('db');
        $db->begin();

        foreach (self::find(['conditions' => 'user_id = :user_id:', 'bind' => ['user_id' => $userId]]) as $row) {
            if (!in_array((int) $row->role_id, $wanted, true) && !$row->delete()) {
                $db->rollback();

                return false;
            }
        }

        foreach (array_diff($wanted, $current) as $roleId) {
            $row          = new self();
            $row->user_id = $userId;
            $row->role_id = $roleId;

            if (!$row->save()) {
                $db->rollback();

                return false;
            }
        }

        $db->commit();

        return true;
    }
}
