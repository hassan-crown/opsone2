<?php

/**
 * Every M-12 (refund), M-39 (financial dispute) and M-40 (external accounting document) transition: allowed from its
 * states, refused from every other state without any side effect (no row, version, counter, audit or transition).
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M12Refund\RefundProviderResults;
use App\Actions\StateMachines\M12Refund\T01StartRefund;
use App\Actions\StateMachines\M12Refund\T04RecordRefundFailure;
use App\Actions\StateMachines\M12Refund\T06CancelRefund;
use App\Actions\StateMachines\M12Refund\T07RetryRefund;
use App\Actions\StateMachines\M39Dispute\T01AwaitExternal;
use App\Actions\StateMachines\M39Dispute\T02ResumeDispute;
use App\Actions\StateMachines\M39Dispute\T03ResolveDispute;
use App\Actions\StateMachines\M40Document\T00RequestDocument;
use App\Actions\StateMachines\M40Document\T02AcceptDocument;
use App\Actions\StateMachines\M40Document\T03RequestDocumentCorrection;
use App\Enums\DisputeStatus;
use App\Enums\ExternalDocumentStatus;
use App\Enums\RefundStatus;
use App\Finance\DisputedAmounts;
use App\Finance\FinanceWorkList;
use App\Finance\MoneyLedger;
use App\Models\Finance\FinancialDispute;
use App\Models\Finance\OrderDue;
use App\Models\Finance\Refund;
use App\Support\StaleRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/SettlementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
    finAgree($this->f, '1000');
    $this->payment = finPay($this->f->finance, $this->f->order, '1000');
});

/**
 * Brings a fresh refund of 10 to the given M-12 state.
 */
function refundIn(object $t, RefundStatus $state): Refund
{
    $refund = setRefund($t->f->finance, $t->f->order, $t->payment, '10');
    $start = fn () => app(T01StartRefund::class)->handle($t->f->finance, $refund->refresh(), ['reference' => 'TRX-'.$refund->id], $refund->lock_version, agrKey());
    match ($state) {
        RefundStatus::Requested => null,
        RefundStatus::InProgress => $start(),
        RefundStatus::Unresolved => [$start(), app(RefundProviderResults::class)->unresolved($refund, 'TRX-'.$refund->id)],
        RefundStatus::Executed => setPaid($t->f->finance, $refund),
        RefundStatus::Failed => [$start(), app(T04RecordRefundFailure::class)->handle($t->f->finance, $refund->refresh(), 'رفض البنك.', $refund->lock_version, agrKey())],
        RefundStatus::Cancelled => app(T06CancelRefund::class)->handle($t->f->sales, $refund->refresh(), 'العميل عدل عن الطلب.', $refund->lock_version, agrKey()),
    };

    return $refund->refresh();
}

/**
 * Frees what a refund still reserves, so the payment's limit never runs out in a loop.
 */
function refundRelease(Refund $refund): void
{
    $refund->refresh();
    if ($refund->status === RefundStatus::Requested) {
        app(T01StartRefund::class)->handle(test()->f->finance, $refund, [], $refund->lock_version, agrKey());
    }
    if ($refund->refresh()->status->reserves()) {
        app(T04RecordRefundFailure::class)->handle(null, $refund, 'تنظيف', null, null);
    }
}

/**
 * @return array<string, Closure(Refund, object): mixed>
 */
function refundMoves(): array
{
    return [
        'T01' => fn (Refund $r, object $t) => app(T01StartRefund::class)->handle($t->f->finance, $r, [], $r->lock_version, agrKey()),
        'T02' => fn (Refund $r, object $t) => setPaid($t->f->finance, $r),
        'T03' => fn (Refund $r) => app(RefundProviderResults::class)->unresolved($r, 'TRX-X'),
        'T04' => fn (Refund $r, object $t) => app(T04RecordRefundFailure::class)->handle($t->f->finance, $r, 'فشل.', $r->lock_version, agrKey()),
        'T05' => fn (Refund $r) => app(RefundProviderResults::class)->succeeded($r, $r->reference ?? 'TRX-X'),
        'T06' => fn (Refund $r, object $t) => app(T06CancelRefund::class)->handle($t->f->finance, $r, 'عدول.', $r->lock_version, agrKey()),
        'T07' => fn (Refund $r, object $t) => app(T07RetryRefund::class)->handle($t->f->finance, $r, ['reference' => 'TRX-RETRY'], $r->lock_version, agrKey()),
    ];
}

