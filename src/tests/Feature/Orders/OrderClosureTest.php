<?php

/**
 * M-04-T07 / M-05-T10 «إقفال شامل» and M-14-T04 blocking delivery remarks: the order closes only when money, refunds,
 * purchasing, delivery, returns and remarks are settled; the closure never forgives a debt, keeps the agreement and the
 * reason, and is authorized by P-25 (finished) or P-18 (cancelled).
 */

use App\Models\Delivery\DeliveryRemark;
use App\Models\Finance\OrderDue;
use App\Models\Order;
use App\Models\Settlement\SettlementFollowUp;
use App\Models\StateTransition;
use App\Orders\OrderClosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Delivery/DeliveryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->d = deliveryFixture(10, 2);
    $this->order = $this->d->order;
    $this->note = deliveryConfirm($this->d->keeper, deliveryDraft($this->d->keeper, $this->order, [deliveryLineId($this->order) => '2']));
    $this->order->refresh();
});

function closeOrder(object $t, $user, string $reason = 'اكتمل الطلب')
{
    return $t->actingAs($user)->postJson(route('orders.close', $t->order), ['reason' => $reason]);
}

function blockerKeys(Order $order): array
{
    return array_column(OrderClosure::blockers($order->refresh()), 'key');
}

it('closes a delivered ready order with a reason and keeps what was recorded (M-05-T10)', function () {
    expect($this->order->status)->toBe('delivered')->and(blockerKeys($this->order))->toBe([]);

    closeOrder($this, $this->d->owner)->assertOk()->assertJsonPath('order.status', 'closed');

    expect($this->order->refresh()->status)->toBe('closed')
        ->and(DB::table('order_closures')->where('order_id', $this->order->id)->count())->toBe(1)
        ->and(StateTransition::query()->where('machine', 'M-05')->where('transition', 'T10')->where('subject_id', $this->order->id)->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'order.closed')->count())->toBe(1)
        ->and($this->note->refresh()->status->value)->toBe('confirmed');
});

it('refuses a closure from a state the machine does not allow, twice, or without a reason', function () {
    $other = readyOrder($this->d->s, 1); // a draft: not closable
    $this->actingAs($this->d->owner)->postJson(route('orders.close', $other), ['reason' => 'مسودة'])->assertStatus(409);
    closeOrder($this, $this->d->owner, '')->assertUnprocessable();
    closeOrder($this, $this->d->owner, 'ab')->assertUnprocessable();
    expect($this->order->refresh()->status)->toBe('delivered');

    closeOrder($this, $this->d->owner)->assertOk();
    closeOrder($this, $this->d->owner)->assertStatus(409);
    expect(DB::table('order_closures')->count())->toBe(1);
});

it('needs P-25 for a finished order: a member without it is refused, with no effect', function () {
    closeOrder($this, $this->d->keeper)->assertForbidden();
    expect($this->order->refresh()->status)->toBe('delivered')->and(DB::table('order_closures')->count())->toBe(0);
});

it('a due still owed blocks the closure and a closure never forgives it (no debt dropped by changing the state)', function () {
    OrderDue::query()->create([
        'order_id' => $this->order->id, 'position' => 1, 'reference' => 'final', 'name' => ['ar' => 'دفعة', 'en' => 'Payment'], 'basis' => 'amount',
        'basis_value' => 5000, 'amount_minor' => 5000, 'due_event' => 'date', 'due_date' => now()->toDateString(),
    ]);
    expect(blockerKeys($this->order))->toBe(['money_owed']);

    closeOrder($this, $this->d->owner)->assertUnprocessable()->assertJsonValidationErrors(['order']);
    expect($this->order->refresh()->status)->toBe('delivered')
        ->and((int) OrderDue::query()->where('order_id', $this->order->id)->sum('amount_minor'))->toBe(5000);
});

it('an open settlement follow-up and an open remark each block it, and resolving lifts only its own blocker', function () {
    $followUp = SettlementFollowUp::query()->create(['order_id' => $this->order->id, 'status' => SettlementFollowUp::OPEN]);
    expect(blockerKeys($this->order))->toBe(['settlement_open']);
    $followUp->forceFill(['status' => SettlementFollowUp::CLOSED, 'closed_at' => now()])->save();

    $remark = $this->actingAs($this->d->keeper)->postJson(route('deliveries.remarks.store', $this->note), ['description' => 'خدش في الواجهة'])->assertCreated()->json('remark.id');
    expect(blockerKeys($this->order))->toBe(['remark_open']);
    closeOrder($this, $this->d->owner)->assertUnprocessable();

    $this->actingAs($this->d->keeper)->postJson(route('deliveries.remarks.resolve', $remark), ['resolution' => 'أُعيد الطلاء'])->assertOk();
    expect(blockerKeys($this->order))->toBe([]);
    closeOrder($this, $this->d->owner)->assertOk();
});

