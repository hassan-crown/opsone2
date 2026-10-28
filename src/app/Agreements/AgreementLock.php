<?php

namespace App\Agreements;

use App\Access\Exceptions\InvalidTransition;
use App\Enums\OrderKind;
use App\Enums\StateMachine;
use App\Models\Agreements\AgreementChange;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\DiscountDecision;
use App\Models\Order;
use App\Support\StaleRecord;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Every agreement write locks the order row first (the same lock the workflow engine takes, so a document approval
 * and a stage completion on one order queue), then re-reads its record and checks the version the screen read.
 * Agreements in v1 belong to made-to-order orders; ready goods sell at their published price (domain 04).
 */
final class AgreementLock
{
    public static function order(int $orderId, StateMachine $machine, string $code): Order
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Agreement writes run inside a transaction.');
        }

        $order = Order::query()->lockForUpdate()->find($orderId) ?? throw new LogicException('The order disappeared.');
        if ($order->kind !== OrderKind::Custom) {
            throw new InvalidTransition($machine, $code, 'ready_order');
        }
        if ($order->state()->isFinal()) {
            throw new InvalidTransition($machine, $code, 'order_closed');
        }

        return $order;
    }

    /**
     * @return array{0: Order, 1: AgreementDocument}
     */
    public static function document(AgreementDocument $document, ?int $lockVersion, string $code): array
    {
        $order = self::order($document->order_id, StateMachine::M08, $code);
        $fresh = AgreementDocument::query()->lockForUpdate()->findOrFail($document->id);
        self::checkVersion($fresh->lock_version, $lockVersion);
        $fresh->setRelation('order', $order);

        return [$order, $fresh];
    }

    /**
     * @return array{0: Order, 1: AgreementChange}
     */
    public static function change(AgreementChange $change, ?int $lockVersion, string $code): array
    {
        $order = self::order($change->order_id, StateMachine::M09, $code);
        $fresh = AgreementChange::query()->lockForUpdate()->findOrFail($change->id);
        self::checkVersion($fresh->lock_version, $lockVersion);
        $fresh->setRelation('order', $order);

        return [$order, $fresh];
    }

    /**
     * @return array{0: Order, 1: DiscountDecision}
     */
    public static function decision(DiscountDecision $decision, ?int $lockVersion, string $code): array
    {
        $order = self::order($decision->order_id, StateMachine::M26, $code);
        $fresh = DiscountDecision::query()->lockForUpdate()->findOrFail($decision->id);
        self::checkVersion($fresh->lock_version, $lockVersion);
        $fresh->setRelation('order', $order);

        return [$order, $fresh];
    }

    /**
     * `null` skips the check (system callers only).
     */
    public static function checkVersion(int $current, ?int $expected): void
    {
        if ($expected !== null && $current !== $expected) {
            throw new StaleRecord($current);
        }
    }
}
