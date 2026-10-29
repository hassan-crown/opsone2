<?php

use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementChange;
use App\Models\Agreements\AgreementDocument;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Commissions/CommissionTestHelpers.php';
require_once __DIR__.'/../Portal/PortalTestHelpers.php';
require_once __DIR__.'/../Settlement/SettlementTestHelpers.php';

/*
| HTTP drivers of the money acceptance scenarios 9..14 («اختبارات ترابط ملزمة»). Every scenario step goes through the
| real endpoints as the acting role (docs/p6-finance-api.md); fixtures come from the domain test helpers.
*/

function accMKey(): string
{
    return 'acc-'.Str::uuid();
}

function accMPdf(string $name = 'evidence.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".Str::random(16));
}

/**
 * Multipart JSON write as the given staff member.
 *
 * @param  array<string, mixed>  $data
 */
function accMPost(User $actor, string $uri, array $data): TestResponse
{
    return test()->actingAs($actor)->post($uri, $data, ['Accept' => 'application/json']);
}

/**
 * Sales prepares and sends a quote of the given lines; the owner records the customer's external approval with evidence.
 *
 * @param  list<array<string, mixed>>  $lines
 */
function accMAgree(object $f, array $lines, ?User $preparer = null, ?User $approver = null): Agreement
{
    $preparer ??= $f->sales;
    $approver ??= $f->owner;
    $id = (int) test()->actingAs($preparer)->postJson("/orders/{$f->order->id}/agreement/quotes", ['idempotency_key' => accMKey()])
        ->assertCreated()->json('document_id');
    $document = AgreementDocument::query()->findOrFail($id);
    test()->actingAs($preparer)->putJson("/agreement/documents/{$id}", [
        ...agrQuoteData(lines: $lines), 'idempotency_key' => accMKey(), 'lock_version' => $document->lock_version,
    ])->assertOk();
    test()->actingAs($preparer)->postJson("/agreement/documents/{$id}/send", [
        'idempotency_key' => accMKey(), 'lock_version' => $document->refresh()->lock_version,
    ])->assertOk();
    $document->refresh();
    accMPost($approver, "/agreement/documents/{$id}/external-approval", [
        'idempotency_key' => accMKey(), 'lock_version' => $document->lock_version, 'channel' => 'message',
        'decided_on' => now()->toDateString(), 'content_hash' => $document->content_hash, 'evidence' => accMPdf(),
    ])->assertOk();

    return Agreement::query()->where('order_id', $f->order->id)->where('status', 'active')->firstOrFail();
}

/**
 * Finance records a received transfer (FinRecordPayment); answers the HTTP response (payment_id, receipt, finance).
 */
function accMPay(User $actor, Order $order, string $amount, ?string $reference = null): TestResponse
{
    return accMPost($actor, "/orders/{$order->id}/finance/payments", [
        'amount' => $amount, 'method' => 'transfer', 'paid_on' => now()->toDateString(),
        'reference' => $reference ?? 'FT'.Str::upper(Str::random(10)), 'acknowledge_similar' => '1',
        'idempotency_key' => accMKey(), 'evidence' => accMPdf('transfer.pdf'),
    ]);
}

function accMPaid(User $actor, Order $order, string $amount): int
{
    return (int) accMPay($actor, $order, $amount)->assertCreated()->json('payment_id');
}

/**
 * Creates, prices and presents an agreement change over HTTP; answers the change awaiting approval.
 *
 * @param  list<array<string, mixed>>  $lines
 * @param  list<string>  $affected
 */
