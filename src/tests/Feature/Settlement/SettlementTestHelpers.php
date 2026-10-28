<?php

use App\Actions\StateMachines\M11Payment\T07CorrectPayment;
use App\Actions\StateMachines\M12Refund\T00RequestRefund;
use App\Actions\StateMachines\M12Refund\T02ConfirmRefund;
use App\Actions\StateMachines\M39Dispute\T00OpenDispute;
use App\Actions\StateMachines\M40Document\T01AttachDocument;
use App\Finance\FinanceLock;
use App\Models\Finance\ExternalDocument;
use App\Models\Finance\FinancialDispute;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentCorrection;
use App\Models\Finance\Refund;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Finance/FinanceTestHelpers.php';

/**
 * A refund request (P-42) of part of a payment on its order, with the finance version just read (`$readVersion`
 * false: no optimistic check, so a race exercises the limit under the order lock alone).
 */
function setRefund(User $actor, Order $order, Payment $payment, string $amount, ?string $key = null, bool $readVersion = true): Refund
{
    return app(T00RequestRefund::class)->handle($actor, $order->refresh(), [
        'payment_id' => $payment->id, 'amount' => $amount, 'method' => 'transfer', 'reason' => 'خُفض الاتفاق بعد حذف بند.',
    ], $readVersion ? FinanceLock::versionOf($order) : null, $key ?? agrKey());
}

/**
 * The refund was paid outside the system and its proof recorded (P-43, M-12-T02).
 */
function setPaid(User $actor, Refund $refund, ?string $reference = null, ?string $key = null): Refund
{
    $refund->refresh();

    return app(T02ConfirmRefund::class)->handle($actor, $refund, ['reference' => $reference ?? 'TRX'.Str::upper(Str::random(8))],
        agrPdf('refund.pdf', Str::random(8)), $refund->lock_version, $key ?? agrKey());
}

/**
 * @param  array<string, mixed>  $data
 */
function setCorrect(User $actor, Order $order, Payment $payment, array $data, ?string $key = null): PaymentCorrection
{
    return app(T07CorrectPayment::class)->handle($actor, $order->refresh(), $payment, ['reason' => 'خطأ في إدخال المبلغ.', ...$data],
        null, FinanceLock::versionOf($order), $key ?? agrKey());
}

/**
 * @param  array<string, mixed>  $data
 */
function setDispute(User $actor, Order $order, array $data): FinancialDispute
{
    return app(T00OpenDispute::class)->handle($actor, $order->refresh(), [
        'subject' => 'settlement', 'amount' => '500', 'responsible_id' => $actor->id,
        'reason' => 'ترى العميلة أن التسوية 1,500.00.', ...$data,
    ], FinanceLock::versionOf($order), agrKey());
}

/**
 * @param  array<string, mixed>  $data
 */
function setLinkDocument(User $actor, Order $order, array $data = [], ?ExternalDocument $document = null): ExternalDocument
{
    return app(T01AttachDocument::class)->handle($actor, $order->refresh(), $document?->refresh(), [
        'kind' => 'simplified_invoice', 'event' => 'order', 'source' => 'Qoyod', 'number' => 'INV-2026-0398',
        'issued_on' => now()->toDateString(), 'amount' => '5000', ...$data,
    ], agrPdf('invoice.pdf', Str::random(8)), $document?->lock_version, agrKey());
}

/**
 * Every row a refused settlement write must leave untouched.
 *
 * @return array<string, mixed>
 */
function setSnapshot(): array
{
    return [
        ...finSnapshot(),
        'refunds' => DB::table('refunds')->get()->map(fn ($r) => [$r->id, $r->status, $r->number, $r->attempt, $r->lock_version])->all(),
        'corrections' => DB::table('payment_corrections')->pluck('number')->all(),
        'disputes' => DB::table('financial_disputes')->get()->map(fn ($d) => [$d->id, $d->status, $d->lock_version])->all(),
        'documents' => DB::table('external_documents')->get()->map(fn ($d) => [$d->id, $d->status, $d->number, $d->lock_version])->all(),
        'all_counters' => DB::table('sequence_counters')->get()->map(fn ($c) => [$c->name, $c->last_value])->all(),
    ];
}

function setRefused(Closure $attempt, string $exception, ?string $condition = null): void
{
    $before = setSnapshot();
    try {
        $attempt();
        test()->fail('The settlement write was not refused.');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($exception);
        if ($condition !== null) {
            expect($e->condition ?? null)->toBe($condition);
        }
    }
    expect(setSnapshot())->toBe($before);
}

/**
 * Append-only check of the whole journal: every journal sums to what its kind allows and a reversal is the exact
 * negation of the journal it names.
 */
function setLedgerBalanced(): void
{
    $journals = DB::table('money_journals')->get();
    foreach ($journals as $j) {
        $sum = (int) DB::table('money_entries')->where('journal_id', $j->id)->sum('amount_minor');
        match ($j->kind) {
            'collection' => expect($sum)->toBe((int) DB::table('payments')->where('id', $j->payment_id)->value('amount_minor')),
            'refund' => expect($sum)->toBeLessThan(0),
            'reversal' => expect($sum)->toBe(-(int) DB::table('money_entries')->where('journal_id', $j->reverses_journal_id)->sum('amount_minor')),
            default => expect($sum)->toBe(0),
        };
    }
}
