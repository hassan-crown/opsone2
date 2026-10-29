<?php

/**
 * Scope, grant hiding and the branch-comparison gate for reports (SHR-12, SHR-13; P-60, P-61, P-37, P-82).
 */

use App\Access\Access;
use App\Access\Permission;
use App\Reports\Definitions\Support;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/ReportsTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
    finAgree($this->f, '18450');
    repOrderInB($this->f, '1000');
});

it('keeps people without P-60 out of every report screen', function () {
    $this->actingAs($this->f->executor)->getJson('/reports')->assertForbidden();
    repReport($this->f->executor, 'sales', status: 403);
    $this->actingAs($this->f->executor)->getJson('/reports/compare')->assertForbidden();
    $this->actingAs($this->f->executor)->getJson('/reports/exports')->assertForbidden();
});

it('hides every amount from a viewer without P-37 — keys absent, never 0 — and refuses money-only reports', function () {
    $sales = repReport($this->f->supervisor, 'sales');
    expect($sales['amounts_visible'])->toBeFalse()
        ->and(repMinorKeys($sales))->toBe([])
        ->and($sales['table']['rows'])->not->toBeEmpty()
        ->and(repMetrics($sales)['sales.orders_created'])->toBe(1);

    repReport($this->f->supervisor, 'refunds', status: 403);
    repReport($this->f->supervisor, 'collection', status: 403) ?: null;
    $home = $this->actingAs($this->f->supervisor)->getJson('/reports')->assertOk()->json();
    expect(repMinorKeys($home))->toBe([])
        ->and(collect($home['reports'])->pluck('key')->all())->not->toContain('refunds', 'collection', 'commissions');
});

it('shows money to finance within the branch scope only', function () {
    $sales = repReport($this->f->finance, 'sales');
    expect($sales['amounts_visible'])->toBeTrue()
        ->and(repMetrics($sales)['sales.agreed_gross_minor'])->toBe(1_845_000)
        ->and(array_column($sales['table']['rows'], 'branch'))->each->toBe(Support::branchName($this->f->org->a->id));
});

it('hides purchase cost without P-82 but keeps counts, quantities and the report itself', function () {
    $po = cnPurchaseFor($this->f->order);
    $po->forceFill(['ordered_at' => now()])->save();

    $manager = repReport($this->f->manager, 'procurement');
    expect(repMetrics($manager)['procurement.orders_placed'])->toBe(1);

    Access::flush();
    // Finance has no P-82 in the default role set; whoever lacks it never receives a cost key.
    $finance = repReport($this->f->finance, 'procurement');
    if (! Access::can($this->f->finance, Permission::P82)) {
        expect(repMinorKeys($finance))->toBe([])->and(repMetrics($finance)['procurement.orders_placed'])->toBe(1);
    }
});

it('branch comparison: rows equal each branch\'s own report, and amounts follow P-37', function () {
    $compare = $this->actingAs($this->f->owner)->getJson('/reports/compare')->assertOk()->json('report');
    $rows = collect($compare['table']['rows'])->keyBy('branch');
    $nameA = Support::branchName($this->f->org->a->id);
    $nameB = Support::branchName($this->f->org->b->id);
    expect($rows[$nameA]['orders_created'])->toBe(1)->and($rows[$nameA]['agreed_gross_minor'])->toBe(1_845_000)
        ->and($rows[$nameB]['agreed_gross_minor'])->toBe(100_000)
        ->and($rows[$nameA]['agreed_gross_minor'])->toBe(repMetrics(repReport($this->f->owner, 'sales', ['branch_ids' => [$this->f->org->a->id]]))['sales.agreed_gross_minor']);

    // A branch-A manager compares only what their scope covers: their own branch.
    $mine = $this->actingAs($this->f->manager)->getJson('/reports/compare')->assertOk()->json('report.table.rows');
    expect(array_column($mine, 'branch'))->toBe([$nameA]);
});

it('branch comparison disappears without the license capability while the basic reports stay available', function () {
    repLicense(['ready_goods']);

    $this->actingAs($this->f->owner)->getJson('/reports/compare')->assertForbidden();
    $this->actingAs($this->f->owner)->getJson('/reports')->assertOk()->assertJsonPath('compare', 'locked');
    expect(repMetrics(repReport($this->f->owner, 'sales'))['sales.orders_created'])->toBe(2);
    $this->actingAs($this->f->owner)->get('/reports')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('reports/index')->where('compare', 'locked'));
});

it('a user without P-61 sees no comparison entry (hidden), not a locked one, on a plan that has it', function () {
    $this->actingAs($this->f->finance)->getJson('/reports/compare')->assertForbidden();
    $this->actingAs($this->f->finance)->getJson('/reports')->assertOk()->assertJsonPath('compare', 'hidden');
});

it('rejects a reversed or oversized range', function () {
    $this->actingAs($this->f->owner)->getJson('/reports/sales?from=2026-10-10&to=2026-10-01')->assertUnprocessable();
    $this->actingAs($this->f->owner)->getJson('/reports/sales?from=2024-01-01&to=2026-10-01')->assertUnprocessable();
});

it('the store report needs the ready_goods capability', function () {
    repReport($this->f->owner, 'store');
    repLicense(['branch_comparisons']);
    repReport($this->f->owner, 'store', status: 403);
});