it('M-12: each transition moves only from its states and is refused from every other state without effect', function () {
    $expected = [
        'T01' => [RefundStatus::Requested, RefundStatus::InProgress],
        'T02' => [RefundStatus::InProgress, RefundStatus::Executed],
        'T03' => [RefundStatus::InProgress, RefundStatus::Unresolved],
        'T04' => [RefundStatus::Unresolved, RefundStatus::Failed],
        'T05' => [RefundStatus::Unresolved, RefundStatus::Executed],
        'T06' => [RefundStatus::Requested, RefundStatus::Cancelled],
        'T07' => [RefundStatus::Failed, RefundStatus::InProgress],
    ];
    $allowed = RefundStatus::transitions();
    foreach (refundMoves() as $code => $move) {
        foreach (RefundStatus::cases() as $state) {
            $fromRequested = $code === 'T02' && $state === RefundStatus::Requested; // T02 records T01 first (manual payout)
            if (in_array($state, $allowed[$code]['from'], true) || $fromRequested) {
                $refund = refundIn($this, $state);
                $move($refund, $this);
                expect($refund->refresh()->status)->toBe($allowed[$code]['to']);
                refundRelease($refund);

                continue;
            }
            $refund = refundIn($this, $state);
            setRefused(fn () => $move($refund, $this), InvalidTransition::class, 'not_from_'.$state->value);
            refundRelease($refund);
        }
    }
    expect($expected['T01'][1])->toBe($allowed['T01']['to']);
    setLedgerBalanced();
});

it('M-12: only the transition\'s own grant acts — P-42 requests and withdraws, P-43 executes; a stale version is refused', function () {
    $refund = refundIn($this, RefundStatus::Requested);
    setRefused(fn () => app(T01StartRefund::class)->handle($this->f->sales, $refund, [], $refund->lock_version, agrKey()), AuthorizationException::class);
    setRefused(fn () => setPaid($this->f->manager, $refund), AuthorizationException::class);
    setRefused(fn () => app(T01StartRefund::class)->handle($this->f->finance, $refund, [], $refund->lock_version + 5, agrKey()), StaleRecord::class);
    setRefused(fn () => app(T06CancelRefund::class)->handle($this->f->executor, $refund, 'x', $refund->lock_version, agrKey()), AuthorizationException::class);

    app(T06CancelRefund::class)->handle($this->f->manager, $refund, 'عدول العميل.', $refund->lock_version, agrKey());
    expect($refund->refresh()->status)->toBe(RefundStatus::Cancelled)
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(100_000);
});

it('M-12-T05: a provider success after an unknown result executes once and must name the same reference', function () {
    $refund = refundIn($this, RefundStatus::Unresolved);
    setRefused(fn () => app(RefundProviderResults::class)->succeeded($refund, 'OTHER'), InvalidTransition::class, 'reference_mismatch');
    app(RefundProviderResults::class)->succeeded($refund, (string) $refund->reference);
    expect($refund->refresh()->status)->toBe(RefundStatus::Executed)
        ->and($refund->number)->toStartWith('RF-')
        ->and(MoneyLedger::collected($this->f->order->id)->minor)->toBe(99_000);
});

/**
 * Brings a fresh dispute on the first due to the given M-39 state.
 */
function disputeIn(object $t, DisputeStatus $state): FinancialDispute
{
    $due = OrderDue::query()->where('order_id', $t->f->order->id)->where('reference', 'first')->firstOrFail();
    $dispute = setDispute($t->f->finance, $t->f->order, ['due_id' => $due->id, 'amount' => '100']);
    if ($state !== DisputeStatus::Open) {
        app(T01AwaitExternal::class)->handle($t->f->finance, $dispute, 'البنك', null, $dispute->lock_version, agrKey());
    }
    if ($state === DisputeStatus::Resolved) {
        app(T03ResolveDispute::class)->handle($t->f->finance, $dispute->refresh(), ['outcome' => 'rejected', 'resolution' => 'كشف البنك يظهر تحويلًا واحدًا.'],
            agrPdf('statement.pdf'), $dispute->lock_version, agrKey());
    }

    return $dispute->refresh();
}

it('M-39: open → awaiting external → open → resolved with proof; each refused from other states without effect', function () {
    $moves = [
        'T01' => fn (FinancialDispute $d) => app(T01AwaitExternal::class)->handle($this->f->finance, $d, 'البنك', 'طلب كشف', $d->lock_version, agrKey()),
        'T02' => fn (FinancialDispute $d) => app(T02ResumeDispute::class)->handle($this->f->finance, $d, 'وصل الكشف.', $d->lock_version, agrKey()),
        'T03' => fn (FinancialDispute $d) => app(T03ResolveDispute::class)->handle($this->f->finance, $d, ['outcome' => 'upheld', 'resolution' => 'ثبت التكرار.'],
            agrPdf('proof.pdf'), $d->lock_version, agrKey()),
    ];
    foreach ($moves as $code => $move) {
        foreach (DisputeStatus::cases() as $state) {
            $dispute = disputeIn($this, $state);
            if (in_array($state, DisputeStatus::transitions()[$code]['from'], true)) {
                $move($dispute);
                expect($dispute->refresh()->status)->toBe(DisputeStatus::transitions()[$code]['to']);
            } else {
                setRefused(fn () => $move($dispute), InvalidTransition::class, 'not_from_'.$state->value);
            }
        }
    }
    // A resolution never moves money.
    expect(MoneyLedger::collected($this->f->order->id)->minor)->toBe(100_000);
});

