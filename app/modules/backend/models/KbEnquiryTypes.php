<?php
declare(strict_types=1);

use App_skeleton\Models\SoftDeletes;

/**
 * The shared enquiry-type taxonomy (Knowledge-Base-Module-Plan.md v0.1
 * section 4) — an editable table rather than a hardcoded enum so a new
 * category is a data change, not a code change. Referenced by both
 * KbArticles (enquiry_type_id) and Tickets (kb_enquiry_type_id, migration
 * 022) so triage can go straight from a typed ticket to a matching
 * article via the API's matchAction().
 *
 * @method static KbEnquiryTypes|null findFirstById(int $id)
 */
class KbEnquiryTypes extends \Phalcon\Mvc\Model
{
    use SoftDeletes;

    public $id;
    public $name;
    public $description;
    public $deleted_at;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('kb_enquiry_types');
        $this->keepSnapshots(true);
        $this->hasMany('id', 'KbArticles', 'enquiry_type_id', ['alias' => 'Articles']);
    }

    public function beforeSave(): void
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }

    /**
     * How many kb_articles / tickets rows point at this type — the guard
     * KbEnquiryTypesController's delete actions use to refuse removing a
     * type that's still in use. Counts every referencing row, soft-deleted
     * ones included (count() isn't trait-filtered): kb_articles.
     * enquiry_type_id is NOT NULL and a trashed article can be restore()d,
     * so a soft-deleted reference is still a reference the FK would
     * otherwise leave dangling.
     *
     * @return array{articles: int, tickets: int}
     */
    public function referenceCounts(): array
    {
        $bind = ['id' => (int) $this->id];

        return [
            'articles' => (int) \KbArticles::count(['conditions' => 'enquiry_type_id = :id:', 'bind' => $bind]),
            'tickets'  => (int) \Tickets::count(['conditions' => 'kb_enquiry_type_id = :id:', 'bind' => $bind]),
        ];
    }

    public function isReferenced(): bool
    {
        $counts = $this->referenceCounts();

        return $counts['articles'] > 0 || $counts['tickets'] > 0;
    }
}
