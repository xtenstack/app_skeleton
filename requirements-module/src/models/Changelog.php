<?php

use App_skeleton\Models\SoftDeletes;

/**
 * One release's changelog. Rendered/exported as Markdown from whichever
 * `requirements` rows are linked to it (Requirement::changelog_id) — see
 * ChangelogsController::renderMarkdown(). No free-text body of its own.
 */
class Changelog extends \Phalcon\Mvc\Model
{
    use SoftDeletes;

    public $id;
    public $version;
    public $released_at;
    public $deleted_at;
    public $created_at;
    public $updated_at;

    public function initialize()
    {
        $this->setSource('changelogs');
        $this->keepSnapshots(true);
        $this->hasMany('id', 'Requirement', 'changelog_id', ['alias' => 'Requirements']);
    }

    public function beforeSave()
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }
}
