<?php

namespace App\Http\Controllers\Site;

use App\Actions\AppointmentRequests\SubmitAppointmentRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppointmentRequests\SiteAppointmentRequestRequest;
use App\Marketing\Tracking;
use App\Site\SiteRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * POST of the public «request an appointment» form (ADR-0039). Same spam guards as the contact form (honeypot, form
 * token, `site-appointment` throttle); a suspected bot gets the same answer and nothing is stored. The answer only
 * says the request was received — staff call back to fix the time.
 */
final class AppointmentRequestController extends Controller
{
    public function store(SiteAppointmentRequestRequest $request, string $locale, SubmitAppointmentRequest $submit): RedirectResponse
    {
        $data = $request->validated();
        $back = fn (string $reference) => back()->with('appointment_reference', $reference)->withFragment('appointment');

        $age = SiteRenderer::tokenAge($data['form_token'], $data['submission']);
        if (filled($data['website'] ?? null) || $age === null || $age < (int) config('site.lead.min_seconds')) {
            return $back('APR-'.Str::upper(Str::random(4)));
        }

        $created = $submit->fromSite($data['submission'], Arr::except($data, ['submission', 'form_token', 'website']));

        Tracking::flash('appointment_request', ['form' => 'appointment']); // never the visitor's details (ADR-0045)

        return $back((string) $created->reference);
    }
}
