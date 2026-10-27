<?php

/**
 * The order screens (Inertia pages): authorization per record, money absent from the props without P-37, the create
 * flow end to end over HTTP (client idempotency key, double submit, transfer) and the stale-version conflict shape.
 */

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/OrderTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = ordersFixture();
    $owner = $this->f->org->owner;
    $this->actingAs($owner)->postJson('/orders', readyPayload($this->f->inA, $this->f->variant, 2))->assertCreated();
    $this->orderA = lastOrder();
    $this->actingAs($owner)->postJson('/orders', customPayload($this->f->inC))->assertCreated();
    $this->orderC = lastOrder();
});

describe('pages authorization', function () {
    it('shows the list only within scope', function () {
        $this->actingAs($this->f->sales)->get('/orders')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('orders/index')
            ->has('orders', 1)
            ->where('orders.0.id', $this->orderA->id)
            ->where('canCreate', true));
    });

    it('refuses the list, the create screen and the pickers to a user without the grants', function () {
        $executor = $this->f->org->member('executor', [$this->f->org->a]);

        $this->actingAs($executor)->get('/orders')->assertForbidden();
        $this->actingAs($executor)->get('/orders/create')->assertForbidden();
        $this->actingAs($executor)->getJson('/orders/customers')->assertForbidden();
        $this->actingAs($executor)->getJson('/orders/catalog?kind=custom&branch_id='.$this->f->org->a->id)->assertForbidden();
        auth()->logout();
        $this->get('/orders')->assertRedirect('/login');
    });

    it('opens an in-scope order, refuses an out-of-scope or unauthorized one', function () {
        $executor = $this->f->org->member('executor', [$this->f->org->a]);

        $this->actingAs($this->f->sales)->get("/orders/{$this->orderA->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('orders/show')->where('order.id', $this->orderA->id)->has('history')->has('transferBranches'));
        $this->actingAs($this->f->sales)->get("/orders/{$this->orderC->id}")->assertForbidden();
        $this->actingAs($executor)->get("/orders/{$this->orderA->id}")->assertForbidden();
    });

    it('still serves the JSON contract to JSON clients', function () {
        $this->actingAs($this->f->sales)->getJson("/orders/{$this->orderA->id}")->assertOk()->assertJsonPath('order.id', $this->orderA->id);
        $this->actingAs($this->f->sales)->getJson('/orders')->assertOk()->assertJsonCount(1, 'orders');
    });

    it('offers transfer targets only to a user holding P-19', function () {
        $manager = $this->f->org->member('manager', [$this->f->org->a, $this->f->org->b]);

        $this->actingAs($this->f->sales)->get("/orders/{$this->orderA->id}")->assertInertia(fn (Assert $page) => $page->where('order.can.transfer_branch', false)->where('transferBranches', []));
        $this->actingAs($manager)->get("/orders/{$this->orderA->id}")->assertInertia(fn (Assert $page) => $page
            ->where('order.can.transfer_branch', true)
            ->where('transferBranches', fn ($branches) => collect($branches)->pluck('id')->doesntContain($this->orderA->branch_id) && count($branches) >= 1));
    });
});

describe('financial fields', function () {
    it('omits money from the props without P-37 and says so', function () {
        $inventory = $this->f->org->member('inventory', [$this->f->org->a]);

        $this->actingAs($inventory)->get("/orders/{$this->orderA->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('order.financials_hidden', true)
            ->missing('order.total_minor')
            ->missing('order.lines.0.unit_price_minor')
            ->missing('order.lines.0.line_total_minor')
            ->missing('order.lines.0.snapshot.item.price_minor'));
        $this->actingAs($inventory)->get('/orders')->assertInertia(fn (Assert $page) => $page
            ->where('orders.0.financials_hidden', true)->missing('orders.0.total_minor'));
    });

    it('includes money with P-37', function () {
        $this->actingAs($this->f->sales)->get("/orders/{$this->orderA->id}")->assertInertia(fn (Assert $page) => $page
            ->where('order.financials_hidden', false)
            ->where('order.total_minor', 9000)
            ->where('order.lines.0.unit_price_minor', 4500));
    });

    it('keeps catalog prices out of the line picker without P-37', function () {
        $seller = $this->f->org->member(null, [$this->f->org->a]);
        grantOrders($seller, 'P-15', 'branches', [$this->f->org->a->id]);
        $a = $this->f->org->a->id;

        $items = $this->actingAs($seller)->getJson("/orders/catalog?kind=ready&branch_id={$a}")->assertOk()->json('items');
        expect($items)->not->toBeEmpty()->and($items[0])->toHaveKeys(['id', 'name', 'version'])->not->toHaveKey('price_minor');

        $withMoney = $this->actingAs($this->f->sales)->getJson("/orders/catalog?kind=ready&branch_id={$a}")->assertOk()->json('items.0');
        expect($withMoney['price_minor'])->toBe(4500);
    });
});