function accMChange(User $actor, Order $order, array $lines, array $affected = [], int $delayDays = 0): AgreementChange
{
    $id = (int) test()->actingAs($actor)->postJson("/orders/{$order->id}/agreement/changes", [
        'description' => 'تعديل البنود', 'idempotency_key' => accMKey(),
    ])->assertCreated()->json('change_id');
    $change = AgreementChange::query()->findOrFail($id);
    test()->actingAs($actor)->putJson("/agreement/changes/{$id}", [
        ...agrQuoteData(lines: $lines), 'description' => 'تعديل البنود', 'affected' => $affected, 'delay_days' => $delayDays,
        'idempotency_key' => accMKey(), 'lock_version' => $change->lock_version,
    ])->assertOk();
    test()->actingAs($actor)->postJson("/agreement/changes/{$id}/present", [
        'idempotency_key' => accMKey(), 'lock_version' => $change->refresh()->lock_version,
    ])->assertOk();

    return $change->refresh();
}

/**
 * Records the customer's approval of the presented change content (external, with evidence).
 */
function accMApplyChange(User $actor, AgreementChange $change, ?string $hash = null, ?string $key = null): TestResponse
{
    $change->refresh();

    return accMPost($actor, "/agreement/changes/{$change->id}/apply", [
        'idempotency_key' => $key ?? accMKey(), 'lock_version' => $change->lock_version, 'channel' => 'phone',
        'decided_on' => now()->toDateString(), 'content_hash' => $hash ?? $change->content_hash, 'evidence' => accMPdf(),
    ]);
}

/**
 * FinRefund (P-42): a refund request on a payment of the order.
 */
function accMRefund(User $actor, Order $order, int $paymentId, string $amount): TestResponse
{
    return test()->actingAs($actor)->postJson("/orders/{$order->id}/finance/refunds", [
        'payment_id' => $paymentId, 'amount' => $amount, 'method' => 'transfer', 'reason' => 'تسوية متفق عليها مع العميل.',
        'idempotency_key' => accMKey(),
    ]);
}

/**
 * FinRefundExec (P-43): the refund was paid outside the system; its proof is recorded.
 */
function accMRefundConfirm(User $actor, int $refundId, ?string $reference = null): TestResponse
{
    return accMPost($actor, "/finance/refunds/{$refundId}/confirm", [
        'reference' => $reference ?? 'TRX'.Str::upper(Str::random(8)), 'proof' => accMPdf('refund.pdf'), 'idempotency_key' => accMKey(),
    ]);
}

/**
 * M-33 over HTTP: a draft for the member's period, approved and paid at once with execution details and proof.
 */
function accMPayout(User $actor, User $member): TestResponse
{
    $created = test()->actingAs($actor)->postJson("/finance/commissions/members/{$member->id}/payouts", [
        'period_from' => now()->startOfMonth()->toDateString(), 'period_to' => now()->toDateString(), 'idempotency_key' => accMKey(),
    ]);
    if ($created->status() !== 201) {
        return $created;
    }

    return accMPost($actor, '/finance/commissions/payouts/'.$created->json('payout_id').'/approve', [
        'pay_now' => '1', 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'SAL-'.Str::upper(Str::random(6)),
        'proof' => accMPdf('payout.pdf'), 'idempotency_key' => accMKey(),
    ]);
}

/**
 * The member's commission statement as a P-94 holder reads it.
 *
 * @return array<string, mixed>
 */
function accMStatement(User $viewer, User $member): array
{
    return (array) test()->actingAs($viewer)->getJson("/finance/commissions/members/{$member->id}")->assertOk()->json();
}

/**
 * The order's money page payload (finance + settlement) as the viewer reads it.
 *
 * @return array<string, mixed>
 */
function accMFinance(User $viewer, Order $order): array
{
    // The JSON variant carries only `finance`; the page props carry the settlement too.
    $page = test()->actingAs($viewer)->get("/orders/{$order->id}/finance")->assertOk()->viewData('page');

    return json_decode((string) json_encode($page['props']), true);
}

/**
 * Audit actions recorded for the order (subject = order), oldest first.
 *
 * @return list<string>
 */
function accMAudit(string $prefix): array
{
    return array_values(DB::table('audit_events')->where('action', 'like', $prefix.'%')->orderBy('id')->pluck('action')->all());
}
