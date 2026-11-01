<?php

namespace Database\Seeders\ShowcaseKit;

use App\Actions\Orders\CreateOrders;
use App\Actions\StateMachines\M05ReadyOrder\T01ConfirmReadyOrder;
use App\Actions\StateMachines\M05ReadyOrder\T09CancelReadyRemainder;
use App\Actions\StateMachines\M11Payment\T01RecordPayment;
use App\Actions\StateMachines\M12Refund\T00RequestRefund;
use App\Actions\StateMachines\M12Refund\T02ConfirmRefund;
use App\Actions\StateMachines\M14Delivery\T00CreateDraft;
use App\Actions\StateMachines\M14Delivery\T01ConfirmDelivery;
use App\Actions\StateMachines\M15Return\T00RequestReturn;
use App\Actions\StateMachines\M15Return\T01ReceiveReturn;
use App\Actions\StateMachines\M15Return\T05AcceptReturn;
use App\Finance\FinanceLock;
use App\Inventory\Quantity;
use App\Models\Customer;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockItem;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Ready-accessory sales over the counter: order → reservation (confirm) → payment → hand-over with a delivery note,
 * and a few partial / full cancellations (with refund) and returns (good, damaged), all through the real actions.
 */
final class ReadyOrders
{
    public function __construct(private readonly Showcase $s) {}

