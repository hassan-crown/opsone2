<?php

namespace App\Http\Controllers\AppointmentRequests;

use App\Actions\AppointmentRequests\UpdateAppointmentRequestSettings;
use App\Actions\Rules\RulesAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppointmentRequests\AppointmentRequestSettingsRequest;
use App\Models\Appointment;
use App\Models\AppointmentRequestSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › appointment requests (ADR-0039), one of the business-rules pages (ADR-0037 pattern).
 */
class AppointmentRequestSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        RulesAccess::authorizeView($user);
        $settings = AppointmentRequestSettings::current();
        $fields = [];
        foreach (AppointmentRequestSettings::FIELDS as $field) {
            $fields[$field] = $settings->mode($field);
        }

        return Inertia::render('rules/appointment-requests', [
            'settings' => [
                'site_enabled' => $settings->site_enabled, 'portal_enabled' => $settings->portal_enabled,
                'kinds' => $settings->kinds, 'fields' => $fields, 'response_hours' => $settings->response_hours,
                'lock_version' => $settings->exists ? $settings->lock_version : null,
            ],
            'allKinds' => Appointment::KINDS,
            'fieldKeys' => AppointmentRequestSettings::FIELDS,
            'canEdit' => RulesAccess::canEdit($user),
        ]);
    }

    public function update(AppointmentRequestSettingsRequest $request, UpdateAppointmentRequestSettings $update): RedirectResponse
    {
        $update->handle($request->user(), $request->safe()->except('lock_version'), $request->integer('lock_version') ?: null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('rules.saved')]);

        return to_route('rules.appointment_requests');
    }
}
