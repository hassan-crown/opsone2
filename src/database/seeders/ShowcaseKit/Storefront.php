<?php

namespace Database\Seeders\ShowcaseKit;

use App\Actions\StateMachines\M14Delivery\T00CreateDraft;
use App\Actions\StateMachines\M14Delivery\T01ConfirmDelivery;
use App\Inventory\Quantity;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Store\StoreCheckout;
use App\Models\Store\StoreLink;
use App\Store\CouponAdmin;
use App\Store\StoreCheckouts;
use App\Store\StoreLinks;
use App\Store\StoreSetup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The online store: launch and seasonal coupons, cart links issued by sales, and online checkouts paid through a
 * faked Moyasar test account (real order, reservation, gateway outcome, receipt and pick-up note); a few
 * abandoned checkouts are released when their reservation window ends.
 */
final class Storefront
{
    public function __construct(private readonly Showcase $s) {}

    public function setup(): void
    {
        Timeline::freeze(CarbonImmutable::parse('2026-04-25 11:00', 'Asia/Riyadh'));
        FakeMoyasar::install();
        app(StoreSetup::class)->save($this->s->users['owner'], [
            'enabled' => true, 'branch_id' => $this->s->branches['RUH']->id, 'assignee_id' => $this->s->users['sales_ruh1']->id,
            'reservation_minutes' => 30, 'link_hours_default' => 48, 'link_days_max' => 7,
        ], null);

        $coupons = app(CouponAdmin::class);
        $gm = $this->s->users['gm'];
        $coupons->save($gm, [
            'code' => 'LAUNCH15', 'title_ar' => 'خصم الإطلاق 15%', 'title_en' => 'Launch offer 15%', 'kind' => 'percent', 'value' => '15',
            'starts_at' => '2026-05-01 00:00', 'ends_at' => '2026-06-30 23:59', 'max_uses' => 200, 'max_uses_per_customer' => 1,
        ]);
        $coupons->save($gm, [
            'code' => 'NATIONAL96', 'title_ar' => 'عرض اليوم الوطني 96', 'title_en' => 'National Day 96 offer', 'kind' => 'percent', 'value' => '20',
            'starts_at' => '2026-09-18 00:00', 'ends_at' => '2026-09-28 23:59', 'min_subtotal' => '300', 'max_uses' => 150, 'max_uses_per_customer' => 2,
        ]);
        $coupons->save($gm, [
            'code' => 'WINTER10', 'title_ar' => 'خصم الشتاء 10%', 'title_en' => 'Winter 10% off', 'kind' => 'percent', 'value' => '10',
            'starts_at' => '2026-10-15 00:00', 'ends_at' => '2026-12-15 23:59', 'min_subtotal' => '250', 'max_uses_per_customer' => 3,
        ]);
        $coupons->save($gm, [
            'code' => 'FIRST50', 'title_ar' => 'خصم 50 ريال على أول طلب', 'title_en' => 'SAR 50 off your first order', 'kind' => 'amount', 'value' => '50',
            'starts_at' => '2026-07-01 00:00', 'min_subtotal' => '400', 'max_uses_per_customer' => 1,
        ]);
    }

    public function run(): void
    {
        $rng = $this->s->rng;
        $span = (int) $this->s->start->diffInDays($this->s->end);
        $orders = $this->s->n(42);
        for ($i = 0; $i < $orders; $i++) {
            $day = (int) floor(($span - 3) * sqrt($rng->int(1, 10000) / 10000));
            $when = $this->s->start->addDays(max(3, $day))->setTime($rng->int(8, 23), $rng->int(0, 59));
            $mode = $rng->weighted(['paid' => 78, 'abandoned' => 10, 'link' => 12]);
            $story = new Story('store-'.$i);
            $this->s->timeline->at($when, $story, 'checkout', fn (Story $st) => $this->checkout($st, $mode));
        }
    }

    private function couponFor(CarbonImmutable $now): ?string
    {
        $date = $now->toDateString();
        $candidates = [];
        if ($date <= '2026-06-30') {
            $candidates[] = 'LAUNCH15';
        }
        if ($date >= '2026-09-18' && $date <= '2026-09-28') {
            $candidates[] = 'NATIONAL96';
        }
        if ($date >= '2026-10-15') {
            $candidates[] = 'WINTER10';
        }
        if ($date >= '2026-07-01') {
            $candidates[] = 'FIRST50';
        }

        return $candidates !== [] && $this->s->rng->chance(45) ? $this->s->rng->pick($candidates) : null;
    }