it('FIN-11/13: an open dispute holds its part of the due from reminders until resolved; it needs a movement, a P-85 holder and an explained amount', function () {
    $due = OrderDue::query()->where('order_id', $this->f->order->id)->where('reference', 'final')->firstOrFail();
    $dispute = setDispute($this->f->finance, $this->f->order, ['due_id' => $due->id, 'amount' => '200']);
    expect(app(DisputedAmounts::class)->disputedFor($due)->minor)->toBe(20_000)
        ->and(FinanceWorkList::for($this->f->finance)['counts']['dispute'])->toBe(1);

    app(T03ResolveDispute::class)->handle($this->f->finance, $dispute, ['outcome' => 'partly_upheld', 'resolution' => 'تسوية جزئية.'], agrPdf(), $dispute->lock_version, agrKey());
    expect(app(DisputedAmounts::class)->disputedFor($due->refresh())->minor)->toBe(0);

    setRefused(fn () => setDispute($this->f->finance, $this->f->order, []), InvalidTransition::class, 'no_movement');
    setRefused(fn () => setDispute($this->f->sales, $this->f->order, ['payment_id' => $this->payment->id]), AuthorizationException::class);
    setRefused(fn () => setDispute($this->f->finance, $this->f->order, ['payment_id' => $this->payment->id, 'amount' => '1500']), ValidationException::class);
    $explained = setDispute($this->f->finance, $this->f->order, ['payment_id' => $this->payment->id, 'amount' => '1500', 'amount_note' => 'يشمل تركيبًا مستقلًا خارج الدفعة.']);
    expect($explained->amount_minor)->toBe(150_000);
});

it('M-40: requested → attached → needs correction → attached again → accepted; refused from other states without effect', function () {
    $requested = app(T00RequestDocument::class)->handle($this->f->finance, $this->f->order, ['kind' => 'tax_invoice', 'event' => 'payment', 'payment_id' => $this->payment->id], agrKey());
    expect($requested->status)->toBe(ExternalDocumentStatus::Requested);
    setRefused(fn () => app(T02AcceptDocument::class)->handle($this->f->finance, $requested, $requested->lock_version, agrKey()), InvalidTransition::class, 'not_from_requested');
    setRefused(fn () => app(T03RequestDocumentCorrection::class)->handle($this->f->finance, $requested, 'x', $requested->lock_version, agrKey()), InvalidTransition::class, 'not_from_requested');

    $attached = setLinkDocument($this->f->finance, $this->f->order, ['number' => 'INV-1'], $requested);
    expect($attached->status)->toBe(ExternalDocumentStatus::Attached);
    setRefused(fn () => setLinkDocument($this->f->finance, $this->f->order, ['number' => 'INV-1'], $attached), InvalidTransition::class, 'not_from_attached');

    $fix = app(T03RequestDocumentCorrection::class)->handle($this->f->finance, $attached, 'المبلغ لا يطابق الدفعة.', $attached->lock_version, agrKey());
    expect($fix->status)->toBe(ExternalDocumentStatus::NeedsCorrection);
    setRefused(fn () => app(T02AcceptDocument::class)->handle($this->f->finance, $fix, $fix->lock_version, agrKey()), InvalidTransition::class, 'not_from_needs_correction');

    $again = setLinkDocument($this->f->finance, $this->f->order, ['number' => 'INV-1', 'amount' => '500'], $fix);
    expect($again->status)->toBe(ExternalDocumentStatus::Attached)
        ->and($again->attachments()->count())->toBe(2);
    $accepted = app(T02AcceptDocument::class)->handle($this->f->finance, $again, $again->lock_version, agrKey());
    expect($accepted->status)->toBe(ExternalDocumentStatus::Accepted);
    foreach (['T02' => fn () => app(T02AcceptDocument::class)->handle($this->f->finance, $accepted, $accepted->lock_version, agrKey()),
        'T03' => fn () => app(T03RequestDocumentCorrection::class)->handle($this->f->finance, $accepted, 'x', $accepted->lock_version, agrKey()),
        'T04' => fn () => setLinkDocument($this->f->finance, $this->f->order, [], $accepted)] as $attempt) {
        setRefused($attempt, InvalidTransition::class, 'not_from_accepted');
    }
});

it('FIN-10: an invoice number is unique per source; the same number from another source is a different document; P-44 only', function () {
    setLinkDocument($this->f->finance, $this->f->order, ['source' => 'Qoyod', 'number' => 'INV-2026-0398']);
    setRefused(fn () => setLinkDocument($this->f->finance, $this->f->order, ['source' => ' qoyod ', 'number' => 'inv-2026-0398']), InvalidTransition::class, 'duplicate_number');
    setLinkDocument($this->f->finance, $this->f->order, ['source' => 'Zoho Books', 'number' => 'INV-2026-0398']);
    setRefused(fn () => setLinkDocument($this->f->manager, $this->f->order, ['number' => 'INV-9']), AuthorizationException::class);
    // Linking never changes the money.
    expect(MoneyLedger::collected($this->f->order->id)->minor)->toBe(100_000);
});
