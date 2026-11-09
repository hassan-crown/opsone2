<?php

namespace App\Ai\Onboarding;

use App\Ai\AiOutcome;
use App\Models\Ai\AiFlowSetup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one setup proposal on the queue (AI-1): the deep model may take up to the background timeout (ai.timeouts),
 * longer than a web request should wait. One attempt — the gateway already retries the provider; the screen shows a
 * failure and the specialist may ask again. Never inside a transaction (afterCommit, and the gateway refuses).
 */
class ProposeFlowSetup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $setupId)
    {
        $this->afterCommit();
    }

    public function handle(FlowDraftProposer $proposer): void
    {
        // Claim the row first: the Redis queue re-offers a job after `retry_after` (90 s) while a slow model call may
        // still be running; the second copy must not call the model again.
        $claimed = AiFlowSetup::query()->whereKey($this->setupId)->where('status', AiFlowSetup::PENDING)
            ->update(['status' => AiFlowSetup::RUNNING, 'updated_at' => now()]);
        $setup = AiFlowSetup::query()->find($this->setupId);
        if ($claimed !== 1 || $setup === null) {
            return;
        }

        try {
            $proposer->propose($setup);
        } catch (Throwable $e) {
            $setup->forceFill(['status' => AiFlowSetup::FAILED, 'outcome' => AiOutcome::Unavailable->value])->save();

            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        AiFlowSetup::query()->whereKey($this->setupId)->whereIn('status', [AiFlowSetup::PENDING])
            ->update(['status' => AiFlowSetup::FAILED, 'outcome' => AiOutcome::Unavailable->value, 'updated_at' => now()]);
    }
}
