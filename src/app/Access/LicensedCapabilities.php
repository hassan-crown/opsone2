<?php

namespace App\Access;

use App\Access\Contracts\LicenseCapabilities;
use App\Access\Enums\LicenseMode;
use App\Licensing\CurrentLicense;
use App\Licensing\LicenseStatus;

/**
 * The Gate's view of the signed license (ADR-0004). While the license needs attention, the last trusted license
 * applies at the monotonic clock; without one nothing is proven, so no capability is granted and staff can only
 * read and export (M-19-T05). Login and the user's own account actions are never blocked here.
 */
final class LicensedCapabilities implements LicenseCapabilities
{
    /** Capabilities the app gates on (permissions, reports, the store, help articles). Keep in step with the plans. */
    public const KNOWN = ['ready_goods', 'branch_comparisons', 'marketing_reports'];

    /**
     * AI capabilities (ADR-0050): `ai_core` = essentials in every plan, `ai_plus` = the «OpsOne الذكي» add-on. Gated by
     * App\Ai\AiGateway, not by help articles, so they stay out of KNOWN (which drives the help «higher plan» view).
     */
    public const AI = ['ai_core', 'ai_plus'];

    public function __construct(private readonly CurrentLicense $license) {}

    public function allows(string $capability): bool
    {
        return $this->license->allows($capability);
    }

    public function mode(): LicenseMode
    {
        return match ($this->license->snapshot()->effectiveStatus()) {
            LicenseStatus::Active, LicenseStatus::Grace => LicenseMode::Full,
            LicenseStatus::WindDown => LicenseMode::NoNewWork,
            default => LicenseMode::ReadOnly,
        };
    }
}
