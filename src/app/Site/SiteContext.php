<?php

namespace App\Site;

use App\Models\Attachment;
use App\Models\Facility;
use App\Models\Site\SiteDomain;
use App\Models\Site\SitePage;
use App\Models\Site\SitePageLocale;
use App\Models\Site\SitePageVersion;
use App\Models\Site\SiteTheme;
use App\Site\Theme\ThemeSettings;

/**
 * Read side of the public site: what a visitor may see. Only published versions of non-archived pages are ever
 * returned (never a draft), and the base URL / name come from one place so every tag agrees.
 */
final class SiteContext
{
    public const LOCALES = ['ar', 'en'];

    public function defaultLocale(): string
    {
        $locale = Facility::current()?->default_locale;

        return in_array($locale, self::LOCALES, true) ? $locale : (string) config('app.locale', 'ar');
    }

    public function facilityName(string $locale): string
    {
        $f = Facility::current();
        if ($f === null) {
            return (string) config('app.name');
        }

        return $locale === 'en' && $f->name_en ? $f->name_en : $f->name_ar;
    }

    /**
     * The canonical origin: the verified custom domain, else the configured application URL.
     */
    public function baseUrl(): string
    {
        $domain = SiteDomain::query()->where('status', SiteDomain::VERIFIED)->orderBy('id')->first();

        return $domain !== null ? 'https://'.$domain->hostname : rtrim((string) config('app.url'), '/');
    }

    public function url(string $locale, ?SitePage $page = null): string
    {
        $path = '/'.$locale;
        if ($page !== null && ! $page->isHome()) {
            $path .= '/'.$page->slug;
        }

        return $this->baseUrl().$path;
    }

    public function path(string $locale, ?SitePage $page = null): string
    {
        return '/'.$locale.($page === null || $page->isHome() ? '' : '/'.$page->slug);
    }

    public function page(string $slug): ?SitePage
    {
        return SitePage::query()->with('locales')->where('slug', $slug)->first();
    }

    public function publishedVersion(SitePage $page, string $locale): ?SitePageVersion
    {
        if ($page->isArchived() || $page->isSystem()) {
            return null;
        }
        /** @var SitePageLocale|null $row */
        $row = $page->localeRow($locale);

        return $row?->published_version_id === null ? null : SitePageVersion::query()->find($row->published_version_id);
    }

    /**
     * Languages the page is published in.
     *
     * @return list<string>
     */
    public function publishedLocales(SitePage $page): array
    {
        return array_values(array_filter(self::LOCALES, fn ($l) => $this->publishedVersion($page, $l) !== null));
    }

    /**
     * Pages listed in the header navigation for a language.
     *
     * @return list<array{title: string, path: string}>
     */
    public function nav(string $locale): array
    {
        $out = [];
        foreach (SitePage::query()->with('locales')->where('show_in_nav', true)->whereNull('archived_at')->orderBy('sort')->get() as $page) {
            $v = $this->publishedVersion($page, $locale);
            if ($v !== null) {
                $out[] = ['title' => $v->title, 'path' => $this->path($locale, $page)];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function theme(): array
    {
        return ThemeSettings::published(SiteTheme::current());
    }

    /**
     * Is the file shown on the live site (a published page of a non-archived page, or the live theme)?
     */
    public function isPublicMedia(Attachment $attachment): bool
    {
        $theme = ThemeSettings::published();
        if (in_array($attachment->uuid, array_filter([$theme['logo'], $theme['logo_dark'], $theme['favicon']]), true)) {
            return true;
        }

        $ids = SitePageLocale::query()->whereNotNull('published_version_id')
            ->whereHas('page', fn ($q) => $q->whereNull('archived_at')->where('policy', '!=', SitePage::POLICY_SYSTEM))
            ->pluck('published_version_id');

        return SitePageVersion::query()->whereIn('id', $ids)->where('sections', 'like', '%'.$attachment->uuid.'%')->exists();
    }
}
