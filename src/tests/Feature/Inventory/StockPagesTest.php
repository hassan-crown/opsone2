<?php

/**
 * The stock screens render for a facility-wide holder and are refused without any stock grant.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->s = stockFixture();
    stockReceive($this->s->la, 'material', $this->s->material->id, '2');
});

it('renders every stock screen', function () {
    $count = $this->actingAs($this->s->keeper)->postJson('/stock/counts', [
        'location_id' => $this->s->la->id, 'reason' => 'جرد',
        'lines' => [['item_type' => 'material', 'item_id' => $this->s->material->id, 'counted' => '2']],
    ])->assertCreated()->json('count');
    $this->actingAs($this->s->f->sales)->postJson('/orders', customPayload($this->s->f->inA))->assertCreated();
    $order = lastOrder();

    foreach ([
        '/stock' => 'stock/index', '/stock/movements' => 'stock/movements', '/stock/transfers' => 'stock/transfers',
        '/stock/counts' => 'stock/counts', "/stock/counts/{$count['id']}" => 'stock/count', '/stock/ready' => 'stock/ready',
        '/stock/locations' => 'stock/locations', "/orders/{$order->id}/materials" => 'orders/materials',
    ] as $url => $component) {
        $this->actingAs($this->s->f->org->owner)->get($url)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component($component));
    }
    $this->actingAs($this->s->f->org->owner)->get("/orders/{$order->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('stock.kind', 'custom')->where('stock.can.materials', true));
});

it('refuses the stock screens without a stock grant and hides the menu entry', function () {
    $executor = $this->s->f->org->member('executor', [$this->s->f->org->a]);
    foreach (['/stock', '/stock/movements', '/stock/transfers', '/stock/counts', '/stock/locations'] as $url) {
        $this->actingAs($executor)->get($url)->assertForbidden();
    }
    $this->actingAs($this->s->keeper)->get('/stock')->assertInertia(fn (AssertableInertia $p) => $p->where('orgNav.stock', true));
});
