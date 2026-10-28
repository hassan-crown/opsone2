<?php

namespace App\Models\Site;

use App\Access\Permission;
use App\Files\Contracts\AttachmentOwner;
use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * The facility's single theme row (M-35): `published` is live (null = default Alpha), `draft` is the one private draft.
 *
 * @property int $id
 * @property array<string, mixed>|null $published
 * @property array<string, mixed>|null $draft
 * @property Carbon|null $published_at
 * @property Carbon|null $draft_saved_at
 */
class SiteTheme extends Model implements AttachmentOwner
{
    protected $table = 'site_theme';

    protected $guarded = ['id'];

    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? self::query()->create([]);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('id');
    }

    /**
     * M-35 state: default | custom | draft (a draft exists over the published setting).
     */
    public function state(): string
    {
        if ($this->draft !== null) {
            return 'draft';
        }

        return $this->published === null ? 'default' : 'custom';
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P100;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P100;
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
        return ['published' => 'array', 'draft' => 'array', 'published_at' => 'datetime', 'draft_saved_at' => 'datetime'];
    }
}
