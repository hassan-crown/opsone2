<?php

namespace App\Access;

use App\Access\Enums\DenyReason;
use App\Licensing\CurrentLicense;
use App\Models\User;

/**
 * Why the main "add" actions are unavailable to a user who otherwise holds them (P9, SUB-12/13 facility side), so
 * screens show the action disabled WITH its reason instead of hiding it. Informs only: the Gate and LicenseLimits
 * still refuse on the server.
 *
 * Reasons: license_no_new_work (wind-down), license_read_only (expired / needs attention), license (capability not
 * in the plan), seats_limit / branches_limit (usage at or over the plan after a downgrade; nothing is deleted).
 */
final class LicenseBlocks
{
    /** Shared action key → permission. */
    public const ACTIONS = [
        'orders_create' => Permission::P15,
        'customers_create' => Permission::P09,
        'team_invite' => Permission::P05,
        'branches_create' => Permission::P03,
        'store_links' => Permission::P92,
    ];

    private const LICENSE_REASONS = [DenyReason::License, DenyReason::LicenseReadOnly, DenyReason::LicenseNoNewWork];

    public function __construct(
        private readonly Authorizer $access,
        private readonly LicenseLimits $limits,
        private readonly CurrentLicense $license,
    ) {}

    /**
     * @return array<string, string> action → reason, only for blocked actions the user holds a grant for
     */
    public function for(User $user): array
    {
        $held = [];
        foreach ($this->access->effectiveGrants($user) as $grant) {
            $held[$grant->permission->value] = true;
        }

        $blocked = [];
        foreach (self::ACTIONS as $action => $permission) {
            if (! isset($held[$permission->value])) {
                continue;
            }

            $reason = $this->access->inspect($user, $permission)->reason;
            if ($reason !== null && in_array($reason, self::LICENSE_REASONS, true)) {
                $blocked[$action] = $reason->value;

                continue;
            }

            $limit = match ($action) {
                'team_invite' => $this->full('seats', $this->limits->usedSeats()),
                'branches_create' => $this->full('branches', $this->limits->activeBranches()),
                default => null,
            };
            if ($limit !== null) {
                $blocked[$action] = $limit;
            }
        }

        return $blocked;
    }

    private function full(string $key, int $used): ?string
    {
        $limit = $this->license->limit($key);

        return $limit !== null && $used >= $limit ? $key.'_limit' : null;
    }
}
