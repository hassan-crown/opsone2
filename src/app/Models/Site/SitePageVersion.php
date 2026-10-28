<?php

namespace App\Models\Site;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One language's content of a page. Editable only while it is a draft; once published it is immutable (a change is
 * a new draft version), so the published copy can never be edited in place.
 *
 * @property int $id
 * @property int $page_id
 * @property string $locale
 * @property int $number
 * @property string $title
 * @property string|null $meta_description
 * @property list<array<string, mixed>> $sections
 * @property Carbon|null $published_at
 */
class SitePageVersion extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getOriginal('published_at') !== null) {
                throw new LogicException('A published site page version is immutable.');
            }
        });
        static::deleting(function (self $version): void {
            if ($version->published_at !== null) {
                throw new LogicException('A published site page version cannot be deleted.');
            }
        });
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    protected function casts(): array
    {
        return ['sections' => 'array', 'published_at' => 'datetime'];
    }
}
