<?php

namespace App\Store;

use App\Models\Facility;
use App\Money\Money;
use App\Portal\PortalSession;
use App\Site\Preview\SitePreview;
use App\Site\Seo\JsonLd;
use App\Site\Seo\SiteIndexing;
use App\Site\SiteContext;
use App\Site\Theme\ThemeSettings;
use Illuminate\Contracts\View\View;

/**
 * Store pages are server-rendered on the public site's surface (`data-surface="site"`, Alpha theme, same header and
 * language rules as ADR-0021), so the store reads as part of the facility's site. This builds the shared chrome.
 */
final class StoreView
{
    public function __construct(private readonly SiteContext $site, private readonly Cart $cart, private readonly SitePreview $preview, private readonly JsonLd $jsonLd) {}

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $seo  page-specific search tags (description, canonical, alternates, image…)
     */
    public function render(string $view, string $locale, string $title, array $data = [], bool $index = true, array $seo = []): View
    {
        $theme = $this->site->theme();
        $name = $this->site->facilityName($locale);
        $facility = Facility::current();
        $cookie = request()->cookie('appearance');
        $appearance = in_array($cookie, ['light', 'dark', 'contrast', 'system'], true) ? $cookie : ($theme['appearance'] ?? 'system');
        $other = $locale === 'ar' ? 'en' : 'ar';
        $path = '/'.ltrim((string) request()->path(), '/');
        $switch = preg_replace('#^/(ar|en)(/|$)#', '/'.$other.'$2', $path);
        $media = fn (?string $uuid) => $uuid === null ? null : route('site.media', ['attachment' => $uuid]);
        $customer = PortalSession::customer();
        $logo = $media($theme['logo'] ?? null);
        $robots = SiteIndexing::meta($index && ! $this->preview->active());

        return view($view, [
            'seo' => [
                'title' => $title.' | '.$name, 'description' => '', 'robots' => $robots,
                'canonical' => null, 'alternates' => [], 'site_name' => $name, 'image' => $logo,
                ...$seo,
                'json_ld' => [JsonLd::encode($this->jsonLd->business($locale, $logo)), ...($seo['json_ld'] ?? [])],
            ],
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'otherLocale' => $other,
            'preview' => $this->preview->active(),
            'previewExit' => $this->preview->active() ? route('site.preview.exit') : null,
            'siteName' => $name,
            'nav' => $this->site->nav($locale),
            'homePath' => $this->site->path($locale),
            'switchUrl' => $switch,
            'themeCss' => ThemeSettings::css($theme),
            'logo' => $logo,
            'logoDark' => $media($theme['logo_dark'] ?? null),
            'favicon' => $media($theme['favicon'] ?? null),
            'appearance' => $appearance,
            'contact' => ['phone' => $facility?->contact_phone, 'email' => $facility?->contact_email],
            'cartCount' => $this->cart->count(),
            'customerName' => $customer?->name,
            'money' => fn (int $minor) => self::money($minor, $locale),
            ...$data,
        ]);
    }

    public static function money(int $minor, string $locale): string
    {
        $amount = Money::of($minor)->toInput();
        $currency = strtoupper((string) (Facility::current()->currency ?? 'SAR'));

        return $locale === 'ar' ? $amount.' '.($currency === 'SAR' ? 'ر.س' : $currency) : $currency.' '.$amount;
    }
}
