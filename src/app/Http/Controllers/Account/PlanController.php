<?php

namespace App\Http\Controllers\Account;

use App\Access\Access;
use App\Access\LicenseLimits;
use App\Access\Permission;
use App\Enums\InvitationStatus;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Licensing\CurrentLicense;
use App\Models\Invitation;
use App\Models\Membership;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PlanUsage / PlanBasic (P-01): the signed license, read-only. Renewal, upgrade and add-ons happen in the OpsOne account.
 */
class PlanController extends Controller
{
    /** Capabilities the app gates on; a plan without one shows it as not included. */
    private const CAPABILITIES = ['ready_goods', 'branch_comparisons'];

    public function __invoke(Request $request, CurrentLicense $license, LicenseLimits $limits): Response
    {
        abort_unless(Access::can($request->user(), Permission::P01), 403);

        $snapshot = $license->snapshot();
        $payload = $snapshot->payload;
        $effective = $snapshot->effectiveStatus();

        return Inertia::render('account/plan', [
            'plan' => $payload?->plan,
            'status' => $snapshot->status->value,
            'effective_status' => $effective?->value,
            'problem' => $snapshot->problem?->value,
            'starts_at' => $payload?->startsAt?->toIso8601ZuluString(),
            'ends_at' => $payload?->endsAt?->toIso8601ZuluString(),
            'grace_ends_at' => $payload?->graceEndsAt()?->toIso8601ZuluString(),
            'wind_down_ends_at' => $payload?->windDownEndsAt()?->toIso8601ZuluString(),
            'phase_ends_at' => $snapshot->phaseEndsAt()?->toIso8601ZuluString(),
            'days_remaining' => $snapshot->daysRemaining(),
            'capabilities' => array_map(fn (string $key): array => [
                'key' => $key,
                'enabled' => $license->allows($key),
            ], self::CAPABILITIES),
            'usage' => [
                'seats' => [
                    'used' => $limits->usedSeats(),
                    'limit' => $payload?->limits['seats'] ?? null,
                    'members' => Membership::query()->where('status', MembershipStatus::Active->value)->count(),
                    'pending_invitations' => Invitation::query()
                        ->where('status', InvitationStatus::Pending->value)
                        ->where('expires_at', '>', now())
                        ->count(),
                ],
                'branches' => [
                    'used' => $limits->activeBranches(),
                    'limit' => $payload?->limits['branches'] ?? null,
                ],
                'storage' => [
                    'measured' => true,
                    'used_mb' => (int) ceil($limits->usedStorageBytes() / 1048576),
                    'limit' => $payload?->limits['storage_mb'] ?? null,
                ],
            ],
        ]);
    }
}
