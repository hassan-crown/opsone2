<?php

namespace App\Actions\StateMachines\M25Transfer;

use App\Access\TransitionRecorder;
use App\Inventory\TransferStatus;
use App\Models\Inventory\StockTransfer;
use App\Models\User;
use App\Support\LockingTransaction;

/**
 * M-25-T03: withdraw a draft before any exit; no movement. After the exit, a return is a new linked transfer.
 */
final class T03CancelTransfer
{
    public function __construct(private readonly TransferMoves $moves) {}

    public function handle(User $actor, StockTransfer $transfer, ?int $lockVersion = null, ?string $reason = null, string $source = TransitionRecorder::SOURCE_WEB): StockTransfer
    {
        return LockingTransaction::run(function () use ($actor, $transfer, $lockVersion, $source, $reason) {
            $transfer = $this->moves->open($actor, $transfer, 'T03', [TransferStatus::Draft], $lockVersion);

            return $this->moves->finish($actor, $transfer, 'T03', TransferStatus::Draft, TransferStatus::Cancelled, $source, $reason);
        });
    }
}
