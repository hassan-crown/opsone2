<?php

namespace App\Site;

use App\Access\Contracts\LicenseCapabilities;
use App\Models\Facility;
use App\Models\Site\SitePage;
use App\Models\Site\SitePageVersion;
use App\Site\Sections\SectionCatalogue;
use App\Site\Theme\ThemeSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Builds the server-rendered public page (WEB-01): `data-surface="site"`, `lang`/`dir` of the language, SEO tags
 * (title, description, canonical, hreflang, Open Graph) and the sections of one page version.
 */
final class SiteRenderer
{
    public function __construct(private readonly SiteContext $site, private readonly LicenseCapabilities $license) {}

    private bool $preview = false;

    public function render(SitePage $page, SitePageVersion $version, string $locale, bool $preview = false): View
    {
        $this->preview = $preview;
        $theme = $this->site->theme();
        $name = $this->site->facilityName($locale);
        $facility = Facility::current();

        $alternates = [];
        $locales = $preview ? [] : $this->site->publishedLocales($page);
        foreach ($locales as $l) {
            $alternates[$l] = $this->site->url($l, $page);
        }
        if ($alternates !== []) {
            $alternates['x-default'] = $alternates[$this->site->defaultLocale()] ?? reset($alternates);
        }

        $sections = [];
        foreach ($version->sections as $section) {
            if (! $section['visible']) {
                continue;
            }
            if ($section['type'] === 'ready_goods' && ! $this->license->allows('ready_goods')) {
                continue; // the store is a licensed capability: no showcase without it
            }
            $sections[] = $section;
        }

        $description = $version->meta_description
            ?: $this->firstText($version->sections)
            ?: $name;
        $title = $page->isHome() ? ($version->title !== '' ? $version->title.' | '.$name : $name) : $version->title.' | '.$name;

        $appearanceCookie = request()->cookie('appearance');
        $appearance = in_array($appearanceCookie, ['light', 'dark', 'contrast', 'system'], true) ? $appearanceCookie : ($theme['appearance'] ?? 'system');

        return view('site.page', [
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'otherLocale' => $locale === 'ar' ? 'en' : 'ar',
            'page' => $page,
            'version' => $version,
            'sections' => $sections,
            'preview' => $preview,
            'seo' => [
                'title' => $title, 'description' => Str::limit(strip_tags($description), 200),
                'canonical' => $this->site->url($locale, $page), 'alternates' => $alternates,
                'robots' => $preview ? 'noindex, nofollow' : 'index, follow', 'site_name' => $name,
                'image' => $this->mediaUrl($theme['logo'] ?? null),
            ],
            'siteName' => $name,
            'nav' => $this->site->nav($locale),
            'homePath' => $this->site->path($locale),
            'switchUrl' => $this->switchUrl($page, $locale, $preview),
            'themeCss' => ThemeSettings::css($theme),
            'logo' => $this->mediaUrl($theme['logo'] ?? null),
            'logoDark' => $this->mediaUrl($theme['logo_dark'] ?? null),
            'favicon' => $this->mediaUrl($theme['favicon'] ?? null),
            'appearance' => $appearance,
            'contact' => ['phone' => $facility?->contact_phone, 'email' => $facility?->contact_email, 'address' => $facility?->address],
            'formToken' => Crypt::encryptString((string) time()),
            'submissionKey' => (string) Str::uuid(),
            'media' => fn (?string $uuid) => $this->mediaUrl($uuid),
            'linkUrl' => fn (?string $link) => $this->linkUrl($link, $locale),
            'leadAction' => route('site.lead', ['locale' => $locale]),
        ]);
    }

    public static function tokenAge(string $token): ?int
    {
        try {
            return time() - (int) Crypt::decryptString($token);
        } catch (DecryptException) {
            return null;
        }
    }

    private function mediaUrl(?string $uuid): ?string
    {
        return $uuid === null ? null : route($this->preview ? 'site.admin.media' : 'site.media', ['attachment' => $uuid]);
    }

    private function linkUrl(?string $link, string $locale): ?string
    {
        if ($link === null || $link === '') {
            return null;
        }
        if ($link === '#contact') {
            return '#contact';
        }
        if ($link === 'home') {
            return $this->site->path($locale);
        }
        $target = SitePage::query()->where('slug', $link)->whereNull('archived_at')->first();

        return $target === null ? null : $this->site->path($locale, $target);
    }

    private function switchUrl(SitePage $page, string $locale, bool $preview): ?string
    {
        $other = $locale === 'ar' ? 'en' : 'ar';
        if ($preview) {
            return null;
        }

        return in_array($other, $this->site->publishedLocales($page), true) ? $this->site->path($other, $page) : $this->site->path($other);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function firstText(array $sections): ?string
    {
        foreach ($sections as $s) {
            foreach (['subheading', 'text', 'body'] as $key) {
                if (($s['visible'] ?? true) && ! empty($s['data'][$key])) {
                    return (string) $s['data'][$key];
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<string>
     */
    public static function mediaIn(array $sections): array
    {
        return SectionCatalogue::mediaIn($sections);
    }
}
