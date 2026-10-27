<?php

/**
 * Opening orders (COM-08, COM-10, M-04-T00, M-05-T00): custom without price, ready as a draft at frozen prices,
 * a shared purchase reference when sold together, idempotent double submit, server-side checks of customer,
 * contact and assignee.
 */

use App\Actions\Catalog\SetCatalogStatus;
use App\Catalog\CatalogStatus;
use App\Enums\StateMachine;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\PurchaseReference;
use App\Models\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/OrderTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = ordersFixture();
});

it('opens a custom order in progress, without any price, numbered per year', function () {
    $response = $this->actingAs($this->f->sales)->postJson('/orders', customPayload($this->f->inA))->assertCreated();

    $order = lastOrder();
    expect($order->kind->value)->toBe('custom')
        ->and($order->status)->toBe('in_progress')
        ->and($order->total_minor)->toBeNull()
        ->and($order->number)->toBe('ORD-'.now()->format('Y').'-00001')
        ->and($order->assignee_id)->toBe($this->f->sales->id)
        ->and($order->needs_measurement)->toBeTrue()
        ->and($order->lines()->count())->toBe(1)
        ->and($order->lines()->first()->unit_price_minor)->toBeNull();

    $response->assertJsonPath('orders.0.total_minor', null)->assertJsonPath('orders.0.status', 'in_progress');

    $transition = StateTransition::query()->where('subject_type', $order->getMorphClass())->where('subject_id', $order->id)->firstOrFail();
    expect($transition->machine)->toBe(StateMachine::M04)
        ->and($transition->transition)->toBe('T00')
        ->and($transition->to_state)->toBe('in_progress');
    expect(AuditEvent::query()->where('action', 'order.created')->where('subject_type', $order->getMorphClass())->where('subject_id', $order->id)->exists())->toBeTrue();
});

it('creates a ready order as a draft with the variant price frozen on the line', function () {
    $this->actingAs($this->f->sales)->postJson('/orders', readyPayload($this->f->inA, $this->f->variant, 3))->assertCreated();

    $order = lastOrder();
    $line = $order->lines()->firstOrFail();
    expect($order->status)->toBe('draft')
        ->and($order->total_minor)->toBe(13500)
        ->and($line->unit_price_minor)->toBe(4500)
        ->and($line->line_total_minor)->toBe(13500)
        ->and($line->catalog_version_id)->not->toBeNull();
    expect(StateTransition::query()->where('subject_type', $order->getMorphClass())->where('subject_id', $order->id)->firstOrFail()->machine)->toBe(StateMachine::M05);
});

it('keeps a ready and a custom order of one sale apart, linked by one purchase reference', function () {
    $payload = customPayload($this->f->inA) + ['ready' => readyPayload($this->f->inA, $this->f->variant)['ready']];

    $response = $this->actingAs($this->f->sales)->postJson('/orders', $payload)->assertCreated();

    $orders = Order::query()->orderBy('id')->get();
    expect($orders)->toHaveCount(2)
        ->and($orders->pluck('kind')->map->value->all())->toBe(['custom', 'ready'])
        ->and($orders->pluck('purchase_reference_id')->unique()->count())->toBe(1)
        ->and($orders[0]->number)->not->toBe($orders[1]->number)
        ->and($orders[0]->total_minor)->toBeNull()
        ->and($orders[1]->total_minor)->toBe(9000);
    expect(PurchaseReference::query()->count())->toBe(1)
        ->and(PurchaseReference::query()->first()->getAttributes())->not->toHaveKey('total_minor');
    $response->assertJsonPath('orders.0.related_orders.0.id', $orders[1]->id);
});