describe('create screen', function () {
    it('renders with branches and members and pre-selects a visible customer only', function () {
        $a = $this->f->org->a->id;

        $this->actingAs($this->f->sales)->get('/orders/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('orders/create')
            ->where('options.branches.custom.0.id', $a)
            ->where('preselectedCustomer', null));
        $this->actingAs($this->f->sales)->get('/orders/create?customer='.$this->f->inA->id)->assertInertia(fn (Assert $page) => $page
            ->where('preselectedCustomer.id', $this->f->inA->id));
        $this->actingAs($this->f->sales)->get('/orders/create?customer='.$this->f->inC->id)->assertInertia(fn (Assert $page) => $page
            ->where('preselectedCustomer', null));
    });

    it('searches only customers the user may see', function () {
        $ids = collect($this->actingAs($this->f->sales)->getJson('/orders/customers')->assertOk()->json('customers'))->pluck('id')->all();

        expect($ids)->toContain($this->f->inA->id)->not->toContain($this->f->inC->id);
    });

    it('offers selectable catalog items with the version a line would freeze', function () {
        $a = $this->f->org->a->id;

        $custom = $this->actingAs($this->f->sales)->getJson("/orders/catalog?kind=custom&branch_id={$a}")->assertOk()->json('items.0');
        expect($custom['id'])->toBe($this->f->product->id)->and($custom['version'])->toBe(1)->and($custom['type'])->toBe('custom_product');

        $this->actingAs($this->f->sales)->getJson('/orders/catalog?kind=custom&branch_id='.$this->f->org->c->id)->assertForbidden();
    });

    it('opens an order end to end and replays the same client key without a second order', function () {
        $before = Order::query()->count();
        $payload = customPayload($this->f->inA, ['idempotency_key' => 'client-form-0001']);

        $first = $this->actingAs($this->f->sales)->postJson('/orders', $payload)->assertCreated();
        $second = $this->actingAs($this->f->sales)->postJson('/orders', $payload)->assertOk();

        expect(Order::query()->count())->toBe($before + 1)
            ->and($second->json('replayed'))->toBeTrue()
            ->and($second->json('orders.0.id'))->toBe($first->json('orders.0.id'));

        $this->actingAs($this->f->sales)->get('/orders/'.$first->json('orders.0.id'))->assertInertia(fn (Assert $page) => $page
            ->where('order.status', 'in_progress')->where('order.kind', 'custom')->where('order.lines.0.type', 'custom_need'));
    });

    it('reports field errors keyed for the screen when the form is invalid', function () {
        $this->actingAs($this->f->sales)->postJson('/orders', customPayload($this->f->inA, ['custom' => ['need_summary' => '']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['custom.need_summary']);
    });
});

describe('conflict response shape', function () {
    it('answers a stale edit and a stale line removal with 409 stale_version and the current version', function () {
        $order = $this->orderA;
        $line = $order->lines()->firstOrFail();

        $this->actingAs($this->f->sales)->putJson("/orders/{$order->id}", ['lock_version' => 99, 'invoice_type' => 'tax'])
            ->assertStatus(409)->assertExactJson(['code' => 'stale_version', 'message' => __('orders.stale'), 'lock_version' => $order->refresh()->lock_version]);
        $this->actingAs($this->f->sales)->deleteJson("/orders/{$order->id}/lines/{$line->id}", ['lock_version' => 99])
            ->assertStatus(409)->assertJsonPath('code', 'stale_version')->assertJsonPath('lock_version', $order->lock_version);
        expect($order->refresh()->invoice_type)->toBeNull()->and($line->refresh()->isRemoved())->toBeFalse();
    });
});

describe('branch transfer screen', function () {
    it('previews the impact and applies with the previewed version; a stale one conflicts', function () {
        $manager = $this->f->org->member('manager', [$this->f->org->a, $this->f->org->b]);
        $order = $this->orderA;
        $to = $this->f->org->b->id;

        $preview = $this->actingAs($manager)->getJson("/orders/{$order->id}/branch-transfer?branch_id={$to}")->assertOk();
        $version = $preview->json('lock_version');

        $this->actingAs($manager)->postJson("/orders/{$order->id}/branch-transfer", ['branch_id' => $to, 'reason' => 'سبب النقل', 'assignee_id' => $manager->id, 'lock_version' => $version + 5])
            ->assertStatus(409)->assertJsonPath('code', 'stale_version');
        $this->actingAs($manager)->postJson("/orders/{$order->id}/branch-transfer", ['branch_id' => $to, 'reason' => 'الفرع الأقرب للعميل', 'assignee_id' => $manager->id, 'lock_version' => $version])
            ->assertOk();
        expect($order->refresh()->branch_id)->toBe($to);
    });

    it('requires a reason', function () {
        $manager = $this->f->org->member('manager', [$this->f->org->a, $this->f->org->b]);

        $this->actingAs($manager)->postJson("/orders/{$this->orderA->id}/branch-transfer", ['branch_id' => $this->f->org->b->id, 'lock_version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    });
});

it('lists the orders entry in the shared navigation only for users who can open orders', function () {
    $executor = $this->f->org->member('executor', [$this->f->org->a]);

    $this->actingAs($this->f->sales)->get('/orders')->assertInertia(fn (Assert $page) => $page->where('orgNav.orders', true));
    $this->actingAs($executor)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('orgNav.orders', false));
});
