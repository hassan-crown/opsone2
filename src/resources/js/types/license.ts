export type LicenseStatus =
    | 'provisioned'
    | 'active'
    | 'grace'
    | 'wind_down'
    | 'expired'
    | 'needs_attention';

/** Shared by `ShareLicenseState` for signed-in pages (ADR-0004). */
export type LicenseBanner = {
    status: LicenseStatus;
    effective_status: LicenseStatus | null;
    problem: string | null;
    days_remaining: number | null;
    phase_ends_at: string | null;
    show: boolean;
};
