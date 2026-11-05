<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Site\Preview\SitePreview;
use App\Site\SiteContext;
use App\Site\SiteRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The public site (P-83): only published versions of live pages, in the language of the URL. Unpublished or system
 * pages are 404, an archived page is 410 (ADR-0045), a language without a published copy falls back to the other
 * language's page — a draft is never shown.
 */
final class PublicSiteController extends Controller
{
    public function __construct(private readonly SiteContext $site, private readonly SiteRenderer $renderer, private readonly SitePreview $preview) {}

    /** `/` — the home page in the facility's default language (canonical points at `/{locale}`). */
    public function root(): View|RedirectResponse
    {
        return $this->show($this->site->defaultLocale(), '');
    }

    public function show(string $locale, string $slug = ''): View|RedirectResponse
    {
        $page = $this->site->page($slug);
        abort_if($page === null || $page->isSystem(), 404);

        // An archived page is gone for good, not moved: 410 tells search engines to drop it (a redirect home reads as a soft 404).
        abort_if($page->isArchived(), 410);

        $version = $this->site->publishedVersion($page, $locale);
        if ($version === null) {
            $other = $locale === 'ar' ? 'en' : 'ar';
            abort_if($this->site->publishedVersion($page, $other) === null, 404);

            return redirect($this->site->path($other, $page));
        }

        return $this->renderer->render($page, $version, $locale, preview: $this->preview->active());
    }
}
