<?php

namespace App\Http\Controllers\Account;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Settings\UpdateWorkSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateWorkSettingsRequest;
use App\Models\WorkSettings;
use App\Workflow\AppointmentBooking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SettingsWork (Q-31 a): working days and hours, appointment slot length, reminder hours and the conflicting
 * appointments policy. Viewing needs P-01 (or P-02), saving P-02.
 */
class WorkSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        abort_unless(Access::can($user, Permission::P01) || Access::can($user, Permission::P02), 403);
        $settings = WorkSettings::current();

        return Inertia::render('account/settings-work', [
            'settings' => $settings->only(array_keys(WorkSettings::DEFAULTS)),
            'timezone' => AppointmentBooking::timezone(),
            'slotOptions' => [15, 30, 45, 60, 90, 120, 180, 240],
            'canEdit' => Access::can($user, Permission::P02),
        ]);
    }

    public function update(UpdateWorkSettingsRequest $request, UpdateWorkSettings $action): RedirectResponse
    {
        $action->handle($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('appointments.work.saved')]);

        return to_route('settings.work');
    }
}
