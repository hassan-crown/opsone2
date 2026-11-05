<?php

namespace App\Http\Controllers\Site;

use App\Access\Permission;
use App\Actions\Marketing\SaveMarketingSettings;
use App\Models\Marketing\MarketingSettings;
use App\Models\Site\SitePage;
use App\Site\Seo\SiteIndexing;
use App\Site\SiteContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SiteMarketing (ADR-0045, P-107): tag IDs, the WhatsApp button and public links of the facility's site and store.
 * Thin: SaveMarketingSettings validates, authorizes and audits.
 */
final class MarketingController extends SiteAdminController
{
    public function edit(Request $request, SiteContext $site): Response
    {
        $actor = $this->actor($request);
        $this->access->authorize($actor, Permission::P107);
        $s = MarketingSettings::current();
        $privacy = SitePage::query()->with('locales')->where('system_key', 'privacy')->first();

        return Inertia::render('site/marketing', [
            'settings' => [
                ...$s->only(array_keys(MarketingSettings::FORMATS)),
                'whatsapp_number' => $s->whatsapp_number === null ? null : '+'.$s->whatsapp_number,
                'whatsapp_message' => $s->whatsapp_message->toArray(),
                'links' => (object) ($s->links ?? []),
                'lock_version' => $s->exists ? $s->lock_version : null,
            ],
            'linkKeys' => array_keys(MarketingSettings::LINKS),
            'privacy' => [
                'published' => $privacy !== null && $site->publishedLocales($privacy) !== [],
                'edit_url' => $privacy === null ? null : route('site.admin.pages.edit', $privacy),
            ],
            'indexing' => SiteIndexing::enabled(),
            'tabs' => $this->tabs($actor),
        ]);
    }

    public function update(Request $request, SaveMarketingSettings $save): RedirectResponse
    {
        $save->handle($this->actor($request), $request->all(), $request->integer('lock_version') ?: null);
        $this->toast(__('marketing.saved'));

        return to_route('site.admin.marketing.edit');
    }
}
