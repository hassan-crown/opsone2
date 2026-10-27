<?php

namespace App\Http\Controllers\Account;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Organization\UpdateFacilityProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateFacilityProfileRequest;
use App\Models\Facility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SettingsBasic: the facility profile (SET-01..05). Viewing needs P-01 (or P-02), saving P-02.
 */
class FacilityProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        abort_unless(Access::can($user, Permission::P01) || Access::can($user, Permission::P02), 403);

        $facility = Facility::currentOrFail();

        return Inertia::render('account/settings-basic', [
            'facility' => [
                'name_ar' => $facility->name_ar,
                'name_en' => $facility->name_en,
                'contact_email' => $facility->contact_email,
                'contact_phone' => $facility->contact_phone,
                'address' => $facility->address,
                'default_locale' => $facility->default_locale,
                'timezone' => $facility->timezone,
                'currency' => $facility->currency,
                'currency_locked' => $facility->currency_locked_at !== null,
                'version' => $facility->updated_at?->toIso8601String(),
            ],
            'timezones' => config('account.timezones'),
            'currencies' => config('account.currencies'),
            'canEdit' => Access::can($user, Permission::P02),
        ]);
    }

    public function update(UpdateFacilityProfileRequest $request, UpdateFacilityProfile $action): RedirectResponse
    {
        $action->handle($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.profile.saved')]);

        return to_route('settings.basic');
    }
}
