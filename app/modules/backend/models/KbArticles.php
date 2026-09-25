<?php
declare(strict_types=1);

use App_skeleton\Models\SoftDeletes;

/**
 * A Knowledge Base article (Knowledge-Base-Module-Plan.md v0.1 section 3)
 * — human-authored (plan section 5: agents get read-only API access,
 * never create/update/publish), grouped by enquiry_type_id (020) so a
 * matching article can be found for a given enquiry/ticket type rather
 * than relying on a generic search hit. body is stored as markdown and
 * rendered server-side via App_skeleton\Markdown (league/commonmark,
 * safe mode) wherever it's shown as HTML — never trust it as
 * pre-rendered markup.
 *
 * @method static KbArticles|null findFirstById(int $id)
 */
class KbArticles extends \Phalcon\Mvc\Model
{
    use SoftDeletes;

    public $id;
    public $title;
    public $body;
    public $summary;
    public $enquiry_type_id;
    public $product_ref;
    public $visibility;
    public $status;
    public $published_at;
    public $created_by_user_id;
    public $updated_by_user_id;
    public $deleted_at;
    public $created_at;
    public $updated_at;

    public const VISIBILITIES = ['internal' => 'Internal', 'public' => 'Public'];
    public const STATUSES     = ['draft' => 'Draft', 'published' => 'Published'];

    public function initialize(): void
    {
        $this->setSource('kb_articles');
        $this->keepSnapshots(true);
        $this->belongsTo('enquiry_type_id', 'KbEnquiryTypes', 'id', ['alias' => 'EnquiryType']);
        $this->belongsTo('created_by_user_id', 'Users', 'id', ['alias' => 'CreatedBy']);
        $this->belongsTo('updated_by_user_id', 'Users', 'id', ['alias' => 'UpdatedBy']);
    }

    public function beforeSave(): void
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }

    public function isPubliclyVisible(): bool
    {
        return $this->visibility === 'public' && $this->status === 'published';
    }
}
