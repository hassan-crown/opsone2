<?php

namespace App\Actions\Organization;

use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\OwnerInvitation;

/**
 * What an invitation link currently is, for the acceptance pages: usable, past its seven days, already used,
 * cancelled/replaced, or not a link we issued.
 */
enum OrgInvitationState: string
{
    case Valid = 'valid';
    case Expired = 'expired';
    case Used = 'used';
    case Cancelled = 'cancelled';
    case Invalid = 'invalid';

    public static function of(Invitation|OwnerInvitation|null $invitation): self
    {
        if ($invitation === null) {
            return self::Invalid;
        }

        return match ($invitation->status) {
            InvitationStatus::Accepted => self::Used,
            InvitationStatus::Cancelled => self::Cancelled,
            InvitationStatus::Expired => self::Expired,
            InvitationStatus::Pending => $invitation->isPastExpiry() ? self::Expired : self::Valid,
        };
    }
}
