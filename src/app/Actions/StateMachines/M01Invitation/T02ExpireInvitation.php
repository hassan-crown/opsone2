<?php

namespace App\Actions\StateMachines\M01Invitation;

use App\Access\TransitionRecorder;
use App\Enums\InvitationStatus;
use App\Enums\StateMachine;
use App\Models\Invitation;
use Illuminate\Support\Facades\DB;

/**
 * M-01-T02: seven days passed (time service). The link is void; the record is kept.
 */
final class T02ExpireInvitation
{
    public function __construct(private readonly TransitionRecorder $transitions) {}

    /**
     * Expires every pending invitation past its expiry. Idempotent; returns how many were expired.
     */
    public function handleDue(): int
    {
        $count = 0;
        $ids = Invitation::query()
            ->where('status', InvitationStatus::Pending->value)
            ->where('expires_at', '<=', now())
            ->pluck('id');

        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id): int {
                $invitation = Invitation::query()->lockForUpdate()->whereKey($id)->first();
                if ($invitation === null || $invitation->status !== InvitationStatus::Pending || ! $invitation->isPastExpiry()) {
                    return 0;
                }

                $invitation->forceFill(['status' => InvitationStatus::Expired])->save();
                $this->transitions->record(
                    StateMachine::M01, 'T02', $invitation, InvitationStatus::Pending, InvitationStatus::Expired, null, TransitionRecorder::SOURCE_SYSTEM,
                );

                return 1;
            });
        }

        return $count;
    }
}
