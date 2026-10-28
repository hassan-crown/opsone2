<?php

use App\Actions\StateMachines\M10Proof\T01ConfirmProof;
use App\Actions\StateMachines\M11Payment\T01RecordPayment;
use App\Agreements\CustomerActor;
use App\Finance\CustomerProofs;
use App\Finance\FinanceLock;
use App\Finance\MoneyLedger;
use App\Models\Agreements\Agreement;
use App\Models\Finance\OrderDue;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentProof;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Agreements/AgreementTestHelpers.php';

/**
 * Finance fixture: the agreement fixture (custom order in branch A on the default flow: «first_payment» 50% in the
 * agreement stage, «final_payment» remaining in the installation stage) with owner, manager, supervisor (no P-37),
 * sales, finance officer and executor.
 */
function financeFixture(): object
{
    return agreementFixture();
}

/**
 * An approved agreement of one line (prices VAT-inclusive): the schedule follows it (50% / rest).
 */
function finAgree(object $f, string $total): Agreement
{
    return agrAgreed($f, [finLine('مطبخ', $total)]);
}

/**
 * @return array<string, mixed>
 */
function finLine(string $description, string $price, bool $products = true): array
{
    return ['description' => $description, 'quantity' => '1', 'unit_price' => $price, 'commissionable' => $products];
}

/**
 * Staff records a received payment (P-39). Similarity is acknowledged unless the test is about it.
 *
 * @param  array<string, mixed>  $extra
 */
function finPay(User $actor, Order $order, string $amount, array $extra = [], ?string $key = null, ?string $evidence = null): Payment
{
    return app(T01RecordPayment::class)->handle($actor, $order->refresh(), [
        'amount' => $amount, 'method' => 'transfer', 'paid_on' => now()->toDateString(),
        'reference' => 'FT'.Str::upper(Str::random(10)), 'acknowledge_similar' => true, ...$extra,
    ], agrPdf('transfer.pdf', $evidence ?? Str::random(8)), null, $key ?? agrKey());
}

/**
 * The customer uploads a transfer proof from their account (P-68).
 */
function finProof(Order $order, string $amount, ?string $reference = 'FT2610204471'): PaymentProof
{
    $customer = new CustomerActor($order->customer_id, $order->customer->mobile);

    return app(CustomerProofs::class)->submit($customer, $order, [
        'amount' => $amount, 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => $reference,
    ], agrPdf('receipt.pdf', Str::random(8)));
}

/**
 * @param  array<string, mixed>  $extra
 */
function finConfirm(User $actor, PaymentProof $proof, ?string $amount = null, array $extra = []): PaymentProof
{
    $proof->refresh();

    return app(T01ConfirmProof::class)->handle($actor, $proof, [
        'amount' => $amount ?? $proof->claimed()->toInput(), 'paid_on' => $proof->paid_on->toDateString(), ...$extra,
    ], $proof->lock_version, agrKey());
}

/**
 * @return array<string, array{amount: int, allocated: int}> due reference → figures
 */
function finDues(Order $order): array
{
    $allocated = MoneyLedger::allocatedByDue($order->id);

    return OrderDue::query()->where('order_id', $order->id)->whereNull('removed_at')->orderBy('position')->get()
        ->mapWithKeys(fn (OrderDue $d) => [$d->reference => ['amount' => $d->amount_minor, 'allocated' => $allocated[$d->id] ?? 0]])->all();
}

function finVersion(Order $order): int
{
    return FinanceLock::versionOf($order);
}

/**
 * Every row a refused money write must leave untouched.
 *
 * @return array<string, mixed>
 */
function finSnapshot(): array
{
    return [
        ...workflowSnapshot(),
        'payments' => DB::table('payments')->get()->map(fn ($p) => [$p->id, $p->status, $p->amount_minor, $p->lock_version])->all(),
        'receipts' => DB::table('receipts')->pluck('number')->all(),
        'proofs' => DB::table('payment_proofs')->get()->map(fn ($p) => [$p->id, $p->status, $p->payment_id, $p->lock_version])->all(),
        'journals' => DB::table('money_journals')->count(),
        'entries' => DB::table('money_entries')->count(),
        'dues' => DB::table('order_dues')->get()->map(fn ($d) => [$d->id, $d->amount_minor, $d->removed_at])->all(),
        'heads' => DB::table('order_finances')->get()->map(fn ($h) => [$h->order_id, $h->lock_version, $h->agreement_id])->all(),
        'counters' => DB::table('sequence_counters')->where('name', 'receipt')->pluck('last_value')->all(),
        'attachments' => DB::table('attachments')->count(),
        'idempotency' => DB::table('idempotency_keys')->count(),
        'outbox' => DB::table('outbox_messages')->count(),
        'audit' => DB::table('audit_events')->count(),
    ];
}

function finRefused(Closure $attempt, string $exception, ?string $condition = null): void
{
    $before = finSnapshot();
    try {
        $attempt();
        test()->fail('The money write was not refused.');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($exception);
        if ($condition !== null) {
            expect($e->condition ?? null)->toBe($condition);
        }
    }
    expect(finSnapshot())->toBe($before);
}

/**
 * Brings the order to the agreement stage (measurement and design/quote stages done), as the owner would.
 */
function finToAgreementStage(object $f): void
{
    finishActiveStage($f->owner, $f->order);
    finishActiveStage($f->owner, $f->order);
}
