<?php

namespace App\Portal;

use App\Enums\AppointmentStatus;
use App\Enums\DocumentStatus;
use App\Enums\OrderKind;
use App\Enums\ReadyOrderStatus;
use App\Models\Agreements\AgreementDocument;
use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Customer;
use App\Models\Finance\PaymentProof;
use App\Models\Finance\Receipt;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a signed-in customer may see and act on (P-66..P-70, P-99; WEB-02): only records of their own orders. Every
 * customer query starts here, scoped by the authenticated customer id; a record outside the scope is «not found»
 * (404), never «forbidden», so an id reveals nothing. An order id alone never grants anything.
 *
 * Visible: the customer's orders except a ready-goods draft staff are still preparing; documents once sent; proofs
 * they uploaded; receipts of their orders; appointments once sent for confirmation; their cancellation requests.
 */
final class PortalAccess
{
    /**
     * @return Builder<Order>
     */
    public static function orders(Customer $customer): Builder
    {
        return Order::query()->where('orders.customer_id', $customer->id)
            ->where(fn (Builder $q) => $q->where('orders.kind', '!=', OrderKind::Ready->value)
                ->orWhere('orders.status', '!=', ReadyOrderStatus::Draft->value));
    }

    public static function order(Customer $customer, int|string $id): Order
    {
        return self::orders($customer)->whereKey(self::id($id))->firstOrFail();
    }

    /**
     * @return Builder<AgreementDocument>
     */
    public static function documents(Customer $customer): Builder
    {
        return AgreementDocument::query()->whereIn('order_id', self::orders($customer)->select('orders.id'))
            ->whereNotNull('sent_at')->where('status', '!=', DocumentStatus::Draft->value);
    }

    public static function document(Customer $customer, int|string $id): AgreementDocument
    {
        return self::documents($customer)->whereKey(self::id($id))->firstOrFail();
    }

    /**
     * @return Builder<PaymentProof>
     */
    public static function proofs(Customer $customer): Builder
    {
        return PaymentProof::query()->where('customer_id', $customer->id)
            ->whereIn('order_id', self::orders($customer)->select('orders.id'));
    }

    public static function proof(Customer $customer, int|string $id): PaymentProof
    {
        return self::proofs($customer)->whereKey(self::id($id))->firstOrFail();
    }

    /**
     * @return Builder<Receipt>
     */
    public static function receipts(Customer $customer): Builder
    {
        return Receipt::query()->where('customer_id', $customer->id)
            ->whereIn('order_id', self::orders($customer)->select('orders.id'));
    }

    /**
     * @return Builder<Appointment>
     */
    public static function appointments(Customer $customer): Builder
    {
        return Appointment::query()->whereIn('order_id', self::orders($customer)->select('orders.id'))
            ->where('status', '!=', AppointmentStatus::Proposed->value);
    }

    public static function appointment(Customer $customer, int|string $id): Appointment
    {
        return self::appointments($customer)->whereKey(self::id($id))->firstOrFail();
    }

    /**
     * @return Builder<OrderCancellation>
     */
    public static function cancellations(Customer $customer): Builder
    {
        return OrderCancellation::query()->whereIn('order_id', self::orders($customer)->select('orders.id'));
    }

    public static function cancellation(Customer $customer, int|string $id): OrderCancellation
    {
        return self::cancellations($customer)->whereKey(self::id($id))->firstOrFail();
    }

    /**
     * Files the customer may open: those of their own proofs and of documents sent to them.
     */
    public static function canDownload(Customer $customer, Attachment $attachment): bool
    {
        $owner = $attachment->attachable;

        return match (true) {
            $owner instanceof PaymentProof => self::proofs($customer)->whereKey($owner->id)->exists(),
            $owner instanceof AgreementDocument => self::documents($customer)->whereKey($owner->id)->exists(),
            default => false,
        };
    }

    /**
     * Route ids are digits only; anything else is simply not found.
     */
    private static function id(int|string $id): int
    {
        if (is_string($id) && preg_match('/^\d{1,18}$/', $id) !== 1) {
            abort(404);
        }

        return (int) $id;
    }
}
