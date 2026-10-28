<?php

namespace App\Actions\StateMachines\M25Transfer;

use App\Access\TransitionRecorder;
use App\Inventory\TransferStatus;
use App\Models\Inventory\StockTransfer;
use App\Models\User;
use App\Support\LockingTransaction;

/**
 * M-25-T02: confirm arrival once at the destination; the in-transit quantity becomes on hand there.
 */
final class T02ReceiveTransfer
{
    public function __construct(private readonly TransferMoves $moves) {}

    public function handle(User $actor, StockTransfer $transfer, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): StockTransfer
    {
        return LockingTransaction::run(function () use ($actor, $transfer, $lockVersion, $source) {
            $transfer = $this->moves->open($actor, $transfer, 'T02', [TransferStatus::InTransit], $lockVersion);
            $this->moves->arrive($actor, $transfer);

            return $this->moves->finish($actor, $transfer, 'T02', TransferStatus::InTransit, TransferStatus::Received, $source, null);
        });
    }
}
