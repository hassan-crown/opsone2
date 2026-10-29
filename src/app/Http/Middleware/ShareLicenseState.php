<?php

namespace App\Http\Middleware;

use App\Access\LicenseBlocks;
use App\Access\LicensedCapabilities;
use App\Licensing\CurrentLicense;
use App\Licensing\LicenseStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the license banner data with signed-in pages. Never blocks a request (M-19-T05).
 */
class ShareLicenseState
{
    public function __construct(
        private readonly CurrentLicense $license,
        private readonly LicensedCapabilities $capabilities,
        private readonly LicenseBlocks $blocks,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share('license', fn () => $request->user() instanceof User ? $this->banner($request->user()) : null);

        return $next($request);
    }

    /**
     * `mode` is what the phase leaves open (full / no_new_work / read_only); `blocked` maps the main add actions the
     * user holds to the reason they are unavailable (LicenseBlocks), so screens disable them with that reason.
     *
     * @return array{status: string, effective_status: ?string, problem: ?string, days_remaining: ?int, phase_ends_at: ?string, show: bool, mode: string, blocked: array<string, string>}
     */
    private function banner(User $user): array
    {
        $snapshot = $this->license->snapshot();
        $days = $snapshot->daysRemaining();

        return [
            'status' => $snapshot->status->value,
            'effective_status' => $snapshot->effectiveStatus()?->value,
            'problem' => $snapshot->problem?->value,
            'days_remaining' => $days,
            'phase_ends_at' => $snapshot->phaseEndsAt()?->toIso8601ZuluString(),
            'show' => $snapshot->status !== LicenseStatus::Active
                || ($days !== null && $days <= (int) config('license.renewal_notice_days', 14)),
            'mode' => $this->capabilities->mode()->value,
            'blocked' => $user->is_support ? [] : $this->blocks->for($user),
        ];
    }
}
