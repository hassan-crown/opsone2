<?php

/**
 * CancelReview over HTTP and the scope matrix of P-18 (ADR-0020): who may review and decide a cancellation on which
 * order, amounts hidden without P-37 (keys absent, never 0), a stale confirm answered 409, and the entry point on the
 * order page.
 */

use App\Models\Cancellation\OrderCancellation;
use App\Models\Settlement\SettlementItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/CancellationTestHelpers.php';
require_once __DIR__.'/../Procurement/ProcurementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
});

it('applies P-18 by scope: owner and branch manager decide, other branches and roles are refused', function () {
    $f = $this->f;
    $org = $f->org;
    finAgree($f, '10000');
    $managerB = $org->member('manager', [$org->b]);
    $url = "/orders/{$f->order->id}/cancellation/preview";

    foreach ([[$f->owner, 200], [$f->manager, 200], [$managerB, 403], [$f->sales, 403], [$f->finance, 403], [$f->supervisor, 403], [$f->executor, 403]] as [$user, $status]) {
        $this->actingAs($user)->postJson($url, ['scope' => 'full'])->assertStatus($status);
        $this->actingAs($user)->get("/orders/{$f->order->id}/cancellation")->assertStatus($status);
    }

    cnRefused(fn () => cnCancel($managerB, $f->order, ['scope' => 'full']), AuthorizationException::class);
    cnRefused(fn () => cnCancel($f->sales, $f->order, ['scope' => 'full']), AuthorizationException::class);
    expect(cnCancel($f->manager, $f->order, ['scope' => 'full'])->status->value)->toBe('accepted');
});

it('reviews and confirms over HTTP, answering 409 for a stale review', function () {
    $f = $this->f;
    finAgree($f, '10000');
    finPay($f->owner, $f->order, '4000');
    $this->actingAs($f->owner);

    $preview = $this->postJson("/orders/{$f->order->id}/cancellation/preview", ['scope' => 'full', 'retained' => '1200'])->assertOk()
        ->assertJsonPath('impact.settlement.money.refund_minor', 280000)
        ->assertJsonPath('impact.order.status_after', 'cancelled')
        ->assertJsonMissingPath('impact.content')->json();

    finPay($f->owner, $f->order, '500');
    $body = [...cnRequest(), 'scope' => 'full', 'retained' => '1200', 'hash' => $preview['hash'], 'lock_version' => $f->order->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()];
    $this->postJson("/orders/{$f->order->id}/cancellation", $body)->assertStatus(409)->assertJsonPath('condition', 'preview_stale');

    $fresh = $this->postJson("/orders/{$f->order->id}/cancellation/preview", ['scope' => 'full', 'retained' => '1200'])->assertOk()->json();
    $body = [...$body, 'hash' => $fresh['hash'], 'idempotency_key' => (string) Str::uuid()];
    $this->postJson("/orders/{$f->order->id}/cancellation", $body)->assertCreated()
        ->assertJsonPath('order.status', 'cancelled')
        ->assertJsonPath('settlement.items.0.kind', 'refund')
        ->assertJsonPath('settlement.items.0.target_minor', 330000);
    // A replay with the same key returns the known decision without a second effect (M-34-T06).
    $this->postJson("/orders/{$f->order->id}/cancellation", $body)->assertSuccessful();
    expect(OrderCancellation::query()->count())->toBe(1);

    $this->get("/orders/{$f->order->id}")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('orders/show')->where('cancellation.settlement.status', 'open')->where('cancellation.can_review', false));
});

it('hides amounts from a reviewer without P-37 and from a viewer of the follow-up', function () {
    $f = $this->f;
    finAgree($f, '10000');
    finPay($f->owner, $f->order, '4000');
    procDeny($f->manager, 'P-37');

    $this->actingAs($f->manager)->postJson("/orders/{$f->order->id}/cancellation/preview", ['scope' => 'full'])->assertOk()
        ->assertJsonMissingPath('impact.settlement.money.refund_minor')
        ->assertJsonMissingPath('impact.stays.collected_minor')
        ->assertJsonPath('impact.order.status_after', 'cancelled');
    // Setting the kept value is an amount: refused without P-37.
    $this->actingAs($f->manager)->postJson("/orders/{$f->order->id}/cancellation/preview", ['scope' => 'full', 'retained' => '100'])->assertForbidden();

    cnCancel($f->owner, $f->order, ['scope' => 'full']);
    $this->actingAs($f->supervisor)->get("/orders/{$f->order->id}/cancellation")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('orders/cancel')
            ->where('can.review', false)->where('amounts_visible', false)
            ->where('settlement.items.0.kind', 'refund')->missing('settlement.items.0.target_minor')->where('settlement.items.0.can_resolve', false));
    $this->actingAs($f->owner)->get("/orders/{$f->order->id}/cancellation")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('settlement.items.0.target_minor', 400000)->where('settlement.items.0.can_resolve', true));
});

it('records, rejects and resolves over HTTP with the right permission', function () {
    $f = $this->f;
    finAgree($f, '10000');
    finPay($f->owner, $f->order, '1000');
    $this->actingAs($f->owner);

    $this->postJson("/orders/{$f->order->id}/cancellation/requests", [...cnRequest(), 'scope' => 'full', 'lock_version' => $f->order->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])
        ->assertCreated()->assertJsonPath('order.status', 'cancellation_pending');
    $request = OrderCancellation::query()->sole();
    $this->get("/orders/{$f->order->id}/cancellation")->assertInertia(fn (Assert $p) => $p->component('orders/cancel')->where('pending.number', $request->number));

    $this->postJson("/cancellations/{$request->id}/reject", ['reason' => 'x'])->assertUnprocessable();
    $this->postJson("/cancellations/{$request->id}/reject", ['reason' => 'العقد ساري والتصنيع جارٍ.', 'lock_version' => $request->lock_version])
        ->assertOk()->assertJsonPath('order.status', 'in_progress');
    $this->postJson("/cancellations/{$request->id}/reject", ['reason' => 'مرة ثانية'])->assertStatus(409);

    cnCancel($f->owner, $f->order, ['scope' => 'full']);
    $item = SettlementItem::query()->where('kind', 'refund')->sole();
    $this->actingAs($f->sales)->postJson("/settlement-items/{$item->id}/resolve", ['reason' => 'اتفقنا على رصيد', 'status' => 'dropped'])->assertForbidden();
    $this->actingAs($f->finance)->postJson("/settlement-items/{$item->id}/resolve", ['reason' => 'أبقى العميل المبلغ رصيدًا لطلب جديد.', 'status' => 'dropped', 'lock_version' => $item->lock_version])
        ->assertOk()->assertJsonPath('settlement.status', 'closed')->assertJsonPath('settlement.items.0.status', 'dropped');
});
