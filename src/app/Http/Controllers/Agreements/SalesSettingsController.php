<?php

namespace App\Http\Controllers\Agreements;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Settings\UpdateDiscountLimits;
use App\Actions\Settings\UpdateFinanceSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agreements\DiscountLimitsRequest;
use App\Http\Requests\Agreements\FinanceSettingsRequest;
use App\Models\DiscountLimit;
use App\Models\FinanceSettings;
use App\Models\RoleTemplate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SettingsSales (P6a part): VAT rate and price basis, quote validity, contracts on/off (P-02), and discount limits per
 * role (the owner alone). Viewing needs P-01 or P-02. Commission and ready-link settings arrive with P6d/P8.
 */
class SalesSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $this->actor($request);
        abort_unless(Access::can($user, Permission::P01) || Access::can($user, Permission::P02), 403);
        $settings = FinanceSettings::current();
        $limits = DiscountLimit::query()->whereNotNull('role_template_id')->get()->keyBy('role_template_id');

        return Inertia::render('account/settings-sales', [
            'settings' => [...$settings->only(array_keys(FinanceSettings::DEFAULTS)), 'lock_version' => $settings->lock_version],
            'roles' => array_values(RoleTemplate::query()->orderBy('id')->get()->map(fn (RoleTemplate $r) => [
                'id' => $r->id,
                'name' => app()->getLocale() === 'en' ? $r->name_en : $r->name_ar,
                'is_owner' => $r->is_owner,
                'custom_bp' => $r->is_owner ? null : (int) $limits->get($r->id)?->custom_bp,
                'ready_bp' => $r->is_owner ? null : (int) $limits->get($r->id)?->ready_bp,
                'approval_bp' => $r->is_owner ? null : (int) $limits->get($r->id)?->approval_bp,
            ])->all()),
            'canEdit' => Access::can($user, Permission::P02),
            'canEditLimits' => UpdateDiscountLimits::canEdit($user),
        ]);
    }

    public function update(FinanceSettingsRequest $request, UpdateFinanceSettings $update): RedirectResponse
    {
        $data = $request->validated();
        $version = $data['lock_version'] ?? null;
        unset($data['lock_version']);
        $update->handle($this->actor($request), $data, $version === null ? null : (int) $version);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('agreements.settings.saved')]);

        return to_route('settings.sales');
    }

    public function updateLimits(DiscountLimitsRequest $request, UpdateDiscountLimits $update): RedirectResponse
    {
        $update->handle($this->actor($request), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('agreements.settings.saved')]);

        return to_route('settings.sales');
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
