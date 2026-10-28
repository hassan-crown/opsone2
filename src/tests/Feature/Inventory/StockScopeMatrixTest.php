<?php

/**
 * Scope matrix of the stock permissions (P-45..P-53, ADR-0017): a location is a ScopedRecord on its branch, a
 * facility-wide location is covered by facility grants only; P-48 alone shows quantities but not movements.
 */

use App\Access\Access;
use App\Access\Permission;
use App\Models\Inventory\StockLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/InventoryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->s = stockFixture();
    $this->lb = StockLocation::query()->create(['code' => 'B1', 'name' => ['ar' => 'مستودع ب', 'en' => 'Store B'], 'branch_id' => $this->s->f->org->b->id]);
});

dataset('stock permissions', ['P-46', 'P-47', 'P-48', 'P-49', 'P-50', 'P-51', 'P-53']);

it('covers locations by branch, region and facility grants', function (string $code) {
    $org = $this->s->f->org;
    $member = $org->member(null);
    grantOrders($member, $code, 'branches', [$org->a->id]);
    $regional = $org->member(null);
    grantOrders($regional, $code, 'region', [$org->north->id]);
    $facility = $org->member(null);
    grantOrders($facility, $code, 'tenant');
    $none = $org->member(null);

    expect(Access::can($member, $code, $this->s->la))->toBeTrue()
        ->and(Access::can($member, $code, $this->lb))->toBeFalse()
        ->and(Access::can($member, $code, $this->s->lc))->toBeFalse()
        ->and(Access::can($member, $code, $this->s->main))->toBeFalse()
        ->and(Access::can($regional, $code, $this->s->la))->toBeTrue()
        ->and(Access::can($regional, $code, $this->lb))->toBeTrue()
        ->and(Access::can($regional, $code, $this->s->lc))->toBeFalse()
        ->and(Access::can($regional, $code, $this->s->main))->toBeFalse()
        ->and(Access::can($facility, $code, $this->s->main))->toBeTrue()
        ->and(Access::can($facility, $code, $this->s->lc))->toBeTrue()
        ->and(Access::can($none, $code, $this->s->la))->toBeFalse();
})->with('stock permissions');

it('keeps P-45 (suppliers) facility-wide only', function () {
    $org = $this->s->f->org;
    $keeper = $org->member('inventory', [$org->a]);
    expect(Access::can($org->owner, Permission::P45))->toBeTrue()
        ->and(Access::can($this->s->f->sales, Permission::P45))->toBeFalse()
        ->and(Permission::P45->value)->toBe('P-45')
        ->and(Access::can($keeper, Permission::P45))->toBeTrue();
});

it('shows quantities to a sales grant but not the movement history (P-48 matrix note)', function () {
    stockReceive($this->s->la, 'material', $this->s->material->id, '5');
    $this->actingAs($this->s->f->sales)->getJson('/stock')->assertOk()->assertJsonCount(1, 'balances')->assertJsonPath('can.history', false)
        ->assertJsonPath('available_only', true)->assertJsonPath('balances.0.available', '5')
        ->assertJsonPath('balances.0.on_hand', null)->assertJsonPath('balances.0.reserved', null);
    $this->actingAs($this->s->keeper)->getJson('/stock')->assertJsonPath('balances.0.on_hand', '5')->assertJsonPath('available_only', false);
    $this->actingAs($this->s->f->sales)->getJson('/stock/movements')->assertForbidden();
    $this->actingAs($this->s->keeper)->getJson('/stock/movements')->assertOk()->assertJsonCount(1, 'movements');
});

it('narrows balances, movements and transfers to the covered locations', function () {
    stockReceive($this->s->la, 'material', $this->s->material->id, '5');
    stockReceive($this->s->lc, 'material', $this->s->material->id, '7');
    stockReceive($this->s->main, 'material', $this->s->material->id, '9');

    $codes = collect($this->actingAs($this->s->keeper)->getJson('/stock')->json('balances'))->pluck('location.code')->all();
    expect($codes)->toBe(['A1']);
    $all = collect($this->actingAs($this->s->f->org->owner)->getJson('/stock')->json('balances'))->pluck('location.code')->sort()->values()->all();
    expect($all)->toBe(['A1', 'C1', 'MAIN']);
    $this->actingAs($this->s->keeper)->getJson('/stock/movements?location_id='.$this->s->lc->id)->assertOk()->assertJsonCount(0, 'movements');
});

it('refuses adjustments, counts and material moves outside the grant', function () {
    stockReceive($this->s->lc, 'material', $this->s->material->id, '5');
    $this->actingAs($this->s->keeper)->postJson('/stock/adjustments', [
        'location_id' => $this->s->lc->id, 'item_type' => 'material', 'item_id' => $this->s->material->id,
        'direction' => 'out', 'quantity' => '1', 'reason' => 'خارج النطاق',
    ])->assertForbidden();
    $this->actingAs($this->s->keeper)->postJson('/stock/counts', [
        'location_id' => $this->s->lc->id, 'reason' => 'جرد',
        'lines' => [['item_type' => 'material', 'item_id' => $this->s->material->id, 'counted' => '1']],
    ])->assertForbidden();
    expect(stockBalance($this->s->lc, 'material', $this->s->material->id)['on_hand'])->toBe('5');
});

it('manages locations with P-49 in the branch; facility-wide ones need a facility grant', function () {
    $body = fn (?int $branch, string $code) => ['code' => $code, 'name' => ['ar' => 'موقع', 'en' => 'Spot'], 'branch_id' => $branch];
    $this->actingAs($this->s->keeper)->postJson('/stock/locations', $body($this->s->f->org->a->id, 'A3'))->assertCreated();
    $this->actingAs($this->s->keeper)->postJson('/stock/locations', $body($this->s->f->org->c->id, 'C3'))->assertForbidden();
    $this->actingAs($this->s->keeper)->postJson('/stock/locations', $body(null, 'X1'))->assertForbidden();
    $this->actingAs($this->s->f->org->owner)->postJson('/stock/locations', $body(null, 'X1'))->assertCreated();
    $this->actingAs($this->s->f->org->owner)->postJson('/stock/locations', $body(null, 'x1'))->assertUnprocessable();

    $this->actingAs($this->s->f->org->owner)->putJson("/stock/locations/{$this->s->main->id}", $body(null, 'MAIN') + ['status' => 'disabled'])->assertUnprocessable();
    stockReceive($this->s->la, 'material', $this->s->material->id, '1');
    $this->actingAs($this->s->keeper)->putJson("/stock/locations/{$this->s->la->id}", $body($this->s->f->org->a->id, 'A1') + ['status' => 'disabled'])->assertUnprocessable();
});

it('changes enabled units with P-02 only and keeps a unit in use', function () {
    stockReceive($this->s->la, 'material', $this->s->material->id, '1');
    $this->actingAs($this->s->keeper)->putJson('/stock/settings', ['units' => ['piece'], 'low_stock_alerts' => true])->assertForbidden();
    $this->actingAs($this->s->f->org->owner)->putJson('/stock/settings', ['units' => ['piece', 'sheet'], 'low_stock_alerts' => false])->assertUnprocessable();
    $this->actingAs($this->s->f->org->owner)->putJson('/stock/settings', ['units' => ['meter', 'sheet'], 'low_stock_alerts' => false])
        ->assertOk()->assertJsonPath('settings.units', ['piece', 'meter', 'sheet']);
});
