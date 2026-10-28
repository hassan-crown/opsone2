<?php

/**
 * Ledger invariants after a randomized (seeded, reproducible) sequence of money operations on one order: payments,
 * overpayments, refunds executed or left pending, voids and amendments. After every step the ledger must be
 * append-only, every journal balanced, the order's sum equal to the money actually kept, its allocations within
 * their dues, the commission equal to its basis, and RC/RF/CR numbers gapless.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Commissions\CommissionBasis;
use App\Finance\MoneyLedger;
use App\Finance\Settlement\RefundLimits;
use App\Models\Finance\OrderDue;
use App\Models\Finance\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/SettlementTestHelpers.php';
require_once __DIR__.'/../Commissions/CommissionTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * @param  list<array<string, mixed>>  $before  every money_entries row before the step
 */
function ledgerInvariants(object $f, int $expectedNet, array $before): void
{
    $order = $f->order->refresh();
    $rows = DB::table('money_entries')->orderBy('id')->get()->map(fn ($e) => (array) $e)->all();
    expect(array_slice($rows, 0, count($before)))->toBe($before, 'money entries are append-only');
    setLedgerBalanced();

    $sum = (int) DB::table('money_entries')->where('order_id', $order->id)->sum('amount_minor');
    $allocated = MoneyLedger::allocatedByDue($order->id);
    expect($sum)->toBe($expectedNet)
        ->and(MoneyLedger::collected($order->id)->minor)->toBe($sum)
        ->and(MoneyLedger::credit($order->id)->minor + array_sum($allocated))->toBe($sum)
        ->and(MoneyLedger::credit($order->id)->minor)->toBeGreaterThanOrEqual(0);
    foreach (OrderDue::query()->where('order_id', $order->id)->get() as $due) {
        expect($allocated[$due->id] ?? 0)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($due->amount_minor);
    }
    foreach (Payment::query()->where('order_id', $order->id)->get() as $payment) {
        expect(RefundLimits::refundable($payment, $order->id)->minor)->toBeGreaterThanOrEqual(0);
    }

    $sale = comSale($order);
    if ($sale !== null) {
        expect(comAccrued($order))->toBe(CommissionBasis::of($order, $sale)['target_minor']);
    }

    $year = now()->format('Y');
    foreach (['receipts' => 'RC', 'refunds' => 'RF', 'payment_corrections' => 'CR'] as $table => $prefix) {
        $numbers = DB::table($table)->whereNotNull('number')->orderBy('number')->pluck('number')->all();
        $expected = array_map(fn (int $i) => sprintf('%s-%s-%06d', $prefix, $year, $i), range(1, max(1, count($numbers))));
        expect($numbers)->toBe(count($numbers) === 0 ? [] : $expected, "{$prefix} numbers are gapless");
    }
}

it('keeps the ledger balanced and consistent through a random sequence of money operations', function (int $seed) {
    Storage::fake('attachments');
    mt_srand($seed);
    $f = commissionFixture(750);
    agrSettings(0);
    agrAgreed($f, [finLine('مطبخ', '3000'), finLine('تركيب', '450', false)]);

    $net = 0;
    for ($step = 0; $step < 18; $step++) {
        $before = DB::table('money_entries')->orderBy('id')->get()->map(fn ($e) => (array) $e)->all();
        $payments = Payment::query()->where('order_id', $f->order->id)->where('status', 'confirmed')->get();
        $op = $payments->isEmpty() ? 0 : mt_rand(0, 4);
        try {
            switch ($op) {
                case 0: // payment, sometimes above the agreement (credit)
                    $amount = mt_rand(1, 160_000);
                    finPay($f->finance, $f->order, sprintf('%d.%02d', intdiv($amount, 100), $amount % 100));
                    $net += $amount;
                    break;
                case 1: // refund executed
                case 2: // refund requested and left pending
                    $payment = $payments->random();
                    $limit = RefundLimits::refundable($payment, $f->order->id)->minor;
                    if ($limit <= 0) {
                        break;
                    }
                    $amount = mt_rand(1, $limit);
                    $refund = setRefund($f->finance, $f->order, $payment, sprintf('%d.%02d', intdiv($amount, 100), $amount % 100));
                    if ($op === 1) {
                        setPaid($f->finance, $refund);
                        $net -= $amount;
                    }
                    break;
                case 3: // void
                    $payment = $payments->random();
                    setCorrect($f->finance, $f->order, $payment, ['kind' => 'void']);
                    $net -= $payment->amount_minor;
                    break;
                case 4: // amend
                    $payment = $payments->random();
                    $amount = mt_rand(1, 160_000);
                    setCorrect($f->finance, $f->order, $payment, [
                        'kind' => 'amend', 'amount' => sprintf('%d.%02d', intdiv($amount, 100), $amount % 100), 'method' => 'transfer',
                        'paid_on' => $payment->paid_on->toDateString(), 'reference' => 'FT'.mt_rand(100000, 999999),
                    ]);
                    $net += $amount - $payment->amount_minor;
                    break;
            }
        } catch (InvalidTransition $e) {
            // A refused operation (a payment with a pending refund, …) must leave everything as it was.
        }
        ledgerInvariants($f, $net, $before);
    }
})->with([7, 1337, 20261028]);
