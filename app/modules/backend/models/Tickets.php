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
 * someone else. One more deliberate exception (Travis, 31 Oct 2026):
 * a creation-time DEFAULT for the game stream — see
 * beforeValidationOnCreate() / isGameStreamTicket(). That is the system
 * filling an empty assignee from a rule, not a person reassigning, so
 * assignAction()'s refusal is untouched.
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
    public $kb_enquiry_type_id;
    public $source_ref;
    public $intake_token;
    public $intake_data;
    public $intake_submitted_at;
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
        $this->belongsTo('kb_enquiry_type_id', 'KbEnquiryTypes', 'id', ['alias' => 'KbEnquiryType']);
        $this->hasMany('id', 'TicketAttachments', 'ticket_id', ['alias' => 'Attachments']);
        $this->hasMany('id', 'TicketMessages', 'ticket_id', ['alias' => 'Messages']);
    }

    public function beforeSave(): void
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }

    /**
     * Game-stream default assignee (Travis, 31 Oct 2026): tickets relayed
     * from, or raised about, XWar Legacy / xwar / game / games are the
     * Codex (GPT) user's work, so a new ticket with no assignee defaults
     * to that user instead of waiting for a hand assignment. The user is
     * looked up by email from the `tickets_game_stream_assignee` setting
     * (default below); an empty setting turns the rule off, a missing
     * user leaves the ticket unassigned. Reassignment stays human-only.
     */
    public const GAME_STREAM_ASSIGNEE_SETTING = 'tickets_game_stream_assignee';
    public const GAME_STREAM_ASSIGNEE_DEFAULT = 'openai.chatgpt@xten.au';

    /** First `:`-segment of source_ref (lower-cased) that marks a relayed game ticket. */
    public const GAME_STREAM_SOURCE_PREFIXES = ['xwar-legacy', 'xwar', 'game', 'games', 'play'];

    private const GAME_STREAM_TITLE_PATTERN = '/\b(xwar|x-war|game|games|solitaire|mahjong|sudoku|klondike)\b/i';

    /**
     * Pure rule: does this ticket belong to the game stream? Matches a
     * relayed source_ref by its prefix (e.g. `xwar-legacy:2:204:13`) or,
     * for hand-filed tickets, a game word in the title.
     */
    public static function isGameStreamTicket(?string $sourceRef, string $title): bool
    {
        $prefix = strtolower(trim((string) strtok((string) $sourceRef, ':')));

        if ($prefix !== '' && in_array($prefix, self::GAME_STREAM_SOURCE_PREFIXES, true)) {
            return true;
        }

        return (bool) preg_match(self::GAME_STREAM_TITLE_PATTERN, $title);
    }

    public function beforeValidationOnCreate(): void
    {
        if (!empty($this->assigned_to_user_id) || !self::isGameStreamTicket($this->source_ref, (string) $this->title)) {
            return;
        }

        $email = self::GAME_STREAM_ASSIGNEE_DEFAULT;
        $di    = $this->getDI();

        if ($di !== null && $di->has('settings')) {
            $email = trim((string) $di->getShared('settings')->get(self::GAME_STREAM_ASSIGNEE_SETTING, self::GAME_STREAM_ASSIGNEE_DEFAULT));
        }

        if ($email === '') {
            return;
        }

        $assignee = \Users::findFirst([
            'conditions' => 'email = :email: AND deleted_at IS NULL',
            'bind'       => ['email' => $email],
        ]);

        if ($assignee instanceof \Users) {
            $this->assigned_to_user_id = (int) $assignee->id;
        }
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
