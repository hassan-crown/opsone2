<?php

namespace App\Http\Controllers\Site;

use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Site\SitePage;
use App\Models\Site\SiteTheme;
use App\Site\Preview\SitePreview;
use App\Site\SiteContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * Signed, expiring preview links (THM-04). `issue` (staff) needs the page-editing permission P-55; the draft theme is
 * included only when the issuer also holds P-100. `start` (the signed link itself) stores the preview cookie in the
 * visitor's browser and sends them to the chosen site page; `exit` clears it. Nothing here publishes or changes anything.
 */
final class SitePreviewController extends SiteAdminController
{
    public function issue(Request $request, SiteContext $site): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->authorize($actor, Permission::P55);

        $data = $request->validate([
            'locale' => ['required', Rule::in(SiteContext::LOCALES)],
            'target' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'minutes' => ['nullable', 'integer', 'min:5', 'max:'.(int) config('site.preview.max_minutes')],
        ]);
        $target = $data['target'] ?? null;
        $path = '/'.$data['locale'];
        if ($target === 'store') {
            $path .= '/store';
        } elseif ($target !== null && $target !== 'home') {
            $page = SitePage::query()->where('slug', $target)->whereNull('archived_at')->where('policy', '!=', SitePage::POLICY_SYSTEM)->first();
            abort_if($page === null, 404);
            $path .= '/'.$page->slug;
        }

        $withTheme = $this->access->can($actor, Permission::P100);
        $minutes = (int) ($data['minutes'] ?? config('site.preview.ttl_minutes'));
        $expires = now()->addMinutes($minutes);
        $url = URL::temporarySignedRoute('site.preview.start', $expires, ['to' => $path, 'theme' => $withTheme ? 1 : 0]);

        Audit::record(AuditAction::SitePreviewLinkIssued, SiteTheme::current(), $actor, after: ['to' => $path, 'theme' => $withTheme, 'minutes' => $minutes]);

        return response()->json(['url' => $url, 'expires_at' => $expires->toIso8601String(), 'theme' => $withTheme, 'path' => $path]);
    }

    /** The signed link (middleware `signed` already refused a forged or expired one). */
    public function start(Request $request, SitePreview $preview): RedirectResponse
    {
        $to = (string) $request->query('to', '');
        abort_unless(preg_match('#^/(ar|en)(/store|/[a-z0-9][a-z0-9-]*)?$#', $to) === 1, 404);

        $exp = (int) $request->query('expires');
        $token = SitePreview::token(true, $request->query('theme') === '1', $exp);
        $minutes = max(1, (int) ceil(($exp - time()) / 60));

        return redirect($to)->withCookie(Cookie::make(SitePreview::COOKIE, $token, $minutes, '/', null, $request->isSecure(), true, false, 'lax'));
    }

    public function exit(): RedirectResponse
    {
        return redirect('/')->withCookie(Cookie::forget(SitePreview::COOKIE));
    }
}
