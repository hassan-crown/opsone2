<?php

namespace App\Actions\StateMachines\M04CustomOrder;

use App\Access\TransitionRecorder;
use App\Enums\OrderKind;
use App\Models\Order;
use App\Models\PurchaseReference;
use App\Models\User;
use App\Orders\OrderOpener;

/**
 * M-04-T00: open a made-to-order order (P-15) with customer, branch, assignee and a need summary — no price, quote or
 * measurement (COM-08). It starts «جارٍ» (in_progress); call inside a transaction.
 */
final class T00OpenCustomOrder
{
    public function __construct(private readonly OrderOpener $opener) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, ?PurchaseReference $reference = null, string $source = TransitionRecorder::SOURCE_WEB, string $errorPrefix = ''): Order
    {
        return $this->opener->open($actor, OrderKind::Custom, $data, $reference, $source, $errorPrefix);
    }
}
