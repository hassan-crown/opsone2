<?php

namespace App\Models\Site;

use App\Access\Permission;
use App\Files\Contracts\AttachmentOwner;
use App\Models\Attachment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A page of the facility site (WEB-03/04, M-17). The M-17 state is derived from the per-language pointers; content
 * lives in immutable `SitePageVersion` rows. Facility-wide scope (P-55 / P-56 are tenant grants).
 *
 * @property int $id
 * @property string $slug
 * @property string $policy content|editable|system
 * @property string|null $system_key
 * @property bool $show_in_nav
 * @property int $sort
 * @property Carbon|null $archived_at
 * @property-read Collection<int, SitePageLocale> $locales
 */
class SitePage extends Model implements AttachmentOwner
{
    public const POLICY_CONTENT = 'content';

    public const POLICY_EDITABLE = 'editable';

    public const POLICY_SYSTEM = 'system';

    /** Paths a content page may never take: routes of this app and of the other P8 surfaces. */
    public const RESERVED_SLUGS = [
        'home', 'store', 'account', 'cart', 'checkout', 'login', 'logout', 'register', 'dashboard', 'api', 'admin',
        'site', 'site-admin', 'site-media', 'sitemap', 'robots', 'contact', 'up', 'storage', 'build', 'files',
        'orders', 'catalog', 'customers', 'help', 'settings', 'notifications', 'my', 'pay', 'ar', 'en',
    ];

    protected $guarded = ['id'];

    /**
     * @return HasMany<SitePageLocale, $this>
     */
    public function locales(): HasMany
    {
        return $this->hasMany(SitePageLocale::class, 'page_id');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->chaperone()->orderBy('id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isSystem(): bool
    {
        return $this->policy === self::POLICY_SYSTEM;
    }

    public function canArchive(): bool
    {
        return $this->policy === self::POLICY_CONTENT && ! $this->isArchived();
    }

    public function isHome(): bool
    {
        return $this->system_key === 'home';
    }

    public function localeRow(string $locale): ?SitePageLocale
    {
        return $this->locales->firstWhere('locale', $locale);
    }

    /**
     * M-17 state: draft | published | published_with_draft | archived.
     */
    public function state(): string
    {
        if ($this->isArchived()) {
            return 'archived';
        }
        $published = $this->locales->contains(fn (SitePageLocale $l) => $l->published_version_id !== null);
        if (! $published) {
            return 'draft';
        }

        return $this->locales->contains(fn (SitePageLocale $l) => $l->draft_version_id !== null) ? 'published_with_draft' : 'published';
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P55;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P55;
    }

    public function accessBranchIds(): array
    {
        return [];
    }

    public function accessDepartmentId(): ?int
    {
        return null;
    }

    public function accessAssigneeIds(): array
    {
        return [];
    }

    public function accessOwnerId(): ?int
    {
        return null;
    }

    protected function casts(): array
    {
        return ['show_in_nav' => 'boolean', 'archived_at' => 'datetime'];
    }
}
