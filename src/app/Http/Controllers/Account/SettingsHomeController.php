<?php

namespace App\Http\Controllers\Account;

use App\Access\Access;
use App\Access\LicenseLimits;
use App\Access\Permission;
use App\Enums\OwnershipTransferStatus;
use App\Enums\SupportGrantStatus;
use App\Http\Controllers\Controller;
use App\Licensing\CurrentLicense;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\OwnershipTransfer;
use App\Models\SupportGrant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SettingsHome (SET-01): one place to find settings. Every signed-in user sees «my account» (P-84); the facility
 * sections appear only to those the Gate allows. Cards only link to a setting's single home (SET-02).
 */
class SettingsHomeController extends Controller
{
    public function __invoke(Request $request, CurrentLicense $license, LicenseLimits $limits): Response
    {
        $user = $request->user();
        $facility = Facility::currentOrFail();
        $membership = Membership::query()->where('user_id', $user->id)->first();
        $seatLimit = $license->limit('seats');

        return Inertia::render('account/settings-home', [
            'facility' => [
                'name_ar' => $facility->name_ar,
                'name_en' => $facility->name_en,
                'default_locale' => $facility->default_locale,
                'currency' => $facility->currency,
                'timezone' => $facility->timezone,
            ],
            'plan' => [
                'name' => $license->snapshot()->payload?->plan,
                'status' => $license->status()->value,
                'seats_used' => $limits->usedSeats(),
                'seats_limit' => $seatLimit,
            ],
            'can' => [
                'editProfile' => Access::can($user, Permission::P02),
                'viewPlan' => Access::can($user, Permission::P01),
                'transferOwnership' => Access::can($user, Permission::P07),
                'manageSupportAccess' => $canSupport = Access::can($user, Permission::P79),
            ],
            'pendingSupportRequests' => $canSupport ? SupportGrant::query()
                ->where('status', SupportGrantStatus::Requested->value)
                ->where('request_expires_at', '>', now())
                ->count() : 0,
            'pendingTransferForMe' => $membership !== null && OwnershipTransfer::query()
                ->where('to_membership_id', $membership->id)
                ->where('status', OwnershipTransferStatus::Requested->value)
                ->where('expires_at', '>', now())
                ->exists(),
        ]);
    }
}
