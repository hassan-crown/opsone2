<?php

/**
 * Scope matrix for orders per permission (in scope / out of scope / unauthorized), the ready-goods license gate at
 * record level (ADR-0005 note 8), and money hidden from the payload without P-37.
 */

use App\Access\Access;
use App\Access\Authorizer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LicenseFactory;

require_once __DIR__.'/OrderTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = ordersFixture();
    $owner = $this->f->org->owner;
    $this->actingAs($owner)->postJson('/orders', readyPayload($this->f->inA, $this->f->variant, 2))->assertCreated();
    $this->orderA = lastOrder();
    $this->actingAs($owner)->postJson('/orders', customPayload($this->f->inB))->assertCreated();
    $this->orderB = lastOrder();
    $this->actingAs($owner)->postJson('/orders', customPayload($this->f->inC))->assertCreated();
    $this->orderC = lastOrder();
});

describe('P-16 view', function () {
    it('opens in-scope orders, refuses out-of-scope and unauthorized ones', function () {
        $executor = $this->f->org->member('executor', [$this->f->org->a]);

        $this->actingAs($this->f->sales)->getJson("/orders/{$this->orderA->id}")->assertOk();
        $this->actingAs($this->f->sales)->getJson("/orders/{$this->orderC->id}")->assertForbidden();
        $this->actingAs($executor)->getJson("/orders/{$this->orderA->id}")->assertForbidden();
        $this->actingAs($executor)->getJson('/orders')->assertForbidden();
    });

    it('lists exactly what a direct open allows', function () {
        $ids = fn ($user) => collect($this->actingAs($user)->getJson('/orders')->assertOk()->json('orders'))->pluck('id')->sort()->values()->all();

        $region = $this->f->org->member('manager', [$this->f->org->a], null, 'region');
        $tenant = $this->f->org->member('sales', [$this->f->org->a], null, 'tenant');

        expect($ids($this->f->sales))->toBe([$this->orderA->id])
            ->and($ids($region))->toBe([$this->orderA->id, $this->orderB->id])
            ->and($ids($tenant))->toBe([$this->orderA->id, $this->orderB->id, $this->orderC->id]);
    });

    it('honours own and assigned scopes', function () {
        $own = $this->f->org->member(null, [$this->f->org->a]);
        grantOrders($own, 'P-16', 'own');
        $assigned = $this->f->org->member(null, [$this->f->org->a]);
        grantOrders($assigned, 'P-16', 'assigned');
        Order::query()->whereKey($this->orderC->id)->update(['created_by' => $own->id]);
        Order::query()->whereKey($this->orderB->id)->update(['assignee_id' => $assigned->id]);

        expect(Access::can($own, 'P-16', $this->orderC->refresh()))->toBeTrue()
            ->and(Access::can($own, 'P-16', $this->orderA))->toBeFalse()
            ->and(Access::can($assigned, 'P-16', $this->orderB->refresh()))->toBeTrue()
            ->and(Access::can($assigned, 'P-16', $this->orderA))->toBeFalse();
        expect(collect($this->actingAs($own)->getJson('/orders')->json('orders'))->pluck('id')->all())->toBe([$this->orderC->id]);
    });
});

describe('P-15 create', function () {
    it('opens only in a branch inside the grant', function () {
        $this->actingAs($this->f->sales)->postJson('/orders', customPayload($this->f->inC, ['branch_id' => $this->f->org->c->id]))->assertForbidden();
        $this->actingAs($this->f->org->member('executor', [$this->f->org->a]))->postJson('/orders', customPayload($this->f->inA))->assertForbidden();
        expect(Order::query()->count())->toBe(3);

        $branches = $this->actingAs($this->f->sales)->getJson('/orders/options')->assertOk()->json('branches.custom');
        expect(collect($branches)->pluck('id')->all())->toBe([$this->f->org->a->id]);
    });
});

describe('P-17 edit', function () {
    it('edits in scope only, with no effect out of scope', function () {
        $this->actingAs($this->f->sales)->putJson("/orders/{$this->orderB->id}", ['lock_version' => 1, 'need_summary' => 'x'])->assertForbidden();
        expect($this->orderB->refresh()->need_summary)->not->toBe('x');

        $manager = $this->f->org->member('manager', [$this->f->org->b]);
        $this->actingAs($manager)->putJson("/orders/{$this->orderB->id}", ['lock_version' => 1, 'need_summary' => 'x'])->assertOk();
    });
});

describe('ready goods license gate', function () {
    it('blocks the ready part only, at record level, and keeps existing ready orders readable', function () {
        LicenseFactory::install(['capabilities' => ['branch_comparisons']]);
        Access::clearResolvedInstance(Authorizer::class);
        $owner = $this->f->org->owner;

        $this->actingAs($owner)->postJson('/orders', readyPayload($this->f->inA, $this->f->variant))->assertForbidden();
        $this->actingAs($owner)->postJson('/orders', customPayload($this->f->inA) + ['ready' => readyPayload($this->f->inA, $this->f->variant)['ready']])->assertForbidden();
        expect(Order::query()->count())->toBe(3);

        $this->actingAs($owner)->postJson('/orders', customPayload($this->f->inA))->assertCreated();
        $this->actingAs($owner)->postJson("/orders/{$this->orderA->id}/lines", [
            'lock_version' => 1, 'type' => 'ready_variant', 'variant_id' => $this->f->variant->id, 'quantity' => 1,
        ])->assertForbidden();
        $this->actingAs($owner)->getJson("/orders/{$this->orderA->id}")->assertOk();

        expect(Access::inspect($owner, 'P-15', new Order(['kind' => 'ready', 'branch_id' => $this->f->org->a->id]))->reason?->value)->toBe('license')
            ->and(Access::can($owner, 'P-19', $this->orderA))->toBeTrue()
            ->and($this->actingAs($owner)->getJson('/orders/options')->json('branches.ready'))->toBe([]);
    });
});

describe('financial fields', function () {
    it('leaves prices and totals out of the payload without P-37, and keeps them for holders', function () {
        $inventory = $this->f->org->member('inventory', [$this->f->org->a]);

        $hidden = $this->actingAs($inventory)->getJson("/orders/{$this->orderA->id}")->assertOk()->json('order');
        expect($hidden['financials_hidden'])->toBeTrue()
            ->and($hidden)->not->toHaveKey('total_minor')
            ->and($hidden['lines'][0])->not->toHaveKeys(['unit_price_minor', 'line_total_minor'])
            ->and($hidden['lines'][0]['snapshot']['item'])->not->toHaveKey('price_minor')
            ->and($hidden['lines'][0]['snapshot']['item'])->toHaveKey('code');
        $row = $this->actingAs($inventory)->getJson('/orders')->json('orders.0');
        expect($row)->not->toHaveKey('total_minor');

        $shown = $this->actingAs($this->f->sales)->getJson("/orders/{$this->orderA->id}")->assertOk()->json('order');
        expect($shown['financials_hidden'])->toBeFalse()
            ->and($shown['total_minor'])->toBe(9000)
            ->and($shown['lines'][0]['unit_price_minor'])->toBe(4500)
            ->and($shown['lines'][0]['snapshot']['item']['price_minor'])->toBe(4500);
    });
});
