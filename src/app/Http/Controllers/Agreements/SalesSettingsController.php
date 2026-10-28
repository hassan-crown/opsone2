<?php

namespace App\Http\Controllers\Agreements;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Commissions\UpdateCommissionSettings;
use App\Actions\Settings\UpdateDiscountLimits;
use App\Actions\Settings\UpdateFinanceSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agreements\DiscountLimitsRequest;
use App\Http\Requests\Agreements\FinanceSettingsRequest;
use App\Models\Commissions\CommissionRate;
use App\Models\Commissions\CommissionSettings;
use App\Models\DiscountLimit;
use App\Models\Membership;
use App\Models\FinanceSettings;
use App\Models\RoleTemplate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SettingsSales (P6a part): VAT rate and price basis, quote validity, contracts on/off (P-02), and discount limits per
 * role (the owner alone), and the commission policy (P-95, P6d). Viewing needs P-01, P-02 or P-95. Ready-link settings
 * arrive with P8.
 */
class SalesSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $this->actor($request);
        abort_unless(Access::can($user, Permission::P01) || Access::can($user, Permission::P02) || Access::can($user, Permission::P95), 403);
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
            'commissions' => self::commissions(),
            'canEditCommissions' => UpdateCommissionSettings::canEdit($user),
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

    public function updateCommissions(Request $request, UpdateCommissionSettings $update): RedirectResponse
    {
        $data = $request->all();
        $version = $data['lock_version'] ?? null;
        unset($data['lock_version']);
        $update->handle($this->actor($request), $data, $version === null ? null : (int) $version);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('commissions.settings.saved')]);

        return to_route('settings.sales');
    }

    /**
     * @return array<string, mixed>
     */
    private static function commissions(): array
    {
        $settings = CommissionSettings::current();
        $rates = CommissionRate::query()->get();
        $locale = app()->getLocale();

        return [
            ...$settings->only(array_keys(CommissionSettings::DEFAULTS)),
            'lock_version' => $settings->lock_version,
            'roles' => array_values(RoleTemplate::query()->where('is_owner', false)->orderBy('id')->get()->map(fn (RoleTemplate $r) => [
                'id' => $r->id,
                'name' => $locale === 'en' ? ($r->name_en ?? $r->name_ar) : $r->name_ar,
                'rate_bp' => $rates->firstWhere('role_template_id', $r->id)?->rate_bp,
            ])->all()),
            'members' => array_values(Membership::query()->with('user')->get()->map(fn (Membership $m) => [
                'id' => $m->user_id,
                'name' => $m->user->name,
                'rate_bp' => $rates->firstWhere('user_id', $m->user_id)?->rate_bp,
            ])->sortBy('name')->values()->all()),
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
