<?php

/**
 * Cancellation of a made-to-order order (SRS-COM-14, WF-13, FUL-12, FIN-14, INT-08; M-34, M-04-T04/T05; ADR-0020) and
 * the mandatory scenario «إلغاء ورد»: work, stock and money each settled from its own source, and no refund above
 * what is refundable — with the document numbers RC / CN / RF.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M34Cancellation\T04RejectCancellation;
use App\Agreements\OrderAgreement;
use App\Commissions\SettlementHolds;
use App\Enums\TaskStatus;
use App\Finance\MoneyLedger;
use App\Models\Agreements\Agreement;
use App\Models\Cancellation\OrderCancellationLine;
use App\Models\Finance\Payment;
use App\Models\Finance\Receipt;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Settlement\SettlementItem;
use App\Models\StateTransition;
use App\Models\Task;
use App\Settlement\SettlementFollowUps;
use App\Support\LockingTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/CancellationTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
});

it('cancels a custom order with money collected and follows the refund until it is made (scenario «إلغاء ورد»)', function () {
    $f = $this->f;
    $year = now()->year;
    finAgree($f, '10000');
    $payment = finPay($f->owner, $f->order, '4000');
    expect(Receipt::query()->where('payment_id', $payment->id)->value('number'))->toBe("RC-{$year}-000001");
    $openTasks = Task::query()->where('order_id', $f->order->id)->whereIn('status', TaskStatus::open())->count();

    $preview = cnPreview($f->order, ['scope' => 'full', 'retained' => '1200']);
    expect($preview['settlement']['money'])->toMatchArray([
        'collected_minor' => 400000, 'value_before_minor' => 1000000, 'value_after_minor' => 120000, 'refund_minor' => 280000,
    ])->and($preview['order']['status_after'])->toBe('cancelled')
        ->and($preview['stops']['tasks'])->toHaveCount($openTasks);

    $cancellation = cnCancel($f->owner, $f->order, ['scope' => 'full', 'retained' => '1200']);
    $order = $f->order->refresh();

    expect($cancellation->number)->toBe("CN-{$year}-000001")
        ->and($cancellation->status->value)->toBe('accepted')
        ->and($cancellation->impact_hash)->toBe($preview['hash'])
        ->and($order->status)->toBe('cancelled')
        ->and(Task::query()->where('order_id', $order->id)->whereIn('status', TaskStatus::open())->count())->toBe(0)
        // The agreement keeps the retained work only; the schedule follows it and the rest is credit to settle.
        ->and(OrderAgreement::current($order)?->total_minor)->toBe(120000)
        ->and(OrderAgreement::current($order)?->version)->toBe(2)
        ->and(array_sum(array_column(finDues($order), 'amount')))->toBe(120000)
        ->and(MoneyLedger::collected($order->id)->minor)->toBe(400000)
        ->and(MoneyLedger::credit($order->id)->minor)->toBe(280000)
        // No automatic refund (P-18): the follow-up holds the refund to make.
        ->and(DB::table('refunds')->count())->toBe(0)
        ->and(cnItems($order))->toBe(['refund' => 'open'])
        ->and(cnFollowUp($order)?->status)->toBe('open')
        ->and(app(SettlementHolds::class)->holdOf($order))->toBe('cancellation');

    $machines = StateTransition::query()->whereIn('machine', ['M-34', 'M-04'])->orderBy('id')->get()->map(fn ($t) => $t->machine->value.'-'.$t->transition)->all();
    expect(array_slice($machines, -3))->toBe(['M-04-T04', 'M-04-T05', 'M-34-T03'])
        ->and(OutboxMessage::query()->where('idempotency_key', 'like', "cancellation:{$cancellation->id}:accepted%")->count())->toBeGreaterThan(0);

    // The refund through P6 M-12 (its own limit): voucher RF-…, then the item is done and the follow-up closes.
    $refund = setRefund($f->owner, $order, Payment::query()->findOrFail($payment->id), '2800');
    expect(cnFollowUp($order)?->status)->toBe('open');
    $refund = setPaid($f->owner, $refund);
    expect($refund->number)->toBe("RF-{$year}-000001")
        ->and(cnItems($order))->toBe(['refund' => 'done'])
        ->and(cnFollowUp($order)?->status)->toBe('closed')
        ->and(MoneyLedger::collected($order->id)->minor)->toBe(120000)
        ->and(app(SettlementHolds::class)->holdOf($order))->not->toBe('cancellation');
});

it('recalculates the agreement and the dues exactly on a partial cancellation', function () {
    $f = $this->f;
    agrAgreed($f, agrQuoteData()['lines']); // 6 × 14,000 + 6 × 1,250 = 91,500 (inclusive)
    expect(finDues($f->order))->toBe(['first' => ['amount' => 4575000, 'allocated' => 0], 'final' => ['amount' => 4575000, 'allocated' => 0]]);

    $scope = ['scope' => 'partial', 'lines' => [['ref' => 'a0', 'quantity' => '2']]];
    $preview = cnPreview($f->order, $scope);
    cnCancel($f->owner, $f->order, $scope);
    $order = $f->order->refresh();

    $agreement = OrderAgreement::current($order);
    expect($agreement?->total_minor)->toBe(6350000)
        ->and($agreement?->content['lines'][0]['quantity'])->toBe('4')
        ->and(finDues($order))->toBe(['first' => ['amount' => 3175000, 'allocated' => 0], 'final' => ['amount' => 3175000, 'allocated' => 0]])
        ->and($order->status)->toBe('in_progress')
        ->and(OrderCancellationLine::query()->sole()->quantity_milli)->toBe(2000)
        ->and(OrderCancellationLine::query()->sole()->value_minor)->toBe(2800000)
        // Preview equals the applied result.
        ->and($preview['settlement']['agreement']['total_minor'])->toBe($agreement?->total_minor)
        ->and(array_column($preview['settlement']['agreement']['dues'], 'amount_after_minor'))->toBe([3175000, 3175000])
        ->and(cnFollowUp($order))->toBeNull();
});

it('keeps an amount discount in proportion when lines are cancelled', function () {
    $f = $this->f;
    $quote = agrSend($f->sales, agrQuote($f->sales, $f->order, agrQuoteData(['type' => 'amount', 'value' => '9150'])));
    agrApprove($f->owner, $quote);
    expect(OrderAgreement::current($f->order)?->total_minor)->toBe(8235000);

    cnCancel($f->owner, $f->order, ['scope' => 'partial', 'lines' => [['ref' => 'a0', 'quantity' => '2']]]);
    $agreement = OrderAgreement::current($f->order->refresh());

    // Base 63,500, discount 9,150 × 63,500 / 91,500 = 6,350 → 57,150; dues 50% / rest.
    expect($agreement?->discount_minor)->toBe(635000)
        ->and($agreement?->total_minor)->toBe(5715000)
        ->and(finDues($f->order))->toBe(['first' => ['amount' => 2857500, 'allocated' => 0], 'final' => ['amount' => 2857500, 'allocated' => 0]]);
});

it('refuses a confirm whose reviewed impact went stale (409) without any effect', function () {
    $f = $this->f;
    finAgree($f, '10000');
    $scope = ['scope' => 'full'];
    $stale = cnPreview($f->order, $scope)['hash'];
    finPay($f->owner, $f->order, '1000');

    cnRefused(fn () => cnCancel($f->owner, $f->order, $scope, hash: $stale), InvalidTransition::class, 'preview_stale');
    expect(cnCancel($f->owner, $f->order, $scope)->status->value)->toBe('accepted');
});

it('suspends new work while a request is pending and lifts only its own suspension on rejection', function () {
    $f = $this->f;
    $request = cnRequestOnly($f->owner, $f->order);
    expect($f->order->refresh()->status)->toBe('cancellation_pending')
        ->and($request->number)->toBe('CN-'.now()->year.'-000001');

    cnRefused(fn () => cnRequestOnly($f->owner, $f->order), InvalidTransition::class, 'request_pending');

    app(T04RejectCancellation::class)->handle($f->owner, $request, 'الأعمال قائمة حسب العقد.', $request->lock_version);
    expect($f->order->refresh()->status)->toBe('in_progress')
        ->and($request->refresh()->status->value)->toBe('rejected')
        ->and($request->active_key)->toBeNull();
});

it('accepts a pending request with the reviewer\'s scope and returns the order to its previous state on partial', function () {
    $f = $this->f;
    agrAgreed($f, agrQuoteData()['lines']);
    $request = cnRequestOnly($f->owner, $f->order);
    $scope = ['scope' => 'partial', 'lines' => [['ref' => 'a1', 'quantity' => '6']]];

    $done = cnAccept($f->owner, $request, $scope);
    expect($done->status->value)->toBe('accepted')->and($done->scope)->toBe('partial')
        ->and($f->order->refresh()->status)->toBe('in_progress')
        ->and(OrderAgreement::current($f->order)?->total_minor)->toBe(8400000);
});

it('closes the settlement follow-up only when every item is done', function () {
    $f = $this->f;
    finAgree($f, '10000');
    $payment = finPay($f->owner, $f->order, '3000');
    $po = cnPurchaseFor($f->order);

    cnCancel($f->owner, $f->order, ['scope' => 'full']);
    $order = $f->order->refresh();
    expect(cnItems($order))->toBe(['refund' => 'open', "supplier:{$po->id}" => 'open']);

    setPaid($f->owner, setRefund($f->owner, $order, Payment::query()->findOrFail($payment->id), '3000'));
    expect(cnItems($order))->toBe(['refund' => 'done', "supplier:{$po->id}" => 'open'])
        ->and(cnFollowUp($order)?->status)->toBe('open');

    // Someone without the supplier permission cannot settle the supplier item another way.
    $supplierItem = SettlementItem::query()->where('kind', 'supplier')->sole();
    expect(fn () => app(SettlementFollowUps::class)->resolve($f->sales, $supplierItem, 'done', 'تم', $supplierItem->lock_version))
        ->toThrow(AuthorizationException::class);

    // The supplier order's remainder is cancelled in procurement: the item follows its source.
    $po->forceFill(['status' => 'cancelled_remainder'])->save();
    LockingTransaction::run(fn () => app(SettlementFollowUps::class)->sync(Order::query()->lockForUpdate()->findOrFail($order->id)));
    expect(cnItems($order))->toBe(['refund' => 'done', "supplier:{$po->id}" => 'done'])
        ->and(cnFollowUp($order)?->status)->toBe('closed');
});

it('accepts a cancellation before any agreement without a zero agreement or a settlement', function () {
    $f = $this->f;
    cnCancel($f->owner, $f->order, ['scope' => 'full']);

    expect($f->order->refresh()->status)->toBe('cancelled')
        ->and(Agreement::query()->count())->toBe(0)
        ->and(cnFollowUp($f->order))->toBeNull();
});
