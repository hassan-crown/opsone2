<?php

namespace App\Http\Controllers\Site;

use App\Actions\Site\SubmitLead;
use App\Http\Controllers\Controller;
use App\Http\Requests\Site\LeadRequest;
use App\Site\SiteRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/**
 * POST of the public contact form. Spam is handled without a third-party captcha: a hidden honeypot field, a form
 * token (bound to this form's submission key) that proves the form was shown a few seconds and at most a few hours ago, and the `site-lead` throttle on the route. A suspected bot
 * gets the same success answer but nothing is stored.
 */
final class LeadController extends Controller
{
    public function store(LeadRequest $request, string $locale, SubmitLead $submit): RedirectResponse
    {
        $data = $request->validated();
        $back = fn (string $reference) => back()->with('lead_reference', $reference)->withFragment('contact');

        $age = SiteRenderer::tokenAge($data['form_token'], $data['submission']);
        $bot = filled($data['website'] ?? null) || $age === null || $age < (int) config('site.lead.min_seconds');
        if ($bot) {
            return $back('INT-'.Str::upper(Str::random(4)));
        }

        $interest = $submit->handle($data['submission'], [
            'name' => $data['name'], 'mobile' => $data['mobile'] ?? null, 'email' => $data['email'] ?? null, 'need' => $data['need'],
        ]);

        return $back((string) $interest->reference);
    }
}
