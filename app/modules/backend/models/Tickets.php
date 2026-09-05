<?php
declare(strict_types=1);

use App_skeleton\Models\SoftDeletes;

/**
 * An escalation/report raised by an agent, staff member, or customer
 * (reporter_user_id — all three are just `users` rows, see
 * docs/ticketing-module-plan.md) for a human operator to triage.
 * assigned_to_user_id is meant to be a human for reassignment purposes —
 * TicketTriageActions::assignAction() (backend, human-driven) still
 * rejects an agent as a *reassignment* target. The one deliberate
 * exception (2026-09-05, Tim/SSA integration) is self-assignment via
 * App_skeleton\Modules\Api\Controllers\TicketsController::selfAssignAction(),
 * which lets an agent claim a currently-unassigned ticket for itself —
 * see that method's docblock. So: agents can end up as
 * assigned_to_user_id via self-claim, but never via being assigned by
 * someone else.
 *
 * @method static Tickets|null findFirstById(int $id)
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
    public $project;
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

    public function initialize(): void
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

    public function beforeSave(): void
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }

    /**
     * REQ-195: the app's own severity scale (low/normal/high/critical)
     * predates RB-18/the SLA docs' S1-S4 scale and the two never got
     * reconciled — S1 is the most severe (outage/security), S4 the
     * least (a question), the reverse order of this enum's own name
     * ordering. Paired here rather than replacing the stored enum, so
     * every existing sort/filter against the low/normal/high/critical
     * column keeps working unchanged.
     */
    public const SEVERITIES = [
        'low'      => ['label' => 'Low',      'sla_code' => 'S4'],
        'normal'   => ['label' => 'Normal',   'sla_code' => 'S3'],
        'high'     => ['label' => 'High',     'sla_code' => 'S2'],
        'critical' => ['label' => 'Critical', 'sla_code' => 'S1'],
    ];

    /**
     * Accepts either a canonical value (any case) or its SLA code (S1-S4,
     * any case) and returns the canonical value, or null if neither
     * matches — callers decide their own fallback (existing call sites
     * mostly default to 'normal' on no match, matching prior behavior).
     */
    public static function normalizeSeverity(string $value): ?string
    {
        $value = strtolower(trim($value));

        if (isset(self::SEVERITIES[$value])) {
            return $value;
        }

        foreach (self::SEVERITIES as $canonical => $meta) {
            if (strtolower($meta['sla_code']) === $value) {
                return $canonical;
            }
        }

        return null;
    }

    /** "Low / S4" — the paired label used by every severity <select>. */
    public static function severityOptions(): array
    {
        $options = [];

        foreach (self::SEVERITIES as $value => $meta) {
            $options[$value] = "{$meta['label']} / {$meta['sla_code']}";
        }

        return $options;
    }
}
