<?php

namespace App\Http\Middleware;

use App\Licensing\CurrentLicense;
use App\Licensing\LicenseStatus;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the license banner data with signed-in pages. Never blocks a request (M-19-T05).
 */
class ShareLicenseState
{
    public function __construct(private readonly CurrentLicense $license) {}

    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share('license', fn () => $request->user() !== null ? $this->banner() : null);

        return $next($request);
    }

    /**
     * @return array{status: string, effective_status: ?string, problem: ?string, days_remaining: ?int, phase_ends_at: ?string, show: bool}
     */
    private function banner(): array
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
        ];
    }
}
