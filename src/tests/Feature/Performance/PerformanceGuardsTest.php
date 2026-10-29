<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Portal\PortalSession;
use Database\Seeders\DemoSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\PerformanceSeeder;
use Database\Seeders\RoleTemplateSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\LicenseFactory;

/*
 * P9 performance guards (ADR-0027, docs/performance.md): the main pages keep a fixed query budget whatever the data
 * size (an N+1 fails here before it reaches a facility with 20,000 orders), never lazy-load a relation, and the key
 * list queries can use their intended indexes (MariaDB only). Budgets are the measured counts plus a small margin
 * (the same counts at three times the data); raise one only with a reason in docs/performance.md.
 */

uses(RefreshDatabase::class);

/**
 * A small slice of the Q-55 dataset (scale 0.01 ≈ 50 customers, 200 orders, 2,000 movements) on the demo org.
 */
function perfDataset(float $scale = 0.01): void
{
    LicenseFactory::install(['plan' => 'scale', 'limits' => ['seats' => 100, 'branches' => 10, 'storage_mb' => 25600]]);
    test()->seed([FacilitySeeder::class, RoleTemplateSeeder::class, DemoSeeder::class]);
    $seeder = app(PerformanceSeeder::class);
    $seeder->scale = $scale;
    $seeder->run();
}

/**
 * Headers of a navigation inside the app (JSON page object, no HTML shell).
 *
 * @return array<string, string>
 */
function inertiaVisit(): array
{
    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
}

/**
 * Number of statements one request runs.
 */
function queriesFor(Closure $request): int
{
    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });
    $request();
    DB::flushQueryLog();

    return $count;
}

/**
 * @return array<string, array{0: string, 1: string, 2: int}> page → [uri, actor, budget]
 */
function staffBudgets(): array
{
    return [
        'dashboard' => ['/dashboard', 'owner', 22],
        'orders' => ['/orders', 'owner', 20],
        'orders filtered' => ['/orders?status=in_progress&page=1', 'owner', 20],
        'orders scoped' => ['/orders', 'sales', 28],
        'customers' => ['/customers', 'owner', 20],
        'search' => ['/search?q=فهد', 'owner', 20],
        'tasks' => ['/tasks', 'owner', 22],
        'tasks executor' => ['/tasks', 'executor', 46],
        'tasks supervisor inbox' => ['/tasks?view=inbox', 'supervisor', 34],
        'dashboard executor' => ['/dashboard', 'executor', 32],
        'calendar' => ['/calendar', 'owner', 26],
        'finance' => ['/finance', 'owner', 30],
        'stock' => ['/stock', 'owner', 28],
        'reports' => ['/reports', 'owner', 28],
        'report sales' => ['/reports/sales', 'owner', 28],
        'report refunds' => ['/reports/refunds', 'owner', 24],
        'report commissions' => ['/reports/commissions', 'owner', 22],
        'report workflow' => ['/reports/workflow', 'owner', 21],
        'report appointments' => ['/reports/appointments', 'owner', 23],
        'report inventory' => ['/reports/inventory', 'owner', 26],
        'report procurement' => ['/reports/procurement', 'owner', 25],
        'report returns' => ['/reports/returns', 'owner', 28],
        'report store' => ['/reports/store', 'owner', 24],
        // One run of the sales, workflow and collection reports per visible branch (≤ the license's branch limit,
        // 4 in the dataset): linear in branches, constant in rows.
        'report compare' => ['/reports/compare', 'owner', 120],
        'report exports' => ['/reports/exports', 'owner', 15],
        'collection report' => ['/reports/collection', 'owner', 22],
        'reports scoped' => ['/reports/sales', 'supervisor', 26],
    ];
}

