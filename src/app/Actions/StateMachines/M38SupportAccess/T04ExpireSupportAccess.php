<?php

namespace App\Actions\StateMachines\M38SupportAccess;

use App\Access\Exceptions\InvalidTransition;
use App\Access\TransitionRecorder;
use App\Enums\SupportGrantStatus;
use App\Models\SupportGrant;

/**
 * M-38-T04 (time service): an active grant reaches `expires_at`, or a request waits past `request_expires_at`.
 * No automatic extension: support asks again (matrix: «لا يمتد تلقائيًا»).
 */
final class T04ExpireSupportAccess extends SupportAccessTransition
{
    public function handle(SupportGrant $grant): SupportGrant
    {
        return $this->move('T04', $grant, [SupportGrantStatus::Requested, SupportGrantStatus::Active], SupportGrantStatus::Expired, null,
            fn (SupportGrant $g): ?string => self::isDue($g) ? null : 'not_due',
            fn (): array => ['ended_at' => now()],
            TransitionRecorder::SOURCE_SYSTEM,
        );
    }

    /**
     * @param  int|null  $supportUserId  only this support identity's grants (used on its own requests)
     */
    public function handleDue(?int $supportUserId = null): int
    {
        $due = SupportGrant::query()
            ->when($supportUserId !== null, fn ($q) => $q->where('support_user_id', $supportUserId))
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', SupportGrantStatus::Active->value)->where('expires_at', '<=', now()))
                ->orWhere(fn ($q) => $q->where('status', SupportGrantStatus::Requested->value)->where('request_expires_at', '<=', now())))
            ->get();

        $count = 0;
        foreach ($due as $grant) {
            try {
                $this->handle($grant);
                $count++;
            } catch (InvalidTransition) {
                // Decided concurrently; nothing to do.
            }
        }

        return $count;
    }

    private static function isDue(SupportGrant $grant): bool
    {
        return $grant->status === SupportGrantStatus::Active
            ? $grant->expires_at !== null && ! $grant->expires_at->isFuture()
            : ! $grant->request_expires_at->isFuture();
    }
}
