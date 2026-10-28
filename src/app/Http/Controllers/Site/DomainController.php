<?php

namespace App\Http\Controllers\Site;

use App\Access\Permission;
use App\Actions\Site\DomainManager;
use App\Models\Site\SiteDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DomainVerify (SUB-19, Q-46 a): record the custom domain, show the DNS TXT proof to publish, check it.
 */
final class DomainController extends SiteAdminController
{
    public function show(Request $request, DomainManager $domains): Response
    {
        $actor = $this->actor($request);
        $this->access->authorize($actor, Permission::P101);
        $d = $domains->current();

        return Inertia::render('site/domain', [
            'domain' => $d === null ? null : [
                'hostname' => $d->hostname, 'status' => $d->status, 'txt_name' => $d->txtName(), 'txt_value' => $d->txtValue(),
                'verified_at' => $d->verified_at?->toIso8601String(), 'last_checked_at' => $d->last_checked_at?->toIso8601String(), 'last_error' => $d->last_error,
            ],
            'platformHost' => parse_url((string) config('app.url'), PHP_URL_HOST),
            'tabs' => $this->tabs($actor),
        ]);
    }

    public function store(Request $request, DomainManager $domains): RedirectResponse
    {
        $request->validate(['hostname' => ['required', 'string', 'max:253']]);
        $domains->request($this->actor($request), (string) $request->input('hostname'));
        $this->toast(__('site.domain.toast.requested'));

        return back();
    }

    public function check(Request $request, DomainManager $domains): RedirectResponse
    {
        $d = $domains->check($this->actor($request));
        $this->toast($d->status === SiteDomain::VERIFIED ? __('site.domain.toast.verified') : __('site.domain.toast.not_found'));

        return back();
    }

    public function destroy(Request $request, DomainManager $domains): RedirectResponse
    {
        $domains->remove($this->actor($request));
        $this->toast(__('site.domain.toast.removed'));

        return back();
    }
}
