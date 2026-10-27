<?php

namespace App\Models;

use App\Help\HelpCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A bilingual help article (SHR-07). Arabic is authoritative; English falls back to Arabic when missing and
 * the other way round, so a reader never meets an empty page.
 *
 * @property int $id
 * @property string $slug
 * @property HelpCategory $category
 * @property string|null $page_key
 * @property string $title_ar
 * @property string|null $title_en
 * @property string|null $summary_ar
 * @property string|null $summary_en
 * @property string $body_ar
 * @property string|null $body_en
 * @property int $sort
 * @property Carbon|null $published_at
 */
#[Fillable(['slug', 'category', 'page_key', 'title_ar', 'title_en', 'summary_ar', 'summary_en', 'body_ar', 'body_en', 'sort', 'published_at'])]
class HelpArticle extends Model
{
    /**
     * @param  Builder<HelpArticle>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function title(?string $locale = null): string
    {
        return $this->localized('title', $locale) ?? '';
    }

    public function summary(?string $locale = null): ?string
    {
        return $this->localized('summary', $locale);
    }

    public function body(?string $locale = null): string
    {
        return $this->localized('body', $locale) ?? '';
    }

    /**
     * The field in the requested locale, else in the other one (locale fallback).
     */
    private function localized(string $field, ?string $locale): ?string
    {
        $locale ??= app()->getLocale();
        $order = $locale === 'en' ? ['en', 'ar'] : ['ar', 'en'];

        foreach ($order as $candidate) {
            $value = $this->getAttribute($field.'_'.$candidate);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function casts(): array
    {
        return [
            'category' => HelpCategory::class,
            'published_at' => 'datetime',
        ];
    }
}
