<?php

namespace App\Http\Controllers\Rules;

use App\Actions\Rules\RulesAccess;
use App\Actions\Rules\SaveNumberFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rules\NumberFormatRequest;
use App\Models\NumberFormat;
use App\Numbering\NumberFormats;
use App\Workflow\AppointmentBooking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › numbering (ADR-0037): the format of each document sequence with an example of the next number. Tax
 * invoices are listed as fixed (ZATCA).
 */
class NumberFormatController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        RulesAccess::authorizeView($user);
        $saved = NumberFormat::query()->get()->keyBy('sequence');
        $now = now(AppointmentBooking::timezone());

        return Inertia::render('rules/numbering', [
            'formats' => array_map(function (string $sequence, array $default) use ($saved, $now) {
                $format = $saved->get($sequence);
                $mode = $format->year_mode ?? 'gregorian';

                return [
                    'sequence' => $sequence,
                    'prefix' => $format->prefix ?? $default['prefix'],
                    'year_mode' => $mode,
                    'per_branch' => (bool) ($format->per_branch ?? false),
                    'pad' => $format->pad ?? $default['pad'],
                    'start_at' => $format?->start_at,
                    'branchable' => in_array($sequence, NumberFormats::BRANCHED, true),
                    'example' => NumberFormats::compose($format->prefix ?? $default['prefix'], ($format->per_branch ?? false) ? 'RYD' : null,
                        $mode === 'none' ? null : NumberFormats::period($mode, $now), $format->start_at ?? 1, $format->pad ?? $default['pad']),
                ];
            }, array_keys(NumberFormats::SEQUENCES), NumberFormats::SEQUENCES),
            'fixed' => ['INV', 'CRN', 'DBN'],
            'canEdit' => RulesAccess::canEdit($user),
        ]);
    }

    public function update(NumberFormatRequest $request, SaveNumberFormat $save): RedirectResponse
    {
        $save->handle($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('rules.saved')]);

        return to_route('rules.numbering');
    }
}
