<?php

namespace App\Actions\StateMachines\M26Decision;

use App\Access\TransitionRecorder;
use App\Models\Agreements\DiscountDecision;
use App\Models\User;

/**
 * M-26-T02 (P-31): reject with a reason; the limit keeps blocking the quote. Same checks as T01ApproveDiscount.
 */
final class T02RejectDiscount
{
    public function __construct(private readonly T01ApproveDiscount $decide) {}

    public function handle(User $actor, DiscountDecision $decision, string $reason, ?int $lockVersion, ?string $idempotencyKey = null, string $source = TransitionRecorder::SOURCE_WEB): DiscountDecision
    {
        return $this->decide->handle($actor, $decision, false, $reason, $lockVersion, $idempotencyKey, $source);
    }
}
