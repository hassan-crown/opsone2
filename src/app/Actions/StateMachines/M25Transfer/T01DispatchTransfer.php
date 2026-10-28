<?php

namespace App\Actions\StateMachines\M25Transfer;

use App\Access\TransitionRecorder;
use App\Inventory\TransferStatus;
use App\Models\Inventory\StockTransfer;
use App\Models\User;
use App\Support\LockingTransaction;

/**
 * M-25-T01: the exit — the quantity leaves the source (available there) and is in transit; nothing is available at the destination yet.
 */
final class T01DispatchTransfer
{
    public function __construct(private readonly TransferMoves $moves) {}

    public function handle(User $actor, StockTransfer $transfer, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): StockTransfer
    {
        return LockingTransaction::run(function () use ($actor, $transfer, $lockVersion, $source) {
            $transfer = $this->moves->open($actor, $transfer, 'T01', [TransferStatus::Draft], $lockVersion);
            $this->moves->exit($actor, $transfer);

            return $this->moves->finish($actor, $transfer, 'T01', TransferStatus::Draft, TransferStatus::InTransit, $source, null);
        });
    }
}
