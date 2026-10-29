<?php

/**
 * «قبول النظام المتكامل» — row «موافقة وتحويل خارجيان»:
 * «إثباتان موثقان من المخول يحققان نتيجتيهما دون دخول العميل أو تجاوز مصطنع.»
 * plus the binding test «تحويل واحد سجلته الإدارة ورفع العميل إيصالَه»:
 * «يربط الإثبات بالمقبوض نفسه، وسند وتخصيص واحدان فقط.»
 *
 * Traceability: SRS-AGR-05 (external approval recorded by the authorized person with method, date and evidence — the
 * need is met without the customer signing in, the approved content kept), SRS-AGR-04 (only the sent content can be
 * approved), SRS-FIN-04 (payment recorded by the authorized person with amount, method, date, reference and evidence),
 * SRS-FIN-05 (customer transfer receipt awaiting the authorized decision), SRS-FIN-06 (allocation oldest due first),
 * SRS-INT-04 (one original receipt per money even when the customer uploads proof of the same transfer; no second
 * receipt), SRS-INT-07 (stage collection condition met by that money). Permissions P-34, P-39, P-40, P-68.
 *
 * Every business action goes through HTTP as its actor: staff on the web session, the customer on the portal guard.
 * Fixture setup (organisation, members, catalogue, limits) uses the existing helpers.
 */

use App\Enums\ActionStatus;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\CustomerDecision;
use App\Models\AuditEvent;
use App\Models\Finance\MoneyEntry;
use App\Models\Finance\Payment;
use App\Models\Finance\PaymentProof;
use App\Models\Finance\Receipt;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\StateTransition;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Finance/FinanceTestHelpers.php';
require_once __DIR__.'/../Portal/PortalTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = accI03Fixture();
    $this->order = accI03OpenWithSentQuote($this->f);
    $this->quote = AgreementDocument::query()->where('order_id', $this->order->id)->sole();
    $this->year = now()->format('Y');
});

