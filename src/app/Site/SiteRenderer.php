<?php

namespace App\Site;

use App\Access\Contracts\LicenseCapabilities;
use App\Models\AppointmentRequest;
use App\Models\AppointmentRequestSettings;
use App\Models\Facility;
use App\Models\Site\SitePage;
use App\Models\Site\SitePageVersion;
use App\Site\Preview\SitePreview;
use App\Site\Sections\SectionCatalogue;
use App\Site\Seo\SiteIndexing;
use App\Site\Theme\ThemeSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Builds the server-rendered public page (WEB-01): `data-surface="site"`, `lang`/`dir` of the language, SEO tags
 * (title, description, canonical, hreflang, Open Graph) and the sections of one page version.
 */
final class SiteRenderer
{
    public function __construct(private readonly SiteContext $site, private readonly LicenseCapabilities $license) {}

    public function render(SitePage $page, SitePageVersion $version, string $locale, bool $preview = false): View
    {
        $name = $this->site->facilityName($locale);

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
            if ($section['type'] === 'appointment' && ! AppointmentRequestSettings::current()->enabledFor(AppointmentRequest::SOURCE_SITE)) {
                continue; // switched off in the settings (ADR-0039): no form that would only refuse
            }
            $sections[] = $section;
        }

        $description = $version->meta_description
            ?: $this->firstText($version->sections)
            ?: $name;
        $title = $page->isHome() ? ($version->title !== '' ? $version->title.' | '.$name : $name) : $version->title.' | '.$name;

        $submission = (string) Str::uuid();

        $chrome = $this->chrome($locale, $preview);

        return view('site.page', [
            ...$chrome,
            'page' => $page,
            'version' => $version,
            'sections' => $sections,
            'seo' => [
                'title' => $title, 'description' => Str::limit(strip_tags($description), 200),
                'canonical' => $this->site->url($locale, $page), 'alternates' => $alternates,
                'robots' => SiteIndexing::meta(! $preview), 'site_name' => $name,
                'image' => $chrome['logo'],
            ],
            'switchUrl' => $this->switchUrl($page, $locale),
            'formToken' => Crypt::encryptString(time().'|'.$submission),
            'submissionKey' => $submission,
            'media' => fn (?string $uuid) => $this->mediaUrl($uuid),
            'linkUrl' => fn (?string $link) => $this->linkUrl($link, $locale),
            'leadAction' => route('site.lead', ['locale' => $locale]),
            'appointmentAction' => route('site.appointment', ['locale' => $locale]),
            'appointmentForm' => AppointmentRequestSettings::current(),
        ]);
    }

    /**
     * The branded error page of the public surface (404 not found, 410 gone) in the language of the URL: same header,
     * theme and footer as the site, never indexed.
     */
    public function error(int $status, string $locale): Response
    {
        $chrome = $this->chrome($locale, false);
        $title = __('site.error.'.$status.'.title', [], $locale);

        return response()->view('site.error', [
            ...$chrome,
            'status' => $status,
            'seo' => [
                'title' => $title.' | '.$chrome['siteName'], 'description' => __('site.error.'.$status.'.text', [], $locale),
                'canonical' => null, 'alternates' => [], 'robots' => SiteIndexing::NOINDEX, 'site_name' => $chrome['siteName'], 'image' => null,
            ],
            'switchUrl' => $this->site->path($chrome['otherLocale']),
        ], $status, ['X-Robots-Tag' => SiteIndexing::NOINDEX]);
    }

    /**
     * What every public document shares (layout.blade.php): language, header, theme and footer.
     *
     * @return array<string, mixed>
     */
    public function chrome(string $locale, bool $preview): array
    {
        $theme = $this->site->theme();
        $facility = Facility::current();
        $appearanceCookie = request()->cookie('appearance');

        return [
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'otherLocale' => $locale === 'ar' ? 'en' : 'ar',
            'preview' => $preview,
            'previewExit' => $preview && request()->cookie(SitePreview::COOKIE) !== null ? route('site.preview.exit') : null,
            'siteName' => $this->site->facilityName($locale),
            'nav' => $this->site->nav($locale),
            'homePath' => $this->site->path($locale),
            'themeCss' => ThemeSettings::css($theme),
            'logo' => $this->mediaUrl($theme['logo'] ?? null),
            'logoDark' => $this->mediaUrl($theme['logo_dark'] ?? null),
            'favicon' => $this->mediaUrl($theme['favicon'] ?? null),
            'appearance' => in_array($appearanceCookie, ['light', 'dark', 'contrast', 'system'], true) ? $appearanceCookie : ($theme['appearance'] ?? 'system'),
            'contact' => ['phone' => $facility?->contact_phone, 'email' => $facility?->contact_email, 'address' => $facility?->address],
        ];
    }

    /**
     * Seconds since the form was shown, or null for a token that is forged, belongs to another form or is too old.
     */
    public static function tokenAge(string $token, string $submission): ?int
    {
        try {
            [$at, $key] = array_pad(explode('|', Crypt::decryptString($token), 2), 2, '');
        } catch (DecryptException) {
            return null;
        }
        $age = time() - (int) $at;

        return hash_equals($key, $submission) && $age <= (int) config('site.lead.max_seconds') ? $age : null;
    }

    private function mediaUrl(?string $uuid): ?string
    {
        return $uuid === null ? null : route('site.media', ['attachment' => $uuid]);
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

    private function switchUrl(SitePage $page, string $locale): string
    {
        $other = $locale === 'ar' ? 'en' : 'ar';

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
