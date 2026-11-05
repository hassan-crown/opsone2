<?php

namespace App\Marketing;

use App\Models\Marketing\MarketingSettings;
use App\Models\Site\SitePage;
use App\Site\SiteContext;
use App\Support\RequestCache;
use Illuminate\View\View;

/**
 * Adds the marketing pieces to every public document (site/layout and store/layout, ADR-0045): the tracking config
 * with the page's events (only when a tag is configured, and never in a draft preview), the WhatsApp button, the
 * facility's profile links and the privacy / terms pages once published.
 */
final class PublicPageComposer
{
    public function __construct(private readonly SiteContext $site) {}

    public function compose(View $view): void
    {
        $data = $view->getData();
        $locale = (string) ($data['locale'] ?? 'ar');
        $settings = MarketingSettings::current();
        $preview = (bool) ($data['preview'] ?? false);

        $tracking = null;
        if ($settings->tracks() && ! $preview) {
            $tracking = [
                'tags' => $settings->tags(),
                'events' => [...Tracking::flashed(), ...($data['trackingEvents'] ?? [])],
                'secure' => request()->isSecure(),
            ];
        }

        $view->with('marketing', [
            'tracking' => $tracking,
            'whatsapp' => $settings->whatsappUrl($locale),
            'profiles' => $settings->profiles(),
            'review' => $settings->links['google_review'] ?? null,
            'policies' => $this->policies($locale),
        ]);
    }

    /**
     * Published policy pages of the language: key => path.
     *
     * @return array<string, string>
     */
    private function policies(string $locale): array
    {
        return app(RequestCache::class)->remember('marketing.policies.'.$locale, function () use ($locale) {
            $out = [];
            foreach (SitePage::query()->with('locales')->whereIn('system_key', ['privacy', 'terms'])->orderBy('sort')->get() as $page) {
                if (in_array($locale, $this->site->publishedLocales($page), true)) {
                    $out[(string) $page->system_key] = $this->site->path($locale, $page);
                }
            }

            return $out;
        });
    }
}
