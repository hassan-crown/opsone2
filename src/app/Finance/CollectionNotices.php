<?php

namespace App\Finance;

use App\Access\Permission;
use App\Models\Customer;
use App\Models\Facility;
use App\Models\Finance\OrderDue;
use App\Models\Finance\PaymentProof;
use App\Models\Finance\Receipt;
use App\Models\Order;
use App\Models\User;
use App\Money\Money;
use App\Notifications\NotificationCatalogue;
use App\Notifications\Notifier;
use App\Notifications\Outbox;

/**
 * Finance messages, all through the transactional outbox (they commit or roll back with the money change; a failed
 * delivery never undoes it): the customer gets the receipt and reminders by SMS (and mail when they have one), staff
 * get in-app notifications whose link re-checks P-37 on the order. Keys make every message idempotent.
 */
final class CollectionNotices
{
    public const RECEIPT_RECORDED = 'finance.receipt_recorded';

    public const PROOF_SUBMITTED = 'finance.proof_submitted';

    public const LATE_GATEWAY_RESULT = 'finance.late_gateway_result';

    public function __construct(
        private readonly Outbox $outbox,
        private readonly Notifier $notifier,
    ) {}

    public static function define(): void
    {
        foreach ([self::RECEIPT_RECORDED, self::PROOF_SUBMITTED, self::LATE_GATEWAY_RESULT] as $type) {
            $slug = str_replace('.', '_', $type);
            NotificationCatalogue::define($type, [
                'mandatory' => false,
                'permission' => Permission::P37,
                'title' => "notifications.type.{$slug}.title",
                'body' => "notifications.type.{$slug}.body",
                'route' => null,
            ]);
        }
    }

    public function receiptIssued(Receipt $receipt, Order $order, ?User $actor): void
    {
        $customer = $order->customer;
        $params = ['number' => $receipt->number, 'amount' => $receipt->amount()->toInput(), 'order' => $order->number];
        $this->toCustomer($customer, 'finance.message.receipt', $params, "receipt:{$receipt->id}");

        $assignee = $order->assignee;
        if ($assignee !== null && $assignee->id !== $actor?->id) {
            $this->notifier->send($assignee, self::RECEIPT_RECORDED, $params, $order, "receipt:{$receipt->id}");
        }
    }

    public function proofSubmitted(PaymentProof $proof, Order $order): void
    {
        $assignee = $order->assignee;
        if ($assignee !== null) {
            $this->notifier->send($assignee, self::PROOF_SUBMITTED, ['order' => $order->number, 'amount' => $proof->claimed()->toInput()], $order, "proof:{$proof->id}");
        }
    }

    public function proofNeedsCorrection(PaymentProof $proof, Order $order, string $reason): void
    {
        $this->toCustomer($order->customer, 'finance.message.proof_correction', ['order' => $order->number, 'reason' => $reason], "proof:{$proof->id}:correction");
    }

    public function proofAmountDiffers(PaymentProof $proof, Order $order, Money $received): void
    {
        $this->toCustomer($order->customer, 'finance.message.proof_amount', [
            'order' => $order->number, 'claimed' => $proof->claimed()->toInput(), 'received' => $received->toInput(),
        ], "proof:{$proof->id}:amount");
    }

    public function lateGatewayResult(Order $order, string $reference): void
    {
        $assignee = $order->assignee;
        if ($assignee !== null) {
            $this->notifier->send($assignee, self::LATE_GATEWAY_RESULT, ['order' => $order->number, 'reference' => $reference], $order, "gateway:{$reference}:late");
        }
    }

    /**
     * @param  'soon'|'overdue'  $kind
     */
    public function dueReminder(OrderDue $due, Order $order, Money $remaining, string $kind, string $period): bool
    {
        return $this->toCustomer($order->customer, "finance.message.due_{$kind}", [
            'order' => $order->number, 'amount' => $remaining->toInput(), 'due' => (string) $due->name->get(self::locale($order->customer)),
        ], "due:{$due->id}:{$kind}:{$period}");
    }

    /**
     * @param  array<string, string>  $params
     */
    private function toCustomer(Customer $customer, string $key, array $params, string $idempotency): bool
    {
        $locale = self::locale($customer);
        $text = __($key, $params, $locale);
        $sent = $this->outbox->enqueueSms($customer->mobile, $text, "{$idempotency}:sms") !== null;
        if ($customer->email !== null && $customer->email !== '') {
            $sent = $this->outbox->enqueueMail($customer->email, __('finance.message.subject', ['facility' => self::facilityName($locale)], $locale), [$text], null, $locale, "{$idempotency}:mail") !== null || $sent;
        }

        return $sent;
    }

    private static function locale(Customer $customer): string
    {
        $facility = Facility::current();

        return $facility === null ? 'ar' : $facility->default_locale;
    }

    private static function facilityName(string $locale): string
    {
        $facility = Facility::current();
        if ($facility === null) {
            return '';
        }

        return $locale === 'en' && $facility->name_en !== null ? $facility->name_en : $facility->name_ar;
    }
}
