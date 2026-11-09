<?php

namespace App\Ai\Insights;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One recipient's morning digest. The user is loaded fresh when the job runs, so a membership or grant revoked since
 * the nightly dispatch is honoured (the reports use the user's grants at that moment). No retries: a failed digest is
 * simply not sent, and a retry could charge the same credit twice.
 */
final class SendDailyDigest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $userId) {}

    public function handle(DailyDigest $digest): void
    {
        $user = User::query()->find($this->userId);
        // Still a recipient now? (membership or grants may have changed since the dispatch.)
        if ($user === null || ! $digest->isRecipient($user)) {
            return;
        }

        $digest->sendTo($user);
    }
}
