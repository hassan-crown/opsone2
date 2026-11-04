<?php

/**
 * Where an order stands (UI batch B): the stage column of the order list, the header's «current stage → next» strip
 * and the home «orders needing attention» card, all from the active stage and its due date (OrderStage::daysLate).
 */

use App\Models\OrderStage;
use App\Models\OrderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = workflowFixture();
    $this->order = $this->f->order;
    $this->owner = $this->f->org->owner;
    $this->active = OrderStage::query()->findOrFail(OrderWorkflow::query()->where('order_id', $this->order->id)->value('active_stage_id'));
});

function makeLate(OrderStage $stage, int $days): void
{
    $stage->forceFill(['started_at' => now()->subDays($days + 2), 'target_days' => 2, 'expected_on' => null])->save();
}

it('counts the days a stage is past its due date, and none without a target', function () {
    $this->active->forceFill(['started_at' => now()->subDays(10), 'target_days' => null, 'expected_on' => null])->save();
    expect($this->active->refresh()->daysLate())->toBe(0);

    makeLate($this->active, 4);
    expect($this->active->refresh()->daysLate())->toBe(4);

    // The workshop's date wins over started + target days.
    $this->active->forceFill(['expected_on' => now()->addDay()->toDateString()])->save();
    expect($this->active->refresh()->daysLate())->toBe(0);
});

it('shows the current stage of each order in the list', function () {
    makeLate($this->active, 3);

    $this->actingAs($this->owner)->get('/orders')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('orders/index')
            ->where('orders.0.id', $this->order->id)
            ->where('orders.0.stage.key', $this->active->key)
            ->where('orders.0.stage.days_late', 3));
});

it('gives the order header its current and next stage', function () {
    $this->actingAs($this->owner)->get("/orders/{$this->order->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('orders/show')
            ->where('stage.key', $this->active->key)
            ->where('stage.position', 1)
            ->where('stage.total', OrderStage::query()->where('order_id', $this->order->id)->count())
            ->where('stage.next', fn ($next) => is_string($next) && $next !== ''));
});

it('lists late orders on the home card, scoped to what the user may see', function () {
    $this->actingAs($this->owner)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('ordersAttention.count', 0)->where('canExecutive', true));

    makeLate($this->active, 5);

    $this->actingAs($this->owner)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ordersAttention.count', 1)
            ->where('ordersAttention.items.0.number', $this->order->number)
            ->where('ordersAttention.items.0.days_late', 5));

    // A salesperson of another branch does not see the order, so it is not counted for them.
    $other = $this->f->org->member('sales', [$this->f->org->b]);
    $this->actingAs($other)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('ordersAttention.count', 0)->where('canExecutive', false));
});