it('opens a single order only once on a double submit and replays the first result', function () {
    $payload = customPayload($this->f->inA);

    $first = $this->actingAs($this->f->sales)->postJson('/orders', $payload)->assertCreated();
    $second = $this->actingAs($this->f->sales)->postJson('/orders', $payload)->assertOk();

    expect(Order::query()->count())->toBe(1)
        ->and($second->json('replayed'))->toBeTrue()
        ->and($second->json('orders.0.id'))->toBe($first->json('orders.0.id'));

    // The same key with other data is refused, and nothing new is opened.
    $this->actingAs($this->f->sales)->postJson('/orders', [...$payload, 'custom' => ['need_summary' => 'شيء آخر']])
        ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    expect(Order::query()->count())->toBe(1);
});

it('accepts the key from the Idempotency-Key header', function () {
    $payload = customPayload($this->f->inA);
    $key = $payload['idempotency_key'];
    unset($payload['idempotency_key']);

    $this->actingAs($this->f->sales)->postJson('/orders', $payload, ['Idempotency-Key' => $key])->assertCreated();
    $this->actingAs($this->f->sales)->postJson('/orders', $payload, ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('replayed', true);
    expect(Order::query()->count())->toBe(1);
});

it('rolls back everything — order, number and key — when a part fails', function () {
    $payload = customPayload($this->f->inA) + ['ready' => ['lines' => [['type' => 'ready_variant', 'variant_id' => 999999, 'quantity' => 1]]]];

    $this->actingAs($this->f->sales)->postJson('/orders', $payload)->assertUnprocessable();

    expect(Order::query()->count())->toBe(0)
        ->and(PurchaseReference::query()->count())->toBe(0)
        ->and(IdempotencyKey::query()->count())->toBe(0);

    // The key is free again, and the first committed number is still 00001.
    $this->actingAs($this->f->sales)->postJson('/orders', [...customPayload($this->f->inA), 'idempotency_key' => $payload['idempotency_key']])->assertCreated();
    expect(lastOrder()->number)->toEndWith('-00001');
});

it('refuses a customer, contact or assignee the facts do not allow', function () {
    // Customer in branch C is outside the sales grant (A only).
    $this->actingAs($this->f->sales)->postJson('/orders', customPayload($this->f->inC, ['branch_id' => $this->f->org->a->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('customer_id');

    // An executor of branch A has no P-16 by default... a member of branch C cannot see an order of branch A.
    $outsider = $this->f->org->member('sales', [$this->f->org->c]);
    $this->actingAs($this->f->sales)->postJson('/orders', customPayload($this->f->inA, ['assignee_id' => $outsider->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('custom.assignee_id');

    // A person customer has no contact persons.
    $this->actingAs($this->f->sales)->postJson('/orders', customPayload($this->f->inA, ['contact_id' => 1]))
        ->assertUnprocessable()->assertJsonValidationErrors('contact_id');

    expect(Order::query()->count())->toBe(0);
});

it('uses the primary contact of an organization customer by default', function () {
    $company = Customer::factory()->organization()->create(['branch_id' => $this->f->org->a->id]);
    $primary = CustomerContact::query()->create(['customer_id' => $company->id, 'name' => 'سالم', 'mobile' => '+966551110000', 'is_primary' => true, 'primary_of' => $company->id]);

    $this->actingAs($this->f->sales)->postJson('/orders', customPayload($company, ['custom' => ['need_summary' => 'x', 'invoice_type' => 'tax']]))->assertCreated();

    expect(lastOrder()->contact_id)->toBe($primary->id)
        ->and(lastOrder()->invoice_type)->toBe('tax');
});

it('refuses a disabled catalog item on a new line', function () {
    app(SetCatalogStatus::class)->handle($this->f->org->owner, $this->f->product, CatalogStatus::Disabled);

    $this->actingAs($this->f->sales)->postJson('/orders', customPayload($this->f->inA, ['custom' => [
        'need_summary' => 'x', 'lines' => [['type' => 'custom_product', 'product_id' => $this->f->product->id]],
    ]]))->assertUnprocessable()->assertJsonValidationErrors('custom.lines.0.product_id');
});
