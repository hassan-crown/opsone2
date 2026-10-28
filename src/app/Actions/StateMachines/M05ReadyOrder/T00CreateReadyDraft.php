<?php

namespace App\Actions\StateMachines\M05ReadyOrder;

use App\Access\TransitionRecorder;
use App\Enums\OrderKind;
use App\Models\Order;
use App\Models\PurchaseReference;
use App\Models\User;
use App\Orders\OrderOpener;

/**
 * M-05-T00: a ready-goods order as a draft (P-15 + `ready_goods`) with lines at the variants' current prices.
 * Confirmation (T01, stock reservation) arrives with inventory in P7; call inside a transaction.
 */
final class T00CreateReadyDraft
{
    public function __construct(private readonly OrderOpener $opener) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, ?PurchaseReference $reference = null, string $source = TransitionRecorder::SOURCE_WEB, string $errorPrefix = ''): Order
    {
        return $this->opener->open($actor, OrderKind::Ready, $data, $reference, $source, $errorPrefix);
    }
}
