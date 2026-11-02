<?php

namespace App\Enums;

/**
 * When a due falls due (FIN-02: a date or a clear event, never a free formula). The closed trigger catalogue of payment
 * plans (ADR-0037): the first agreement's approval, the contract's or design's approval, the start of a named flow
 * stage, the order's first confirmed delivery (P7) or its completed delivery, or a date. `payment_stage` (the stage
 * holding the payment check with the due's reference) remains for the built-in fallback plan. A due may fall due a
 * number of days after its event (`order_dues.offset_days`).
 */
enum DueEvent: string
{
    case Agreement = 'agreement';
    case ContractApproved = 'contract.approved';
    case DesignApproved = 'design.approved';
    case StageStarted = 'stage.started';
    case Delivery = 'delivery';
    case DeliveryCompleted = 'delivery.completed';
    case PaymentStage = 'payment_stage';
    case Date = 'date';

    /**
     * Triggers a payment plan line may use (a fixed date belongs to one order, not to a plan).
     *
     * @return list<string>
     */
    public static function planTriggers(): array
    {
        return array_map(fn (self $e) => $e->value, [
            self::Agreement, self::ContractApproved, self::DesignApproved, self::StageStarted, self::Delivery, self::DeliveryCompleted,
        ]);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $e) => $e->value, self::cases());
    }

    public function needsStage(): bool
    {
        return $this === self::StageStarted;
    }
}
