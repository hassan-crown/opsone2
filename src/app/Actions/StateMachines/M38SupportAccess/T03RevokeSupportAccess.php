<?php

namespace App\Actions\StateMachines\M38SupportAccess;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Enums\SupportGrantStatus;
use App\Models\SupportGrant;
use App\Models\User;

/**
 * M-38-T03: the owner (P-79) withdraws an active grant before its end. The support session ends immediately;
 * what support did stays in the audit trail.
 */
final class T03RevokeSupportAccess extends SupportAccessTransition
{
    public function handle(User $actor, SupportGrant $grant, ?string $reason = null, string $source = TransitionRecorder::SOURCE_WEB): SupportGrant
    {
        return $this->move('T03', $grant, [SupportGrantStatus::Active], SupportGrantStatus::Revoked, $actor,
            fn (): ?string => Access::can($actor, Permission::P79) ? null : 'not_authorized',
            fn (): array => ['ended_at' => now()],
            $source, $reason,
        );
    }
}
