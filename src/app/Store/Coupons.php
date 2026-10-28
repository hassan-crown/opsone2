<?php

namespace App\Store;

use App\Access\TransitionRecorder;
use App\Enums\StateMachine;
use App\Models\Store\Coupon;
use App\Models\Store\CouponUsage;
use App\Models\Store\StoreCheckout;
use App\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Coupon evaluation and M-32 usage (SAL-06, SAL-07). Validity, scope, minimum and caps are checked when the price is
 * shown and again at checkout start, where the use is reserved under the coupon's row lock: two checkouts racing for
 * the last use cannot both pass (`uses_count` counts reserved + consumed). The per-customer cap follows the verified
 * customer account, never the browser. A reserved use is consumed by the confirmed purchase (once, even when the
 * payment notice repeats) and released only after a settled failure; a consumed use never comes back by itself.
 */
final class Coupons
{
    public function __construct(private readonly TransitionRecorder $transitions) {}

    public function find(string $code): ?Coupon
    {
        $code = Coupon::normalize($code);

        return preg_match('/^[A-Z0-9-]{3,32}$/', $code) === 1 ? Coupon::query()->where('code', $code)->first() : null;
    }

    /**
     * Throws a validation error on `coupon` when the code cannot apply to these lines now (without locking).
     *
     * @param  array{lines: list<array<string, int|string|bool>>, totals: array<string, int>}  $pricing  priced with the coupon
     */
    public function check(?Coupon $coupon, array $pricing, ?int $customerId): Coupon
    {
        if ($coupon === null || $coupon->status() !== CouponStatus::Active) {
            throw ValidationException::withMessages(['coupon' => __('coupons.validation.unavailable')]);
        }
        if ($pricing['totals']['eligible_net_minor'] === 0) {
            throw ValidationException::withMessages(['coupon' => __('coupons.validation.not_applicable')]);
        }
        if ($coupon->min_subtotal_minor !== null && $pricing['totals']['subtotal_minor'] < $coupon->min_subtotal_minor) {
            throw ValidationException::withMessages(['coupon' => __('coupons.validation.minimum', ['amount' => Money::of($coupon->min_subtotal_minor)->toInput()])]);
        }
        if ($coupon->max_uses !== null && $coupon->uses_count >= $coupon->max_uses) {
            throw ValidationException::withMessages(['coupon' => __('coupons.validation.used_up')]);
        }
        if ($customerId !== null && $coupon->max_uses_per_customer !== null && self::customerUses($coupon, $customerId) >= $coupon->max_uses_per_customer) {
            throw ValidationException::withMessages(['coupon' => __('coupons.validation.customer_used')]);
        }

        return $coupon;
    }

    /**
     * M-32-T01, inside the checkout transaction: lock the coupon, check again, hold one use.
     *
     * @param  array{lines: list<array<string, int|string|bool>>, totals: array<string, int>}  $pricing
     */
    public function reserve(Coupon $coupon, StoreCheckout $checkout, array $pricing): CouponUsage
    {
        self::assertTransaction();
        $coupon = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);
        $this->check($coupon, $pricing, $checkout->customer_id);

        $coupon->uses_count++;
        $coupon->save();
        $usage = CouponUsage::query()->create([
            'coupon_id' => $coupon->id, 'checkout_id' => $checkout->id, 'customer_id' => $checkout->customer_id,
            'order_id' => $checkout->order_id, 'status' => CouponUsageStatus::Reserved, 'discount_minor' => $pricing['totals']['discount_minor'],
        ]);
        $this->transitions->record(StateMachine::M32, 'T01', $usage, null, CouponUsageStatus::Reserved, null, TransitionRecorder::SOURCE_SYSTEM, null, [
            'coupon' => $coupon->code, 'checkout' => $checkout->number, 'discount_minor' => $usage->discount_minor,
        ]);

        return $usage;
    }

    /**
     * M-32-T02: the purchase is confirmed. A replay finds it consumed and does nothing. A use released before a late
     * payment is consumed again at its fixed discount (the money was paid at that price; M-32-T05), counted again.
     */
    public function consume(StoreCheckout $checkout): void
    {
        self::assertTransaction();
        $usage = CouponUsage::query()->where('checkout_id', $checkout->id)->lockForUpdate()->first();
        if ($usage === null || $usage->status === CouponUsageStatus::Consumed) {
            return;
        }
        $from = $usage->status;
        if ($from === CouponUsageStatus::Released) {
            Coupon::query()->whereKey($usage->coupon_id)->lockForUpdate()->first();
            Coupon::query()->whereKey($usage->coupon_id)->increment('uses_count');
        }
        $usage->forceFill(['status' => CouponUsageStatus::Consumed])->save();
        $this->transitions->record(StateMachine::M32, $from === CouponUsageStatus::Released ? 'T05' : 'T02', $usage, $from, CouponUsageStatus::Consumed, null, TransitionRecorder::SOURCE_SYSTEM, $from === CouponUsageStatus::Released ? 'late_payment' : null, [
            'checkout' => $checkout->number,
        ]);
    }

    /**
     * M-32-T03: a settled failure or safe cancellation (no money, no open transaction) frees the use.
     */
    public function release(StoreCheckout $checkout, string $reason): void
    {
        self::assertTransaction();
        $usage = CouponUsage::query()->where('checkout_id', $checkout->id)->lockForUpdate()->first();
        if ($usage === null || $usage->status !== CouponUsageStatus::Reserved) {
            return;
        }
        Coupon::query()->whereKey($usage->coupon_id)->lockForUpdate()->first();
        Coupon::query()->whereKey($usage->coupon_id)->where('uses_count', '>', 0)->decrement('uses_count');
        $usage->forceFill(['status' => CouponUsageStatus::Released])->save();
        $this->transitions->record(StateMachine::M32, 'T03', $usage, CouponUsageStatus::Reserved, CouponUsageStatus::Released, null, TransitionRecorder::SOURCE_SYSTEM, $reason, [
            'checkout' => $checkout->number,
        ]);
    }

    public static function customerUses(Coupon $coupon, int $customerId): int
    {
        return CouponUsage::query()->where('coupon_id', $coupon->id)->where('customer_id', $customerId)
            ->whereIn('status', [CouponUsageStatus::Reserved, CouponUsageStatus::Consumed])->count();
    }

    private static function assertTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Coupon usage changes run inside the checkout transaction.');
        }
    }
}
