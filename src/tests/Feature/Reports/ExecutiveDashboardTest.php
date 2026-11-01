<?php

/**
 * Owner dashboard (ADR-0031): who may open it, branch scoping, and its numbers against a hand-computed fixture.
 * Branch A: one agreement of 18,450.00 (1,845,000 halalas); branch B: one of 1,000.00 (100,000).
 */

use App\Reports\Dashboard\ExecutiveDashboard;
use App\Reports\ReportScope;
use App\Workflow\AppointmentBooking;
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

/**
 * @return array<string, int|float>
 */
function exMetrics(array $dashboard): array
{
    return collect($dashboard['metrics'])->mapWithKeys(fn (array $m) => [$m['key'] => $m['value']])->all();
}

function exDashboard(object $viewer, array $query = [], int $status = 200): array
{
    $response = test()->actingAs($viewer)->getJson('/executive'.($query === [] ? '' : '?'.http_build_query($query)));
    $response->assertStatus($status);

    return $status === 200 ? (array) $response->json('dashboard') : [];
}

it('is for P-60 with P-37 only: executors, supervisors and guests are kept out', function () {
    $this->actingAs($this->f->executor)->getJson('/executive')->assertForbidden();
    // The supervisor reads reports (P-60) but holds no money grant: the dashboard is a money page.
    $this->actingAs($this->f->supervisor)->getJson('/executive')->assertForbidden();
    auth()->logout();
    $this->getJson('/executive')->assertUnauthorized();

    foreach (['owner', 'manager', 'finance'] as $who) {
        $this->actingAs($this->f->{$who})->getJson('/executive')->assertOk();
    }
    expect(ExecutiveDashboard::canView($this->f->owner))->toBeTrue()->and(ExecutiveDashboard::canView($this->f->executor))->toBeFalse();
});

it('shows the owner every branch: booked value, average order, trend and branch comparison', function () {
    $d = exDashboard($this->f->owner);
    $m = exMetrics($d);

    expect($m['sales.agreed_gross_minor'])->toBe(1_945_000)
        ->and($m['dashboard.avg_order_minor'])->toBe(972_500)
        ->and($m['sales.orders_created'])->toBe(2)
        ->and($d['branches']['state'])->toBe('available');

    $rows = collect($d['branches']['rows'])->keyBy('branch_id');
    expect($rows[$this->f->org->a->id]['agreed_gross_minor'])->toBe(1_845_000)
        ->and($rows[$this->f->org->b->id]['agreed_gross_minor'])->toBe(100_000)
        ->and($rows[$this->f->org->b->id]['href'])->toContain('branch='.$this->f->org->b->id);

    // The same number the sales report shows: one definition.
    expect($m['sales.agreed_gross_minor'])->toBe(repMetrics(repReport($this->f->owner, 'sales'))['sales.agreed_gross_minor']);

    $trend = $d['trend'];
    expect($trend)->toHaveCount(6)->and(end($trend)['sales_minor'])->toBe(1_945_000);
});

it('scopes a branch manager to their branch and offers no comparison', function () {
    $d = exDashboard($this->f->manager);
    $m = exMetrics($d);

    expect($m['sales.agreed_gross_minor'])->toBe(1_845_000)
        ->and($m['dashboard.avg_order_minor'])->toBe(1_845_000)
        ->and($m['sales.orders_created'])->toBe(1)
        ->and($d['branches'])->toBe(['state' => 'single', 'rows' => []]);

    // Asking for the other branch explicitly matches nothing: the scope is an AND.
    $other = exMetrics(exDashboard($this->f->manager, ['branch_ids' => [$this->f->org->b->id]]));
    expect($other['sales.agreed_gross_minor'])->toBe(0)->and($other['sales.orders_created'])->toBe(0);

    $page = $this->actingAs($this->f->manager)->getJson('/executive')->json();
    expect(array_column($page['branches'], 'id'))->toBe([$this->f->org->a->id]);
});

