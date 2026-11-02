<?php

namespace App\Http\Middleware;

use App\Site\Preview\SitePreview;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Switches the public site and the store to the preview of the drafts for a browser that holds a valid preview cookie
 * (SitePreview). Preview is read only (no lead, no checkout, no account action — only the guest cart works so the cart
 * page can be reviewed), never indexed and never cached. The sitemap and robots.txt always describe the live site.
 */
class SitePreviewMode
{
    /** Routes a preview may still POST to: the guest cart. */
    private const WRITABLE = ['store.cart.add', 'store.cart.update'];

    private const LIVE_ONLY = ['site.sitemap', 'site.robots'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app(SitePreview::class)->reset();
        $grant = in_array($request->route()?->getName(), self::LIVE_ONLY, true) ? null : SitePreview::read($request->cookie(SitePreview::COOKIE));
        if ($grant === null) {
            return $next($request);
        }

        app(SitePreview::class)->activate($grant);
        if (! $request->isMethodSafe() && ! in_array($request->route()?->getName(), self::WRITABLE, true)) {
            abort(403, __('site.preview.read_only'));
        }

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}