function perfActor(string $who): User
{
    return match ($who) {
        'owner' => User::query()->where('email', 'owner@demo.test')->firstOrFail(),
        'sales' => User::query()->where('email', 'like', 'perf-sales-%')->orderBy('id')->firstOrFail(),
        'executor' => User::query()->where('email', 'like', 'perf-executor-%')->orderBy('id')->firstOrFail(),
        'supervisor' => User::query()->where('email', 'supervisor@demo.test')->firstOrFail(),
    };
}

it('keeps the staff pages within their query budget', function () {
    perfDataset();

    $counts = [];
    $over = [];
    foreach (staffBudgets() as $page => [$uri, $who, $budget]) {
        $actor = perfActor($who);
        app()->forgetScopedInstances();
        $status = 0;
        $counts[$page] = queriesFor(function () use ($actor, $uri, &$status) {
            $status = $this->actingAs($actor)->withHeaders(inertiaVisit())->get($uri)->status();
        });
        expect($status)->toBe(200, "{$page} ({$uri})");
        if ($counts[$page] > $budget) {
            $over[] = "{$page} ({$uri}): {$counts[$page]} queries, budget {$budget}";
        }
    }

    expect($over)->toBe([], 'Query budgets exceeded. All counts: '.json_encode($counts));
});

it('runs the same queries for a page of 3 rows and of 100 (no N+1)', function () {
    perfDataset();
    $executor = perfActor('executor');
    $supervisor = perfActor('supervisor');
    $department = (int) DB::table('memberships')->where('user_id', $supervisor->id)->value('department_id');
    $pages = [
        'tasks executor' => ['/tasks', $executor],
        'dashboard executor' => ['/dashboard', $executor],
        'supervisor inbox' => ['/tasks?view=inbox', $supervisor],
        'calendar' => ['/calendar', perfActor('owner')],
    ];
    $measure = function () use ($pages): array {
        $counts = [];
        foreach ($pages as $page => [$uri, $actor]) {
            app()->forgetScopedInstances();
            $counts[$page] = queriesFor(fn () => $this->actingAs($actor)->withHeaders(inertiaVisit())->get($uri)->assertOk());
        }

        return $counts;
    };
    $before = $measure();

    // Pile the open work onto the same people and this week: every list now has dozens of rows more.
    $open = DB::table('tasks')->whereIn('status', ['assigned', 'in_progress', 'waiting'])->orderBy('id')->limit(100)->pluck('id');
    DB::table('tasks')->whereIn('id', $open)->update(['assignee_id' => $executor->id, 'due_at' => now()]);
    $unassigned = DB::table('tasks')->where('status', 'unassigned')->orderBy('id')->limit(100)->pluck('id');
    DB::table('tasks')->whereIn('id', $unassigned)->update(['department_id' => $department]);
    DB::table('order_stages')->whereIn('id', DB::table('tasks')->whereIn('id', $unassigned)->select('order_stage_id'))
        ->update(['follow_up_department_id' => $department]);
    DB::table('appointments')->orderBy('id')->limit(100)->update(['status' => 'confirmed', 'starts_at' => now()->setTime(10, 0), 'ends_at' => now()->setTime(11, 0)]);
    expect(DB::table('tasks')->where('assignee_id', $executor->id)->whereIn('status', ['assigned', 'in_progress', 'waiting'])->count())->toBeGreaterThan(20);

    $after = $measure();
    foreach ($before as $page => $count) {
        expect($after[$page])->toBeLessThanOrEqual($count + 2, "{$page}: {$count} queries before, {$after[$page]} with more rows");
    }
});

