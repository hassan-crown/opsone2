<?php

namespace App\Models\Site;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-language pointers of a page: the live published version and the one editable draft.
 *
 * @property int $id
 * @property int $page_id
 * @property string $locale
 * @property int|null $published_version_id
 * @property int|null $draft_version_id
 */
class SitePageLocale extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<SitePage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(SitePage::class, 'page_id');
    }

    /**
     * @return BelongsTo<SitePageVersion, $this>
     */
    public function published(): BelongsTo
    {
        return $this->belongsTo(SitePageVersion::class, 'published_version_id');
    }

    /**
     * @return BelongsTo<SitePageVersion, $this>
     */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(SitePageVersion::class, 'draft_version_id');
    }
}
