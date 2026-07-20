<?php
declare(strict_types=1);

namespace App_skeleton\Models;

/**
 * Adds a deleted_at column convention to a model: find()/findFirst() exclude
 * soft-deleted rows by default, softDelete() sets the timestamp instead of
 * removing the row, and the *WithTrashed() variants are the explicit escape
 * hatch for admin views/audit that need to see everything.
 */
trait SoftDeletes
{
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find(self::excludeTrashed($parameters));
    }

    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst(self::excludeTrashed($parameters));
    }

    public static function findWithTrashed($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    public static function findFirstWithTrashed($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

    public function softDelete(): bool
    {
        $this->deleted_at = date('Y-m-d H:i:s');

        return $this->save();
    }

    public function restore(): bool
    {
        $this->deleted_at = null;

        return $this->save();
    }

    private static function excludeTrashed($parameters): array
    {
        if ($parameters === null) {
            $parameters = [];
        } elseif (is_int($parameters)) {
            $parameters = ['conditions' => 'id = :__id:', 'bind' => ['__id' => $parameters]];
        } elseif (is_string($parameters)) {
            $parameters = ['conditions' => $parameters];
        }

        $parameters['conditions'] = empty($parameters['conditions'])
            ? 'deleted_at IS NULL'
            : '(' . $parameters['conditions'] . ') AND deleted_at IS NULL';

        return $parameters;
    }
}
