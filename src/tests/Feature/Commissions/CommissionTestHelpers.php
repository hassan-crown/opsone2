<?php

use App\Actions\Commissions\UpdateCommissionSettings;
use App\Actions\StateMachines\M33Payout\T00CreatePayout;
use App\Actions\StateMachines\M33Payout\T01ApprovePayout;
use App\Commissions\CommissionAccrual;
use App\Enums\StateMachine;
use App\Finance\CollectionConditions;
use App\Finance\FinanceLock;
use App\Finance\MoneyLedger;
use App\Models\Commissions\CommissionEntry;
use App\Models\Commissions\CommissionPayout;
use App\Models\Commissions\OrderSale;
use App\Models\Finance\Payment;
use App\Models\Order;
use App\Models\User;
use App\Support\LockingTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Finance/FinanceTestHelpers.php';

/**
 * Commission fixture: the finance fixture (custom order of `sales` in branch A) plus a second sales member and the
 * facility policy on at `$rateBp`.
 */
function commissionFixture(int $rateBp = 500): object
{
    $f = financeFixture();
    $f->sales2 = $f->org->member('sales', [$f->org->a]);
    comPolicy($f->owner, $rateBp);

    return $f;
}

/**
 * @param  list<array{role_template_id: int, rate_bp: int|null}>  $roles
 * @param  list<array{user_id: int, rate_bp: int|null}>  $members
 */
function comPolicy(User $actor, int $defaultBp, bool $enabled = true, array $roles = [], array $members = [], bool $manual = true): void
{
    app(UpdateCommissionSettings::class)->handle($actor, [
        'enabled' => $enabled, 'default_rate_bp' => $defaultBp, 'manual_lines_eligible' => $manual,
        'roles' => $roles, 'members' => $members,
    ], null);
}

function comSale(Order $order): ?OrderSale
{
    return OrderSale::query()->where('order_id', $order->id)->where('status', OrderSale::ACTIVE)->first();
}

/** The member's accrual on the order (all sales, after transfers). */
function comAccrued(Order $order, ?User $member = null): int
{
    return (int) CommissionEntry::query()->where('order_id', $order->id)
        ->when($member !== null, fn ($q) => $q->where('user_id', $member->id))->sum('amount_minor');
}

/**
 * What 6c will do for a refund or a correction: reverse a payment's journals and re-check the order (the documented
 * hook re-derives the commission through CollectionConditions::evaluate).
 */
function comReverse(Payment $payment, Order $order, string $reason = 'رد للعميل'): void
{
    LockingTransaction::run(function () use ($payment, $order, $reason) {
        $locked = FinanceLock::order($order->id, StateMachine::M11, 'T07');
        app(MoneyLedger::class)->reversePayment($payment, $reason, null, 'refund:test');
        app(CollectionConditions::class)->evaluate($locked, $payment->id, null);
    });
}

function comRecompute(Order $order): ?CommissionEntry
{
    return LockingTransaction::run(fn () => app(CommissionAccrual::class)->recompute(
        FinanceLock::order($order->id, StateMachine::M11, 'T07'), 'test', null,
    ));
}

function comDraft(User $actor, User $member, ?string $key = null): CommissionPayout
{
    return app(T00CreatePayout::class)->handle($actor, $member, [
        'period_from' => now()->startOfMonth()->toDateString(), 'period_to' => now()->toDateString(),
    ], $key ?? agrKey());
}

/**
 * @return array<string, string>
 */
function comExecution(): array
{
    return ['method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'SAL-'.Str::upper(Str::random(6))];
}

function comPay(User $actor, User $member, ?string $key = null): CommissionPayout
{
    $draft = comDraft($actor, $member);

    return app(T01ApprovePayout::class)->handle($actor, $draft, $draft->lock_version, $key ?? agrKey(), comExecution(), agrPdf('payout.pdf', Str::random(8)));
}

/**
 * Every row a refused commission write must leave untouched.
 *
 * @return array<string, mixed>
 */
function comSnapshot(): array
{
    return [
        ...finSnapshot(),
        'sales' => DB::table('order_sales')->get()->map(fn ($s) => [$s->id, $s->status, $s->user_id, $s->rate_bp])->all(),
        'entries' => DB::table('commission_entries')->count(),
        'payouts' => DB::table('commission_payouts')->get()->map(fn ($p) => [$p->id, $p->status, $p->amount_minor, $p->corrected_minor, $p->lock_version])->all(),
        'lines' => DB::table('commission_payout_lines')->count(),
        'corrections' => DB::table('sale_corrections')->get()->map(fn ($c) => [$c->id, $c->status, $c->lock_version])->all(),
        'transitions' => DB::table('state_transitions')->count(),
    ];
}

function comRefused(Closure $attempt, string $exception, ?string $condition = null): void
{
    $before = comSnapshot();
    try {
        $attempt();
        test()->fail('The commission write was not refused.');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($exception);
        if ($condition !== null) {
            expect($e->condition ?? null)->toBe($condition);
        }
    }
    expect(comSnapshot())->toBe($before);
}