it('keeps the customer workspace and the store within their query budget', function () {
    perfDataset();
    $customerId = (int) DB::table('orders')->join('customers', 'customers.id', '=', 'orders.customer_id')
        ->where('customers.portal_login_enabled', true)->groupBy('orders.customer_id')
        ->orderByRaw('COUNT(*) DESC')->value('orders.customer_id');
    $customer = Customer::query()->findOrFail($customerId);
    $order = Order::query()->where('customer_id', $customer->id)->orderByDesc('id')->firstOrFail();

    foreach (['/portal' => 22, '/portal/orders' => 10, "/portal/orders/{$order->id}" => 24] as $uri => $budget) {
        Auth::guard(PortalSession::GUARD)->setUser($customer);
        $count = queriesFor(fn () => $this->withSession([PortalSession::STAMP => $customer->portal_session_version])
            ->withHeaders(inertiaVisit())->get($uri)->assertOk());

        expect($count)->toBeLessThanOrEqual($budget, "{$uri} ran {$count} queries; budget {$budget}");
    }

    $count = queriesFor(fn () => $this->get('/ar/store')->assertOk());
    expect($count)->toBeLessThanOrEqual(60, "/ar/store ran {$count} queries");
});

it('refuses lazy loading outside production', function () {
    expect(Model::preventsLazyLoading())->toBeTrue();

    // Every page above runs with lazy loading refused: a relation read without eager loading fails the request.
    perfDataset();
    $order = Order::query()->where('kind', 'custom')->where('status', 'in_progress')->orderByDesc('id')->firstOrFail();
    $this->actingAs(perfActor('owner'))->get("/orders/{$order->id}")->assertOk();
});

it('lets the key list queries use their intended indexes', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('EXPLAIN plans are checked on MariaDB only.');
    }
    perfDataset();
    DB::statement('ANALYZE TABLE orders, money_entries, appointments, tasks, stock_balances');

    $balance = DB::table('stock_balances')->orderBy('id')->first();
    $plans = [
        'orders by status' => [Order::query()->where('status', 'in_progress')->orderByDesc('id')->limit(20), 'orders_status_id_index', true],
        'orders by branch and status' => [Order::query()->where('branch_id', 1)->where('status', 'in_progress')->orderByDesc('id')->limit(20), 'orders_branch_id_status_id_index', true],
        'orders by customer' => [Order::query()->where('customer_id', 1)->orderByDesc('id')->limit(20), 'orders_customer_id_id_index', true],
        'unallocated credit' => [DB::table('money_entries')->whereNull('order_due_id')->groupBy('order_id')->selectRaw('order_id, SUM(amount_minor)'), 'money_entries_due_order_amount_index', true],
        'allocated per due' => [DB::table('money_entries')->where('order_due_id', 1)->selectRaw('SUM(amount_minor)'), 'money_entries_due_order_amount_index', true],
        'calendar window' => [DB::table('appointments')->whereIn('status', ['proposed', 'confirmed'])->where('starts_at', '<', now()->addWeek())->where('starts_at', '>', now()->subDays(3))->orderBy('starts_at'), 'appointments_status_starts_at_index', true],
        'my tasks' => [DB::table('tasks')->where('assignee_id', 1)->whereIn('status', ['assigned', 'in_progress'])->orderBy('due_at'), 'tasks_assignee_id_status_index', true],
        'department inbox' => [DB::table('tasks')->where('department_id', 1)->where('status', 'unassigned')->orderBy('due_at'), 'tasks_department_id_status_index', true],
        'stock balance of a location' => [DB::table('stock_balances')->where('location_id', $balance->location_id)->where('stock_item_id', '>', 0), 'stock_balances_location_id_stock_item_id_unique', false],
    ];

    // The third value false: on this small slice a quarter of the table matches, so a scan may be the cheaper plan.
    foreach ($plans as $name => [$query, $index, $selective]) {
        $plan = (array) DB::selectOne('EXPLAIN '.$query->toSql(), $query->getBindings());
        $possible = explode(',', (string) ($plan['possible_keys'] ?? ''));

        expect(in_array($index, $possible, true))->toBeTrue("{$name}: possible keys ".($plan['possible_keys'] ?? 'none'));
        if ($selective) {
            expect($plan['type'] ?? null)->not->toBe('ALL', "{$name} scans the whole table");
        }
    }
});