it('a remark needs P-52, a confirmed delivery and a description, and the delivery stays untouched', function () {
    $sales = $this->d->s->f->sales;

    $this->actingAs($sales)->postJson(route('deliveries.remarks.store', $this->note), ['description' => 'ملاحظة'])->assertForbidden();
    $this->actingAs($this->d->keeper)->postJson(route('deliveries.remarks.store', $this->note), ['description' => ''])->assertUnprocessable();
    expect(DeliveryRemark::query()->count())->toBe(0)->and($this->note->refresh()->status->value)->toBe('confirmed');
});

it('shows the closure card with the blockers to the closer and none to a member who cannot act', function () {
    $this->actingAs($this->d->owner)->get(route('orders.show', $this->order))->assertOk()
        ->assertInertia(fn ($p) => $p->where('closure.closable', true)->where('closure.can_close', true)->where('closure.blockers', []));
    $this->actingAs($this->d->s->f->sales)->get(route('orders.show', $this->order))
        ->assertInertia(fn ($p) => $p->where('closure.can_close', false));
});

it('holds several remarks on one order, each resolved on its own with who, when and how; closure waits for all (ADR-0035)', function () {
    $keeper = $this->d->keeper;
    $ids = [];
    foreach (['خدش في الواجهة', 'نقص مسمار تثبيت', 'تغليف ممزق'] as $text) {
        $ids[] = $this->actingAs($keeper)->postJson(route('deliveries.remarks.store', $this->note), ['description' => $text])->assertCreated()->json('remark.id');
    }
    expect(DeliveryRemark::query()->where('order_id', $this->order->id)->count())->toBe(3);

    // The 422 names every unresolved remark, and so does the order page.
    closeOrder($this, $this->d->owner)->assertUnprocessable()
        ->assertJsonPath('errors.order.0', __('order.closure.blocker.remark_open', ['count' => 3]))
        ->assertJsonCount(4, 'errors.order');
    $this->actingAs($this->d->owner)->get(route('orders.show', $this->order))
        ->assertInertia(fn ($p) => $p->has('closure.blockers.0.items', 3)->where('closure.blockers.0.items.0.id', $ids[0])->has('closure.remarks', 3));

    // Resolving one keeps the others open and records who, when and the resolution note.
    $this->actingAs($keeper)->postJson(route('deliveries.remarks.resolve', $ids[1]), ['resolution' => 'أُرسل المسمار'])->assertOk()
        ->assertJsonPath('remark.status', 'resolved')->assertJsonPath('remark.resolved_by', $keeper->name)->assertJsonPath('remark.resolution', 'أُرسل المسمار');
    $resolved = DeliveryRemark::query()->findOrFail($ids[1]);
    expect($resolved->resolved_by)->toBe($keeper->id)->and($resolved->resolved_at)->not->toBeNull()
        ->and(DeliveryRemark::query()->whereIn('id', [$ids[0], $ids[2]])->pluck('status')->unique()->all())->toBe(['open'])
        ->and(OrderClosure::blockers($this->order->refresh())[0]['details']['count'])->toBe(2);
    $this->actingAs($keeper)->postJson(route('deliveries.remarks.resolve', $ids[1]), ['resolution' => 'مرة ثانية'])->assertStatus(409);

    // The JSON list (the contract other clients read) puts open remarks first.
    $this->actingAs($keeper)->getJson(route('deliveries.remarks.index', $this->order))->assertOk()
        ->assertJsonPath('remarks.0.status', 'open')->assertJsonPath('remarks.2.status', 'resolved')->assertJsonPath('remarks.2.resolution', 'أُرسل المسمار');
    $this->actingAs($this->d->s->f->sales)->getJson(route('deliveries.remarks.index', $this->order))->assertForbidden();

    foreach ([$ids[0], $ids[2]] as $id) {
        $this->actingAs($keeper)->postJson(route('deliveries.remarks.resolve', $id), ['resolution' => 'عولجت'])->assertOk();
    }
    expect(blockerKeys($this->order))->toBe([]);
    closeOrder($this, $this->d->owner)->assertOk();
    expect(DB::table('audit_events')->where('action', 'delivery.remark_resolved')->count())->toBe(3);
});
