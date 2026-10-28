export type LicenseStatus =
    | 'provisioned'
    | 'active'
    | 'grace'
    | 'wind_down'
    | 'expired'
    | 'needs_attention';

/** What the license phase leaves open to staff (ADR-0005). */
export type LicenseMode = 'full' | 'no_new_work' | 'read_only';

/** Main add actions a screen may show disabled with a reason (`Access\LicenseBlocks`). */
export type LicenseBlockedAction =
    | 'orders_create'
    | 'customers_create'
    | 'team_invite'
    | 'branches_create'
    | 'store_links';

export type LicenseBlockReason =
    | 'license'
    | 'license_read_only'
    | 'license_no_new_work'
    | 'seats_limit'
    | 'branches_limit';

/** Shared by `ShareLicenseState` for signed-in pages (ADR-0004, ADR-0028). */
export type LicenseBanner = {
    status: LicenseStatus;
    effective_status: LicenseStatus | null;
    problem: string | null;
    days_remaining: number | null;
    phase_ends_at: string | null;
    show: boolean;
    mode: LicenseMode;
    blocked: Partial<Record<LicenseBlockedAction, LicenseBlockReason>>;
};