    public function run(): void
    {
        $rng = $this->s->rng;
        $total = $this->s->n(78); // showroom share of the ~120 ready orders; the store adds the rest
        $span = (int) $this->s->start->diffInDays($this->s->end);
        for ($i = 0; $i < $total; $i++) {
            $day = (int) floor(($span - 2) * sqrt($rng->int(1, 10000) / 10000));
            $when = $this->s->start->addDays(max(2, $day))->setTime($rng->int(10, 20), $rng->int(0, 59));
            while (in_array($when->dayOfWeek, [5], true)) {
                $when = $when->addDay();
            }
            $branch = $rng->weighted(['RUH' => 52, 'JED' => 28, 'DMM' => 20]);
            $variant = $rng->weighted(['normal' => 80, 'cancel_full' => 4, 'cancel_part' => 4, 'return_good' => 6, 'return_damaged' => 3, 'no_delivery' => 3]);
            $plan = ['branch' => $branch, 'outcome' => $variant, 'method' => $rng->weighted(['transfer' => 40, 'card' => 38, 'cash' => 22]),
                'lines' => $rng->int(1, 4), 'newCustomer' => $rng->chance(22)];
            $story = new Story('ready-'.$i);
            $this->s->timeline->at($when, $story, 'sale', fn (Story $st) => $this->sale($st, $plan));
        }
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function seller(string $branch): User
    {
        return $this->s->users[$this->s->rng->pick(['RUH' => ['sales_ruh1', 'sales_ruh2'], 'JED' => ['sales_jed'], 'DMM' => ['sales_dmm']][$branch])];
    }

    private function locationKey(string $branch): string
    {
        return $branch === 'RUH' && $this->s->rng->chance(50) ? 'RUH-SHOW' : $branch;
    }

    private function available(int $locationId, int $variantId): int
    {
        $item = StockItem::query()->where('item_type', 'ready_variant')->where('item_id', $variantId)->first();
        $b = $item === null ? null : StockBalance::query()->where('location_id', $locationId)->where('stock_item_id', $item->id)->first();

        return $b === null ? 0 : (int) (floor(($b->on_hand_milli - $b->reserved_milli) / 1000));
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function sale(Story $st, array $p): void
    {
        $rng = $this->s->rng;
        $branch = $p['branch'];
        $sales = $this->seller($branch);
        $locationKey = $this->locationKey($branch);
        $location = $this->s->catalog->locations[$locationKey];

        $lines = [];
        $used = [];
        foreach ($rng->shuffle($this->s->catalog->variants) as $variant) {
            if (count($lines) >= $p['lines']) {
                break;
            }
            $qty = $rng->weighted(['1' => 50, '2' => 28, '3' => 12, '4' => 6, '6' => 4]);
            if ($p['outcome'] === 'cancel_part' && $lines === []) {
                $qty = '4';
            }
            if (isset($used[$variant->id]) || $this->available($location->id, $variant->id) < (int) $qty + 2) {
                continue;
            }
            $used[$variant->id] = true;
            $lines[] = ['type' => 'ready_variant', 'variant_id' => $variant->id, 'quantity' => (int) $qty];
        }
        if ($lines === []) {
            throw new \RuntimeException('nothing in stock at '.$locationKey);
        }
        $customerId = $this->s->leads->customerFor($branch, $p['newCustomer'] ? 100 : 25);
        $res = app(CreateOrders::class)->handle($sales, $this->key(), [
            'customer_id' => $customerId, 'branch_id' => $this->s->branches[$branch]->id, 'assignee_id' => $sales->id, 'ready' => ['invoice_type' => 'simplified', 'lines' => $lines],
        ]);
        $order = Order::query()->findOrFail($res['result']['order_ids'][0]);
        $st->data['order'] = $order->id;
        $st->data += ['branch' => $branch, 'location' => $location->id, 'method' => $p['method'], 'outcome' => $p['outcome']];

        $now = CarbonImmutable::now();
        $app = fn (int $minutes) => $now->addMinutes($minutes);
        $this->s->timeline->at($app(10), $st, 'confirm', function (Story $st) use ($sales) {
            $order = Order::query()->findOrFail($st->int('order'));
            app(T01ConfirmReadyOrder::class)->handle($sales, $order, $st->data['location']);
        });
        if ($p['outcome'] === 'cancel_full') {
            $this->s->timeline->at($app(60 * 20), $st, 'cancel', function (Story $st) {
                $order = Order::query()->findOrFail($st->int('order'));
                app(T09CancelReadyRemainder::class)->handle($this->s->users['gm'], $order, 'العميل غيّر رأيه قبل الاستلام ولم يُدفع شيء.', $order->lock_version);
            });

            return;
        }
        if ($p['outcome'] === 'no_delivery') {
            $this->s->timeline->at($app(30), $st, 'pay', fn (Story $st) => $this->pay($st));

            return; // paid and waiting for pick-up
        }
        $this->s->timeline->at($app(35), $st, 'pay', fn (Story $st) => $this->pay($st));
        $partial = $p['outcome'] === 'cancel_part';
        $this->s->timeline->at($app(60 * 3), $st, 'deliver', fn (Story $st) => $this->deliver($st, $partial));

        if ($partial) {
            $this->s->timeline->at($app(60 * 24 * 2 + 600), $st, 'cancel-rest', function (Story $st) {
                $order = Order::query()->findOrFail($st->int('order'));
                app(T09CancelReadyRemainder::class)->handle($this->s->users['gm'], $order, 'تعذر استلام باقي القطع؛ ألغي الجزء غير المسلَّم.', $order->lock_version);
                $this->refundUnits($st, 'استرداد قيمة القطع الملغاة.', 0.4);
            });
        }
        if (in_array($p['outcome'], ['return_good', 'return_damaged'], true)) {
            $this->s->timeline->at($app(60 * 24 * $rng->int(3, 9) + 600), $st, 'return', fn (Story $st) => $this->returnGoods($st));
        }
    }

    private function pay(Story $st): void
    {
        $order = Order::query()->findOrFail($st->int('order'));
        $amount = Money::of((int) $order->total_minor);
        app(T01RecordPayment::class)->handle($this->s->users['finance'], $order, [
            'amount' => $amount->toInput(), 'method' => $st->data['method'], 'paid_on' => CarbonImmutable::now()->toDateString(),
            'reference' => $st->data['method'] === 'cash' ? null : strtoupper($st->data['method'][0]).$this->s->rng->int(100000000, 999999999),
            'acknowledge_similar' => true,
        ], Assets::pdf('receipt-'.$order->number.'.pdf', 'Payment '.$amount->toInput()), null, $this->key());
    }

    private function deliver(Story $st, bool $partial): void
    {
        $order = Order::query()->findOrFail($st->int('order'));
        $keeper = $this->s->users['inventory'];
        $lines = [];
        foreach (OrderLine::query()->where('order_id', $order->id)->whereNull('removed_at')->get() as $i => $line) {
            $qty = Quantity::parse((string) $line->quantity);
            if ($partial) {
                if ($i > 0) {
                    continue;
                }
                $qty = max(1000, (int) (floor($qty / 2000) * 1000));
            }
            $lines[] = ['order_line_id' => $line->id, 'quantity_milli' => $qty];
        }
        $note = app(T00CreateDraft::class)->handle($keeper, $order, $lines, null, $this->key());
        app(T01ConfirmDelivery::class)->handle($keeper, $note->refresh(), [
            'delivered_on' => CarbonImmutable::now()->toDateString(), 'receiver_name' => Customer::query()->findOrFail($order->customer_id)->name,
            'note' => 'استلام من المعرض.',
        ], [Assets::pdf('dn-'.$order->number.'.pdf', 'Handover')], null, $this->key());
    }

    /**
     * Refund part of the collected money (the cancelled share) through the real refund request and payout.
     */
    private function refundUnits(Story $st, string $reason, float $share): void
    {
        $order = Order::query()->findOrFail($st->int('order'))->refresh();
        $payment = Payment::query()->where('order_id', $order->id)->orderBy('id')->first();
        if ($payment === null) {
            return;
        }
        $amount = Money::of((int) round($order->total_minor * $share));
        $manager = $this->s->users['gm'];
        $refund = app(T00RequestRefund::class)->handle($manager, $order, [
            'payment_id' => $payment->id, 'amount' => $amount->toInput(), 'method' => 'transfer', 'reason' => $reason,
        ], FinanceLock::versionOf($order), $this->key());
        $st->data['refund'] = $refund->id;
        $this->s->timeline->at(CarbonImmutable::now()->addHours(30), $st, 'refund-pay', function (Story $st) {
            $refund = Refund::query()->findOrFail($st->int('refund'));
            app(T02ConfirmRefund::class)->handle($this->s->users['finance'], $refund, ['reference' => 'TRX'.$this->s->rng->int(10000000, 99999999)],
                Assets::pdf('refund-proof.pdf', 'Refund'), $refund->lock_version, $this->key());
        });
    }

    private function returnGoods(Story $st): void
    {
        $order = Order::query()->findOrFail($st->int('order'));
        $line = OrderLine::query()->where('order_id', $order->id)->whereNull('removed_at')->first();
        $manager = $this->s->users['gm'];
        $keeper = $this->s->users['inventory'];
        $ret = app(T00RequestReturn::class)->handle($manager, $order, [['order_line_id' => $line->id, 'quantity_milli' => 1000]], $this->s->rng->pick(['المقاس غير مناسب', 'اللون لا يطابق المطبخ', 'منتج مكرر اشتراه العميل مرتين']), $this->key());
        $ret = app(T05AcceptReturn::class)->handle($manager, $ret->refresh(), null, 'ضمن سياسة الإرجاع (14 يومًا).', null, $ret->lock_version, $this->key());
        $damaged = $st->data['outcome'] === 'return_damaged';
        $ret = app(T01ReceiveReturn::class)->handle($keeper, $ret->refresh(), $st->data['location'], [[
            'order_line_id' => $line->id, 'quantity_milli' => 1000, 'good_milli' => $damaged ? 0 : 1000, 'damaged_milli' => $damaged ? 1000 : 0, 'rejected_milli' => 0,
        ]], true, null, $ret->lock_version, $this->key());
        if (! $damaged) {
            $this->refundUnits($st, 'إرجاع قطعة سليمة ضمن مدة الإرجاع.', 0.35);
        }
    }
}
