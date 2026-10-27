<?php

namespace App\Actions\StateMachines\M38SupportAccess;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Enums\SupportGrantStatus;
use App\Models\SupportGrant;
use App\Models\User;

/**
 * M-38-T02: the owner (P-79) declines a request. Nothing was ever opened.
 */
final class T02RejectSupportAccess extends SupportAccessTransition
{
    public function handle(User $actor, SupportGrant $grant, ?string $reason = null, string $source = TransitionRecorder::SOURCE_WEB): SupportGrant
    {
        return $this->move('T02', $grant, [SupportGrantStatus::Requested], SupportGrantStatus::Rejected, $actor,
            fn (): ?string => Access::can($actor, Permission::P79) ? null : 'not_authorized',
            fn (): array => ['decided_by_user_id' => $actor->id, 'decided_at' => now(), 'ended_at' => now()],
            $source, $reason,
        );
    }
}