it('external approval with evidence by an authorized member activates the agreement without the customer signing in', function () {
    $quote = $this->quote->refresh();
    $this->actingAs($this->f->manager)->post("/agreement/documents/{$quote->id}/external-approval", [
        'idempotency_key' => agrKey(), 'lock_version' => $quote->lock_version, 'channel' => 'message',
        'decided_on' => now()->toDateString(), 'note' => 'وافق العميل عبر واتساب', 'content_hash' => $quote->content_hash,
        'evidence' => agrPdf('whatsapp.pdf', 'approval'),
    ], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('agreement.agreement.version', 1)
        ->assertJsonPath('agreement.agreement.total_minor', 2_000_000)
        ->assertJsonPath('agreement.agreement.decision.channel', 'message');

    $decision = CustomerDecision::query()->sole();
    expect($quote->refresh()->status->value)->toBe('approved')
        ->and(Agreement::query()->where('order_id', $this->order->id)->sole()->status->value)->toBe('active')
        ->and($decision->method)->toBe(CustomerDecision::METHOD_EXTERNAL)
        ->and($decision->channel)->toBe('message')
        ->and($decision->recorded_by)->toBe($this->f->manager->id)
        ->and($decision->content_hash)->toBe($quote->content_hash)
        ->and(accI03Files($decision))->toBe(1)
        ->and(statusOf(actionOf($this->order, 'quote_approval')))->toBe(ActionStatus::Verified)
        ->and(accI03Dues($this->order))->toBe(['first' => [1_000_000, 0], 'final' => [1_000_000, 0]])
        ->and(AuditEvent::query()->where('action', 'agreement_document.approved')->where('actor_user_id', $this->f->manager->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'agreement.activated')->count())->toBe(1);

    // No artificial bypass: the customer never signed in and was never asked to.
    accI03CustomerNeverSignedIn();

    // A second identical submission with a new key is refused (content already decided), nothing is recorded twice.
    $this->actingAs($this->f->manager)->post("/agreement/documents/{$quote->id}/external-approval", [
        'idempotency_key' => agrKey(), 'lock_version' => $quote->refresh()->lock_version, 'channel' => 'paper',
        'decided_on' => now()->toDateString(), 'content_hash' => $quote->content_hash, 'evidence' => agrPdf(),
    ], ['Accept' => 'application/json'])->assertStatus(409);
    expect(CustomerDecision::query()->count())->toBe(1)->and(Agreement::query()->count())->toBe(1);
});

it('refuses the external approval to a member without P-34 (403) and without evidence (422), without effect', function () {
    $quote = $this->quote->refresh();
    $form = fn (array $extra = []) => [
        'idempotency_key' => agrKey(), 'lock_version' => $quote->lock_version, 'channel' => 'phone',
        'decided_on' => now()->toDateString(), 'content_hash' => $quote->content_hash, 'evidence' => agrPdf(), ...$extra,
    ];
    $before = accI03Snapshot();

    // Sales (P-30, sees the order) and finance (P-39/P-40) are not authorized to document an approval.
    $this->actingAs($this->f->sales)->post("/agreement/documents/{$quote->id}/external-approval", $form(), ['Accept' => 'application/json'])->assertForbidden();
    $this->actingAs($this->f->finance)->post("/agreement/documents/{$quote->id}/external-approval", $form(), ['Accept' => 'application/json'])->assertForbidden();
    $this->actingAs($this->f->nobody)->post("/agreement/documents/{$quote->id}/external-approval", $form(), ['Accept' => 'application/json'])->assertForbidden();
    // An authorized member still needs the documented proof.
    $this->actingAs($this->f->manager)->post("/agreement/documents/{$quote->id}/external-approval", $form(['evidence' => null]), ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('evidence');

    expect(accI03Snapshot())->toBe($before)
        ->and($quote->refresh()->status->value)->toBe('awaiting_customer')
        ->and(Agreement::query()->count())->toBe(0);
});

it('a bank transfer recorded with evidence by an authorized member is collected once, allocated and meets the stage condition', function () {
    accI03ExternalApproval($this, $this->f->manager, $this->quote);
    $before = accI03Snapshot();

    // Unauthorized members are refused without effect.
    accI03Pay($this, $this->f->sales, $this->order, '10000.00', 'FT2610204471')->assertForbidden();
    accI03Pay($this, $this->f->manager, $this->order, '10000.00', 'FT2610204471')->assertForbidden();
    accI03Pay($this, $this->f->nobody, $this->order, '10000.00', 'FT2610204471')->assertForbidden();
    expect(accI03Snapshot())->toBe($before);

    $response = accI03Pay($this, $this->f->finance, $this->order, '10000.00', 'FT2610204471')->assertCreated();
    $payment = Payment::query()->sole();
    expect($response->json('receipt'))->toBe("RC-{$this->year}-000001")
        ->and($response->json('finance.summary.collected_minor'))->toBe(1_000_000)
        ->and($payment->amount_minor)->toBe(1_000_000)
        ->and($payment->method->value)->toBe('transfer')
        ->and($payment->recorded_by)->toBe($this->f->finance->id)
        ->and(accI03Files($payment))->toBe(1)
        ->and(Receipt::query()->count())->toBe(1)
        ->and(accI03Dues($this->order))->toBe(['first' => [1_000_000, 1_000_000], 'final' => [1_000_000, 0]])
        ->and(statusOf(actionOf($this->order, 'first_payment')))->toBe(ActionStatus::Verified)
        ->and(AuditEvent::query()->where('action', 'finance.payment_recorded')->where('actor_user_id', $this->f->finance->id)->count())->toBe(1);

    // Both results achieved without the customer ever signing in.
    accI03CustomerNeverSignedIn();
});

it('one transfer recorded by staff and uploaded by the customer: linked to the same payment, one receipt and one allocation (INT-04)', function () {
    accI03ExternalApproval($this, $this->f->manager, $this->quote);
    accI03Pay($this, $this->f->finance, $this->order, '10000.00', 'FT2610204471')->assertCreated();
    $payment = Payment::query()->sole();
    $journals = DB::table('money_journals')->count();
    $allocations = MoneyEntry::query()->where('order_id', $this->order->id)->whereNotNull('order_due_id')->count();
    expect($allocations)->toBe(1);

    // The customer, signed in to their workspace, uploads the receipt of the same transfer (reference spelled differently).
    Auth::forgetGuards();
    actingAsCustomer($this->f->customer);
    $this->post("/portal/orders/{$this->order->id}/proofs", [
        'amount' => '10000.00', 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'ft-2610 2044 71',
        'file' => agrPdf('bank-receipt.pdf', 'customer copy'),
    ])->assertRedirect("/portal/orders/{$this->order->id}");
    Auth::forgetGuards();
    $proof = PaymentProof::query()->sole();
    expect($proof->status->value)->toBe('under_review')->and($proof->payment_id)->toBeNull()
        ->and(Payment::query()->count())->toBe(1)->and(Receipt::query()->count())->toBe(1);

    // The authorized member confirms it: it is linked to the existing payment, nothing new is money.
    $this->actingAs($this->f->finance)->postJson("/finance/proofs/{$proof->id}/confirm", [
        'idempotency_key' => agrKey(), 'lock_version' => $proof->lock_version, 'amount' => '10000.00', 'paid_on' => now()->toDateString(),
    ])->assertOk()->assertJsonPath('finance.summary.collected_minor', 1_000_000);

    expect($proof->refresh()->status->value)->toBe('confirmed')
        ->and($proof->payment_id)->toBe($payment->id)
        ->and(Payment::query()->count())->toBe(1)
        ->and(Receipt::query()->count())->toBe(1)
        ->and(Receipt::query()->sole()->number)->toBe("RC-{$this->year}-000001")
        ->and(DB::table('money_journals')->count())->toBe($journals)
        ->and(MoneyEntry::query()->where('order_id', $this->order->id)->whereNotNull('order_due_id')->count())->toBe(1)
        ->and(accI03Dues($this->order))->toBe(['first' => [1_000_000, 1_000_000], 'final' => [1_000_000, 0]])
        ->and(StateTransition::query()->where('machine', 'M-10')->where('transition', 'T01')->sole()->context['linked_existing'] ?? null)->toBeTrue()
        ->and(StateTransition::query()->where('machine', 'M-11')->where('transition', 'T01')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'finance.proof_confirmed')->count())->toBe(1);

    // The customer sees one paid amount, not two.
    actingAsCustomer($this->f->customer);
    $this->get("/portal/orders/{$this->order->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->where('order.finance.pending_proofs_minor', 0));
});

it('refuses proof confirmation to a member without P-40 and another customer\'s upload, without effect', function () {
    accI03ExternalApproval($this, $this->f->manager, $this->quote);
    accI03Pay($this, $this->f->finance, $this->order, '10000.00', 'FT2610204471')->assertCreated();
    actingAsCustomer($this->f->customer);
    $this->post("/portal/orders/{$this->order->id}/proofs", [
        'amount' => '10000.00', 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'FT2610204471', 'file' => agrPdf(),
    ])->assertRedirect();
    Auth::forgetGuards();
    $proof = PaymentProof::query()->sole();

    // Another customer cannot reach this order (foreign id = 404).
    actingAsCustomer($this->f->other);
    $this->post("/portal/orders/{$this->order->id}/proofs", [
        'amount' => '10000.00', 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'FT2610204471', 'file' => agrPdf(),
    ])->assertNotFound();
    Auth::forgetGuards();

    $before = accI03Snapshot();
    foreach ([$this->f->sales, $this->f->manager, $this->f->nobody] as $member) {
        $this->actingAs($member)->postJson("/finance/proofs/{$proof->id}/confirm", [
            'idempotency_key' => agrKey(), 'lock_version' => $proof->lock_version, 'amount' => '10000.00', 'paid_on' => now()->toDateString(),
        ])->assertForbidden();
    }
    expect(accI03Snapshot())->toBe($before)
        ->and($proof->refresh()->status->value)->toBe('under_review')
        ->and(PaymentProof::query()->count())->toBe(1);
});

it('proofs documented before the agreement stage still count when the stage opens (no re-approval, no second payment)', function () {
    $early = accI03OpenWithSentQuote($this->f, advance: false);
    $quote = AgreementDocument::query()->where('order_id', $early->id)->sole();
    accI03ExternalApproval($this, $this->f->manager, $quote);
    accI03Pay($this, $this->f->finance, $early, '10000.00', 'FT-EARLY-0001')->assertCreated();
    expect(stageOf($early, 'measure')->status->value)->toBe('in_progress');

    $needs = taskOf($early, 'needs');
    $this->actingAs($this->f->sales)->postJson("/tasks/{$needs->id}/start")->assertOk();
    $this->actingAs($this->f->sales)->postJson("/tasks/{$needs->id}/complete", ['result_note' => 'تم'])->assertOk();
    foreach (['measure', 'design'] as $key) {
        $this->actingAs($this->f->manager)->postJson("/orders/{$early->id}/stages/".stageOf($early, $key)->id.'/complete', [
            'lock_version' => $early->refresh()->lock_version,
        ])->assertOk();
    }

    expect(statusOf(actionOf($early, 'quote_approval')))->toBe(ActionStatus::Verified)
        ->and(statusOf(actionOf($early, 'first_payment')))->toBe(ActionStatus::Verified)
        ->and(CustomerDecision::query()->whereIn('subject_id', [$quote->id])->count())->toBe(1)
        ->and(Payment::query()->where('order_id', $early->id)->count())->toBe(1);
    $this->actingAs($this->f->manager)->postJson("/orders/{$early->id}/stages/".stageOf($early, 'agreement')->id.'/complete', [
        'lock_version' => $early->refresh()->lock_version,
    ])->assertOk();
    expect(stageOf($early, 'manufacture')->status->value)->toBe('in_progress');
});

/**
 * Fixture: the orders fixture plus a manager (P-34), a finance officer (P-39/P-40) and a member without any role;
 * sales of A opens orders; the customer (and another one of branch A) has workspace login enabled.
 */
function accI03Fixture(): object
{
    $f = ordersFixture();
    $org = $f->org;
    $f->manager = $org->member('manager', [$org->a]);
    $f->finance = $org->member('finance', [$org->a]);
    $f->nobody = $org->member(null, [$org->a]);
    $f->customer = $f->inA;
    $f->customer->forceFill(['portal_login_enabled' => true])->save();
    $f->other = $f->inB;
    $f->other->forceFill(['portal_login_enabled' => true])->save();
    agrLimit('sales', 1000);
    Auth::forgetGuards();

    return $f;
}

/**
 * Sales opens a custom order (no measurement) and prepares and sends a 20,000.00 SAR quote (VAT-inclusive) over HTTP;
 * the needs task is completed and the manager completes the first two stages, so the agreement stage is active.
 */
function accI03OpenWithSentQuote(object $f, bool $advance = true): Order
{
    test()->actingAs($f->sales)->postJson('/orders', [
        'idempotency_key' => (string) Str::uuid(), 'customer_id' => $f->customer->id, 'branch_id' => $f->customer->branch_id,
        'custom' => ['need_summary' => 'مطبخ', 'needs_measurement' => false, 'lines' => [['type' => 'custom_need', 'description' => 'مطبخ كامل']]],
    ])->assertCreated();
    $order = Order::query()->latest('id')->firstOrFail();
    $id = test()->postJson("/orders/{$order->id}/agreement/quotes", ['idempotency_key' => agrKey()])->assertCreated()->json('document_id');
    $quote = AgreementDocument::query()->findOrFail($id);
    test()->putJson("/agreement/documents/{$quote->id}", [
        ...agrQuoteData(lines: [finLine('مطبخ', '20000')]), 'idempotency_key' => agrKey(), 'lock_version' => $quote->lock_version,
    ])->assertOk();
    test()->postJson("/agreement/documents/{$quote->id}/send", ['idempotency_key' => agrKey(), 'lock_version' => $quote->refresh()->lock_version])->assertOk();

    if (! $advance) {
        Auth::forgetGuards();

        return $order->refresh();
    }
    // The required work before the agreement stage, as its holder and the stage owner would do it.
    $needs = taskOf($order, 'needs');
    test()->actingAs($f->sales)->postJson("/tasks/{$needs->id}/start")->assertOk();
    test()->actingAs($f->sales)->postJson("/tasks/{$needs->id}/complete", ['result_note' => 'تم'])->assertOk();
    foreach (['measure', 'design'] as $key) {
        test()->actingAs($f->manager)->postJson("/orders/{$order->id}/stages/".stageOf($order, $key)->id.'/complete', [
            'lock_version' => $order->refresh()->lock_version,
        ])->assertOk();
    }
    expect(stageOf($order, 'agreement')->status->value)->toBe('in_progress');
    Auth::forgetGuards();

    return $order->refresh();
}

function accI03ExternalApproval(object $test, User $actor, AgreementDocument $quote): void
{
    $quote->refresh();
    $test->actingAs($actor)->post("/agreement/documents/{$quote->id}/external-approval", [
        'idempotency_key' => agrKey(), 'lock_version' => $quote->lock_version, 'channel' => 'paper',
        'decided_on' => now()->toDateString(), 'content_hash' => $quote->content_hash, 'evidence' => agrPdf('signed.pdf', 'signed'),
    ], ['Accept' => 'application/json'])->assertOk();
}

function accI03Pay(object $test, User $actor, Order $order, string $amount, string $reference): TestResponse
{
    return $test->actingAs($actor)->withHeader('Idempotency-Key', 'acc-i03-'.Str::uuid())->post("/orders/{$order->id}/finance/payments", [
        'amount' => $amount, 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => $reference,
        'lock_version' => finVersion($order), 'evidence' => agrPdf('transfer.pdf', $reference),
    ], ['Accept' => 'application/json']);
}

/**
 * @return array<string, array{0: int, 1: int}> due reference → [amount, allocated] in halalas
 */
function accI03Dues(Order $order): array
{
    return array_map(fn (array $d) => [$d['amount'], $d['allocated']], finDues($order));
}

function accI03Files(Model $owner): int
{
    return DB::table('attachments')->where('attachable_type', $owner->getMorphClass())->where('attachable_id', $owner->getKey())->count();
}

function accI03CustomerNeverSignedIn(): void
{
    expect(OutboxMessage::query()->where('idempotency_key', 'like', 'portal-otp:%')->count())->toBe(0)
        ->and(CustomerDecision::query()->where('method', CustomerDecision::METHOD_ACCOUNT)->count())->toBe(0)
        ->and(PaymentProof::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('source', 'customer')->count())->toBe(0);
}

/**
 * Rows a refused write must leave untouched.
 *
 * @return array<string, mixed>
 */
function accI03Snapshot(): array
{
    return [
        ...finSnapshot(),
        'documents' => DB::table('agreement_documents')->get()->map(fn ($d) => [$d->id, $d->status, $d->lock_version])->all(),
        'decisions' => DB::table('customer_decisions')->count(),
        'agreements' => DB::table('agreements')->count(),
    ];
}