it('filters the owner to one branch and counts collections from the ledger', function () {
    finPay($this->f->finance, $this->f->order, '9225');

    $all = exMetrics(exDashboard($this->f->owner));
    expect($all['collection.collected_minor'])->toBe(922_500);

    $b = exMetrics(exDashboard($this->f->owner, ['branch_ids' => [$this->f->org->b->id]]));
    expect($b['sales.agreed_gross_minor'])->toBe(100_000)->and($b['collection.collected_minor'])->toBe(0);

    $a = exDashboard($this->f->owner, ['branch_ids' => [$this->f->org->a->id]]);
    expect(exMetrics($a)['collection.collected_minor'])->toBe(922_500)
        ->and(end($a['trend'])['collected_minor'])->toBe(922_500)
        ->and($a['links']['orders'])->toContain('branch='.$this->f->org->a->id);
});

it('resolves the named periods on the server and bounds a custom one', function () {
    $today = AppointmentBooking::today();
    expect(exDashboard($this->f->owner)['period'])->toBe('last30');
    $month = exDashboard($this->f->owner, ['period' => 'month']);
    expect($month['range'])->toBe(['from' => ReportScopeFrom(), 'to' => $today]);

    $last30 = exDashboard($this->f->owner);
    expect($last30['range']['to'])->toBe($today)->and($last30['range']['from'])->toBe(now()->parse($today)->subDays(29)->toDateString());

    $quarter = exDashboard($this->f->owner, ['period' => 'quarter']);
    expect($quarter['range']['from'])->toBe(now()->parse($today)->startOfQuarter()->toDateString());

    $custom = exDashboard($this->f->owner, ['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31']);
    expect($custom['range'])->toBe(['from' => '2026-01-01', 'to' => '2026-01-31'])->and(exMetrics($custom)['sales.agreed_gross_minor'])->toBe(0);

    // Custom without both dates falls back to the default window; a bad period or an oversized range is refused.
    expect(exDashboard($this->f->owner, ['period' => 'custom'])['period'])->toBe('last30');
    exDashboard($this->f->owner, ['period' => 'decade'], 422);
    exDashboard($this->f->owner, ['period' => 'custom', 'from' => '2024-01-01', 'to' => '2026-01-01'], 422);
});

it('computes conversion, funnel and installations from their own sources', function () {
    $m = exMetrics(exDashboard($this->f->owner));

    // No interests in the fixture: zero created and a zero (never a division error) conversion.
    expect($m['dashboard.interests_created'])->toBe(0)->and($m['dashboard.interest_conversion_percent'])->toEqual(0)
        ->and($m['dashboard.installations_next_14d'])->toBe(0);

    $funnel = collect(exDashboard($this->f->owner)['funnel'])->pluck('value', 'key')->all();
    expect($funnel['interests'])->toBe(0)->and($funnel['agreements'])->toBe(2)->and($funnel['completed'])->toBe(0);
});

it('without the branch_comparisons capability the owner sees a locked comparison, not rows', function () {
    repLicense(['ready_goods']);

    expect(exDashboard($this->f->owner)['branches'])->toBe(['state' => 'locked', 'rows' => []]);
});

it('renders the Inertia page and sends the owner there after signing in, others to the home page', function () {
    $this->actingAs($this->f->owner)->get('/executive')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('reports/executive')->has('dashboard.metrics')->where('filters.period', 'last30'));
    auth()->logout();

    $owner = $this->f->owner;
    $executor = $this->f->executor;
    $password = 'Sup3r-secret-Pass!';
    foreach ([$owner, $executor] as $user) {
        $user->forceFill(['password' => bcrypt($password)])->save();
    }

    $this->post('/login', ['email' => $owner->email, 'password' => $password])->assertRedirect(route('executive', absolute: false));
    auth()->logout();
    $this->post('/login', ['email' => $executor->email, 'password' => $password])->assertRedirect(route('dashboard', absolute: false));
});

function ReportScopeFrom(): string
{
    return ReportScope::defaultFrom();
}
