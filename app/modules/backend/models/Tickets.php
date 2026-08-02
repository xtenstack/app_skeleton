<?php
declare(strict_types=1);

use App_skeleton\Models\SoftDeletes;

/**
 * An escalation/report raised by an agent, staff member, or customer
 * (reporter_user_id — all three are just `users` rows, see
 * docs/ticketing-module-plan.md) for a human operator to triage.
 * assigned_to_user_id is always meant to be a human — agents are never a
 * valid assignee, enforced in the backend controller, not here.
 */
class Tickets extends \Phalcon\Mvc\Model
{
    use SoftDeletes;

    public $id;
    public $title;
    public $description;
    public $severity;
    public $status;
    public $ticket_type;
    public $notes;
    public $source_ref;
    public $reporter_user_id;
    public $reporter_api_key_id;
    public $assigned_to_user_id;
    public $consolidated_into_ticket_id;
    public $retest_ref;
    public $retest_agent_key;
    public $last_retest_result;
    public $last_retest_at;
    public $last_retest_notes;
    public $closed_at;
    public $close_reason;
    public $auto_closed_at;
    public $qa_reviewed_at;
    public $qa_reviewed_by;
    public $qa_outcome;
    public $deleted_at;
    public $created_at;
    public $updated_at;

    public function initialize()
    {
        $this->setSource('tickets');
        $this->keepSnapshots(true);
        $this->belongsTo('reporter_user_id', 'Users', 'id', ['alias' => 'Reporter']);
        $this->belongsTo('assigned_to_user_id', 'Users', 'id', ['alias' => 'Assignee']);
        $this->belongsTo('qa_reviewed_by', 'Users', 'id', ['alias' => 'QaReviewer']);
        $this->belongsTo('reporter_api_key_id', 'ApiKeys', 'id', ['alias' => 'ReporterApiKey']);
        $this->belongsTo('consolidated_into_ticket_id', 'Tickets', 'id', ['alias' => 'ConsolidatedInto']);
        $this->hasMany('id', 'TicketAttachments', 'ticket_id', ['alias' => 'Attachments']);
    }

    public function beforeSave()
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }
}
