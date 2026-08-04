<?php

use App_skeleton\Models\SoftDeletes;

/**
 * One REQ-NNN entry (see requirements-module's plan doc, design decision
 * 2/3). display_id is generated once at create time (sprintf('REQ-%03d',
 * $n), see RequirementsController::nextDisplayId()) and never recomputed
 * — historical ids must never shift even if generation logic changes.
 */
class Requirement extends \Phalcon\Mvc\Model
{
    use SoftDeletes;

    public $id;
    public $display_id;
    public $title;
    public $description;
    public $status;
    public $changelog_decision;
    public $changelog_id;
    public $changelog_note;
    public $origin_session;
    public $deleted_at;
    public $created_at;
    public $updated_at;

    public function initialize()
    {
        $this->setSource('requirements');
        $this->keepSnapshots(true);
        $this->belongsTo('changelog_id', 'Changelog', 'id', ['alias' => 'Changelog']);
    }

    public function beforeSave()
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }
}
