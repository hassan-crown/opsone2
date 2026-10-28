<?php

namespace App\Store;

use App\Models\Facility;
use App\Money\Money;
use App\Portal\PortalSession;
use App\Site\SiteContext;
use App\Site\Theme\ThemeSettings;
use Illuminate\Contracts\View\View;

/**
 * Store pages are server-rendered on the public site's surface (`data-surface="site"`, Alpha theme, same header and
 * language rules as ADR-0021), so the store reads as part of the facility's site. This builds the shared chrome.
 */
final class StoreView
{
    public function __construct(private readonly SiteContext $site, private readonly Cart $cart) {}

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, string $locale, string $title, array $data = [], bool $index = true): View
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

        return view($view, [
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'otherLocale' => $other,
            'title' => $title.' | '.$name,
            'robots' => $index ? 'index, follow' : 'noindex, nofollow',
            'siteName' => $name,
            'nav' => $this->site->nav($locale),
            'homePath' => $this->site->path($locale),
            'switchUrl' => $switch,
            'themeCss' => ThemeSettings::css($theme),
            'logo' => $media($theme['logo'] ?? null),
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
