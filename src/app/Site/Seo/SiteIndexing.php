<?php

namespace App\Site\Seo;

use Illuminate\Http\Request;

/**
 * Which responses search engines may index (ADR-0045). Only the published public surface (site pages, the store
 * catalogue, their media and the SEO files) is ever indexable, and only where indexing is on: production by default,
 * or as `SITE_INDEXING` says. Everything else (staff panel, customer workspace, cart, checkout, field app, previews)
 * is `noindex, nofollow`.
 */
final class SiteIndexing
{
    public const NOINDEX = 'noindex, nofollow';

    /** Route names a search engine may index. */
    public const PUBLIC_ROUTES = [
        'home', 'site.home', 'site.page', 'site.sitemap', 'site.robots', 'site.media',
        'store.index', 'store.product',
    ];

    /** Path prefixes robots.txt keeps crawlers out of (the public surface is everything else). */
    public const DISALLOW = [
        '/site-admin', '/store-admin', '/site-preview', '/dashboard', '/settings', '/portal', '/field', '/login',
        '/api', '/webhooks', '/ar/store/cart', '/en/store/cart', '/ar/store/checkout', '/en/store/checkout',
        '/ar/store/l/', '/en/store/l/',
    ];

    public static function enabled(): bool
    {
        $configured = config('site.indexing');

        return $configured === null ? app()->environment('production') : (bool) $configured;
    }

    public static function indexable(Request $request): bool
    {
        return self::enabled() && in_array($request->route()?->getName(), self::PUBLIC_ROUTES, true);
    }

    /** The `<meta name="robots">` value of a public page. */
    public static function meta(bool $index = true): string
    {
        return $index && self::enabled() ? 'index, follow' : self::NOINDEX;
    }
}
