<?php

/**
 * Regressions from the P4 security review (customers, catalog, orders, branch transfer).
 */

use App\Actions\Catalog\SaveVariant;
use App\Audit\AuditAction;
use App\Catalog\MinorUnits;
use App\Enums\OrderKind;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Orders/OrderTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = ordersFixture();
});

it('does not let an own-scoped P-10 grant move a customer into any branch', function () {
    $org = $this->f->org;
    $seller = $org->member(null, [$org->a]);
    grantOrders($seller, 'P-10', 'own');
    $customer = Customer::factory()->create(['branch_id' => $org->a->id, 'created_by' => $seller->id]);

    $this->actingAs($seller)->getJson("/customers/{$customer->id}/branch-transfer?branch_id={$org->c->id}")->assertForbidden();
    $this->actingAs($seller)->postJson("/customers/{$customer->id}/branch-transfer", [
        'branch_id' => $org->c->id, 'reason' => 'نقل بلا صلاحية فرع',
    ])->assertForbidden();

    expect($customer->refresh()->branch_id)->toBe($org->a->id);
});

it('still moves a customer for a branch-scoped P-10 holder of both branches', function () {
    $org = $this->f->org;
    $mover = $org->member(null, [$org->a, $org->b]);
    grantOrders($mover, 'P-10', 'branches', [$org->a->id, $org->b->id]);

    $this->actingAs($mover)->postJson("/customers/{$this->f->inA->id}/branch-transfer", [
        'branch_id' => $org->b->id, 'reason' => 'العميل انتقل',
    ])->assertOk();

    expect($this->f->inA->refresh()->branch_id)->toBe($org->b->id);
});

it('hides order amounts in the audit detail from a viewer without P-37', function () {
    $org = $this->f->org;
    $this->actingAs($org->owner)->postJson('/orders', readyPayload($this->f->inA, $this->f->variant))->assertCreated();
    $event = AuditEvent::query()->where('action', AuditAction::OrderCreated->value)->latest('id')->firstOrFail();
    expect($event->after['total_minor'])->toBe(9000);

    $auditor = $org->member(null, [$org->a]);
    grantOrders($auditor, 'P-63', 'branches', [$org->a->id]);
    $this->actingAs($auditor)->get('/org/audit?event='.$event->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected.id', $event->id)
            ->where('selected.after.total_minor', '[redacted]')
            ->where('selected.after.number', lastOrder()->number));

    $financeAuditor = $org->member(null, [$org->a]);
    grantOrders($financeAuditor, 'P-63', 'branches', [$org->a->id]);
    grantOrders($financeAuditor, 'P-37', 'branches', [$org->a->id]);
    $this->actingAs($financeAuditor)->get('/org/audit?event='.$event->id)
        ->assertInertia(fn (Assert $page) => $page->where('selected.after.total_minor', 9000));
});

it('refuses a ready line or order total beyond the largest amount instead of saturating', function () {
    $owner = $this->f->org->owner;
    app(SaveVariant::class)->handle($owner, $this->f->variant->product, $this->f->variant, [
        'code' => $this->f->variant->code, 'name' => ['ar' => 'أسود', 'en' => null], 'price_minor' => MinorUnits::MAX, 'is_default' => false,
    ]);

    $this->actingAs($owner)->postJson('/orders', readyPayload($this->f->inA, $this->f->variant, 2))
        ->assertJsonValidationErrors('ready.lines.0.quantity');

    // Each line fits, the sum does not.
    $payload = readyPayload($this->f->inA, $this->f->variant, 1);
    $payload['ready']['lines'][] = $payload['ready']['lines'][0];
    $this->actingAs($owner)->postJson('/orders', $payload)->assertJsonValidationErrors('ready.lines');

    expect(Order::query()->count())->toBe(0);
});

it('refuses quantities that would be rounded to zero or written in exponent form', function (string $quantity) {
    $payload = customPayload($this->f->inA);
    $payload['custom']['lines'][0]['quantity'] = $quantity;

    $this->actingAs($this->f->sales)->postJson('/orders', $payload)->assertJsonValidationErrors('custom.lines.0.quantity');
})->with(['0.0001', '1e3', '-1', '0']);

it('accepts a decimal quantity with up to three places', function () {
    $payload = customPayload($this->f->inA);
    $payload['custom']['lines'][0]['quantity'] = '2.125';

    $this->actingAs($this->f->sales)->postJson('/orders', $payload)->assertCreated();
    expect(lastOrder()->lines()->first()->quantity)->toBe('2.125');
});

it('replays an order create without showing an order the actor can no longer see', function () {
    $org = $this->f->org;
    $payload = customPayload($this->f->inA);
    $this->actingAs($this->f->sales)->postJson('/orders', $payload)->assertCreated();
    $order = lastOrder();

    $manager = $org->member('manager', [$org->a, $org->b]);
    $salesB = $org->member('sales', [$org->b]);
    $this->actingAs($manager)->postJson("/orders/{$order->id}/branch-transfer", [
        'branch_id' => $org->b->id, 'reason' => 'نقل للفرع ب', 'assignee_id' => $salesB->id, 'lock_version' => 1,
    ])->assertOk();

    $this->actingAs($this->f->sales)->postJson('/orders', $payload)
        ->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('orders', []);
});

it('keeps the number, kind, customer and creator of an order fixed', function (string $field) {
    $this->actingAs($this->f->sales)->postJson('/orders', customPayload($this->f->inA))->assertCreated();
    $order = lastOrder();

    $value = match ($field) {
        'number' => 'ORD-1999-00001',
        'kind' => OrderKind::Ready,
        'customer_id' => $this->f->inB->id,
        'created_by' => $this->f->org->owner->id,
    };
    $order->forceFill([$field => $value]);

    expect(fn () => $order->save())->toThrow(LogicException::class);
})->with(['number', 'kind', 'customer_id', 'created_by']);

it('rate limits the P4 create and apply endpoints', function (string $name) {
    expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->toContain('throttle:records-create');
})->with([
    'customers.store', 'customers.contacts.store', 'interests.store', 'interests.convert',
    'orders.lines.store', 'orders.branch-transfer.apply', 'customers.branch-transfer.apply',
    'catalog.products.store', 'catalog.materials.store', 'catalog.ready.store', 'catalog.ready.variants.store',
]);

it('stops a script creating customers past the per-user limit', function () {
    $org = $this->f->org;
    $status = null;
    for ($i = 0; $i < 61; $i++) {
        $status = $this->actingAs($org->owner)->post('/customers', [
            'kind' => 'person', 'name' => 'عميل '.$i, 'mobile' => '05'.str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT),
            'branch_id' => $org->a->id,
        ])->status();
    }

    expect($status)->toBe(429);
});
