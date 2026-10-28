<?php

namespace App\Actions\StateMachines\M25Transfer;

use App\Access\TransitionRecorder;
use App\Inventory\TransferStatus;
use App\Models\Inventory\StockTransfer;
use App\Models\User;
use App\Support\LockingTransaction;

/**
 * M-25-T04: immediate transfer — exit and arrival in one transaction.
 */
final class T04InstantTransfer
{
    public function __construct(private readonly TransferMoves $moves) {}

    public function handle(User $actor, StockTransfer $transfer, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): StockTransfer
    {
        return LockingTransaction::run(function () use ($actor, $transfer, $lockVersion, $source) {
            $transfer = $this->moves->open($actor, $transfer, 'T04', [TransferStatus::Draft], $lockVersion);
            $this->moves->lockAll($transfer);
            $this->moves->exit($actor, $transfer);
            $this->moves->arrive($actor, $transfer);

            return $this->moves->finish($actor, $transfer, 'T04', TransferStatus::Draft, TransferStatus::Received, $source, null);
        });
    }
}
