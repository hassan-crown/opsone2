<?php

namespace App\Http\Requests\Commissions;

/**
 * Decisions that move no money by themselves (cancel a payout, request or decide a sale correction): the key is
 * optional, the `lock_version` still guards a stale screen.
 */
class CommissionDecisionRequest extends CommissionWriteRequest
{
    protected bool $requiresKey = false;
}
