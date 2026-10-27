<?php

namespace App\Access;

use App\Access\Exceptions\LicenseLimitReached;
use App\Enums\InvitationStatus;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationUnitStatus;
use App\Licensing\CurrentLicense;
use App\Models\Branch;
use App\Models\Facility;
use App\Models\Invitation;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Seat and branch limits from the signed license (plans doc «العد والحدود», SRS-ORG-09).
 *
 * Seats = active memberships (the owner included) + pending, unexpired invitations; accepting an invitation
 * does not add to the count, disabling a member frees a seat. Only active branches count. A limit that is not
 * proven (no trusted license, read-only phase) refuses any increase; so does wind-down for seats (no new users).
 *
 * Call inside the transaction that adds the seat or branch: the facility row is locked as a mutex, so concurrent
 * invitations cannot both take the last seat.
 */
final class LicenseLimits
{
    public function __construct(private readonly CurrentLicense $license) {}

    public function usedSeats(): int
    {
        return Membership::query()->where('status', MembershipStatus::Active->value)->count()
            + Invitation::query()->where('status', InvitationStatus::Pending->value)->where('expires_at', '>', now())->count();
    }

    public function activeBranches(): int
    {
        return Branch::query()->where('status', OrganizationUnitStatus::Active->value)->count();
    }

    /**
     * @throws LicenseLimitReached
     */
    public function reserveSeat(): void
    {
        $this->lock();
        $used = $this->usedSeats();
        $limit = $this->license->acceptsNewWork() ? $this->license->limit('seats') : null;

        if ($limit === null || $used >= $limit) {
            throw new LicenseLimitReached('seats', $used, $limit);
        }
    }

    /**
     * @throws LicenseLimitReached
     */
    public function reserveBranch(): void
    {
        $this->lock();
        $used = $this->activeBranches();
        $limit = $this->license->limit('branches');

        if ($limit === null || $used >= $limit) {
            throw new LicenseLimitReached('branches', $used, $limit);
        }
    }

    private function lock(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('License limits must be checked inside the transaction that uses them.');
        }

        Facility::query()->lockForUpdate()->orderBy('id')->first();
    }
}