    private function buyer(): Customer
    {
        $rng = $this->s->rng;
        if ($rng->chance(35)) {
            [$name] = $this->s->people->person();
            $customer = Customer::query()->create([
                'kind' => 'person', 'name' => $name, 'mobile' => $this->s->people->mobile(), 'email' => 'shop'.$rng->int(1, 99999999).'@mail-demo.test',
                'country' => 'SA', 'branch_id' => $this->s->branches['RUH']->id, 'portal_login_enabled' => true, 'preferred_channel' => 'whatsapp',
            ]);
            $this->s->leads->pool['RUH'][] = $customer->id;

            return $customer;
        }
        $id = $this->s->leads->customerFor('RUH');
        $customer = Customer::query()->findOrFail($id);
        $customer->forceFill(['portal_login_enabled' => true])->save();

        return $customer;
    }

    /**
     * @return array<int, int> variant id => quantity of 1–3 published accessories
     */
    private function basket(): array
    {
        $rng = $this->s->rng;
        $lines = [];
        foreach (array_slice($rng->shuffle($this->s->catalog->variants), 0, $rng->int(1, 3)) as $variant) {
            $lines[$variant->id] = $rng->weighted(['1' => 55, '2' => 30, '3' => 15]) + 0;
        }

        return $lines;
    }

    private function checkout(Story $st, string $mode): void
    {
        $customer = $this->buyer();
        $lines = $this->basket();
        $now = CarbonImmutable::now();
        $link = null;
        if ($mode === 'link') {
            $sales = $this->s->users[$this->s->rng->pick(['sales_ruh1', 'sales_ruh2'])];
            $payload = [];
            foreach ($lines as $vid => $qty) {
                $payload[] = ['variant_id' => $vid, 'quantity' => $qty];
            }
            $issued = app(StoreLinks::class)->issue($sales, ['lines' => $payload, 'note' => 'سلة مجهزة للعميل بعد زيارة المعرض']);
            $link = $issued['link'];
            Timeline::freeze($now->addHours(2));
        }
        $returnUrl = rtrim((string) config('app.url'), '/').'/ar/store/checkout/__REF__';
        $coupon = $mode === 'link' ? null : $this->couponFor($now);
        try {
            $result = app(StoreCheckouts::class)->start($customer, $link === null ? $lines : [], $link, $coupon, (string) Str::uuid(), $returnUrl);
        } catch (\Illuminate\Validation\ValidationException) {
            // The basket is below the coupon's minimum: the buyer pays the full price.
            $result = app(StoreCheckouts::class)->start($customer, $link === null ? $lines : [], $link, null, (string) Str::uuid(), $returnUrl);
        }
        /** @var StoreCheckout $checkout */
        $checkout = $result['checkout'];
        $st->data['checkout'] = $checkout->id;
        if ($mode === 'abandoned') {
            $this->s->timeline->at($now->addMinutes(45), $st, 'expire', function (Story $st) {
                $checkout = StoreCheckout::query()->findOrFail($st->data['checkout']);
                FakeMoyasar::expire($checkout);
                app(StoreCheckouts::class)->expireDue();
            });

            return;
        }
        $this->s->timeline->at(CarbonImmutable::now()->addMinutes($this->s->rng->int(3, 20)), $st, 'pay', function (Story $st) {
            $checkout = StoreCheckout::query()->findOrFail($st->data['checkout']);
            FakeMoyasar::pay($checkout);
            app(StoreCheckouts::class)->applyProviderResult($checkout->refresh());
            $st->data['order'] = $checkout->refresh()->order_id;
        });
        $this->s->timeline->at(CarbonImmutable::now()->addDays($this->s->rng->int(1, 3))->setTime(12, 0), $st, 'pickup', function (Story $st) {
            $order = Order::query()->findOrFail($st->data['order']);
            $keeper = $this->s->users['inventory'];
            $lines = [];
            foreach (OrderLine::query()->where('order_id', $order->id)->whereNull('removed_at')->get() as $line) {
                $lines[] = ['order_line_id' => $line->id, 'quantity_milli' => Quantity::parse((string) $line->quantity)];
            }
            $note = app(T00CreateDraft::class)->handle($keeper, $order, $lines, null, (string) Str::uuid());
            app(T01ConfirmDelivery::class)->handle($keeper, $note->refresh(), [
                'delivered_on' => CarbonImmutable::now()->toDateString(), 'receiver_name' => Customer::query()->findOrFail($order->customer_id)->name, 'note' => 'استلام من فرع الرياض بعد طلب إلكتروني.',
            ], [Assets::pdf('pickup-'.$order->number.'.pdf', 'Pickup')], null, (string) Str::uuid());
        });
    }
}
