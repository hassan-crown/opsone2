<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One release note on the WhatsNew page. Written by OpsOne knowledge staff (P-81, central) and seeded here.
 *
 * @property int $id
 * @property string $version
 * @property Carbon $released_on
 * @property string $title_ar
 * @property string|null $title_en
 * @property string $body_ar
 * @property string|null $body_en
 * @property Carbon|null $published_at
 */
#[Fillable(['version', 'released_on', 'title_ar', 'title_en', 'body_ar', 'body_en', 'published_at'])]
class WhatsNewEntry extends Model
{
    /**
     * @param  Builder<WhatsNewEntry>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function title(?string $locale = null): string
    {
        return $this->pick('title', $locale);
    }

    public function body(?string $locale = null): string
    {
        return $this->pick('body', $locale);
    }

    private function pick(string $field, ?string $locale): string
    {
        $locale ??= app()->getLocale();
        $order = $locale === 'en' ? ['en', 'ar'] : ['ar', 'en'];

        foreach ($order as $candidate) {
            $value = $this->getAttribute($field.'_'.$candidate);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }

    protected function casts(): array
    {
        return [
            'released_on' => 'date',
            'published_at' => 'datetime',
        ];
    }
}
