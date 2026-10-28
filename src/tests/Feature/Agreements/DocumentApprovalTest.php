<?php

/**
 * Sending and approving documents (AGR-01, AGR-03..06, AGR-09, M-08) and the P5 actions they verify.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M08Document\ReviseDocument;
use App\Actions\StateMachines\M08Document\T00PrepareContract;
use App\Actions\StateMachines\M08Document\T00UploadDesign;
use App\Actions\StateMachines\M08Document\T02RecordExternalApproval;
use App\Actions\StateMachines\M08Document\T05ExpireDocuments;
use App\Actions\StateMachines\M08Document\T06ExtendDocument;
use App\Agreements\CustomerActor;
use App\Agreements\CustomerDecisions;
use App\Agreements\OrderAgreement;
use App\Enums\ActionStatus;
use App\Enums\DocumentStatus;
use App\Files\AttachmentService;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\CustomerDecision;
use App\Models\Attachment;
use App\Models\Order;
use App\Models\StateTransition;
use App\Support\StaleRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/AgreementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = agreementFixture();
    $this->quote = agrSend($this->f->sales, agrQuote($this->f->sales, $this->f->order));
});

it('sends fixed content: the hash, validity and sender are kept and the content can no longer change', function () {
    expect($this->quote->status)->toBe(DocumentStatus::AwaitingCustomer)
        ->and($this->quote->content_hash)->toHaveLength(64)
        ->and($this->quote->valid_until->toDateString())->toBe(now()->addDays(14)->toDateString())
        ->and($this->quote->sent_by)->toBe($this->f->sales->id);

    expect(fn () => $this->quote->forceFill(['content' => ['changed' => true]])->save())->toThrow(LogicException::class);
    // Sending verifies the flow's «prepare the quote» action (P5 result owner), and nothing else.
    expect(actionOf($this->f->order, 'quote')->refresh()->source_reference)->toBe(['type' => 'agreement_document', 'id' => $this->quote->id])
        ->and(actionOf($this->f->order, 'quote_approval')->refresh()->source_reference)->toBeNull();
});

it('records an external approval with channel and evidence and fixes the order agreement once (AGR-01, AGR-05)', function () {
    $quote = agrApprove($this->f->owner, $this->quote);
    $agreement = OrderAgreement::current($this->f->order);
    $decision = CustomerDecision::query()->sole();

    expect($quote->status)->toBe(DocumentStatus::Approved)
        ->and($decision->method)->toBe('external')->and($decision->channel)->toBe('message')
        ->and($decision->content_hash)->toBe($quote->content_hash)
        ->and($decision->recorded_by)->toBe($this->f->owner->id)
        ->and(Attachment::query()->where('attachable_id', $decision->id)->where('purpose', 'approval_evidence')->count())->toBe(1)
        ->and($agreement->version)->toBe(1)
        ->and($agreement->total_minor)->toBe(9_150_000)
        // The same order carries the value; no second order is created.
        ->and($this->f->order->refresh()->total_minor)->toBe(9_150_000)
        ->and(Order::query()->count())->toBe(1)
        ->and(actionOf($this->f->order, 'quote_approval')->refresh()->source_reference)->toBe(['type' => 'agreement_document', 'id' => $quote->id]);
});

it('keeps the approved agreement immutable', function () {
    agrApprove($this->f->owner, $this->quote);
    $agreement = Agreement::query()->sole();

    expect(fn () => $agreement->forceFill(['total_minor' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $agreement->delete())->toThrow(LogicException::class)
        ->and(fn () => CustomerDecision::query()->sole()->forceFill(['note' => 'x'])->save())->toThrow(LogicException::class);
});

it('replays an idempotent double submit and never makes two agreements (AGR-04)', function () {
    $key = agrKey();
    $version = $this->quote->lock_version;
    agrApprove($this->f->owner, $this->quote, $key);
    $snapshot = agrSnapshot();

    // The same request again (double click, retry): the known result, nothing new.
    app(T02RecordExternalApproval::class)->handle($this->f->owner, $this->quote, [
        'channel' => 'message', 'decided_on' => now()->toDateString(), 'note' => 'واتساب', 'content_hash' => $this->quote->content_hash,
    ], agrPdf(), $version, $key);
    expect(agrSnapshot())->toBe($snapshot);

    // A new key on the approved document is refused without effect.
    agrRefused(fn () => agrApprove($this->f->owner, $this->quote), InvalidTransition::class, 'not_from_approved');
    expect(Agreement::query()->count())->toBe(1)
        ->and(StateTransition::query()->where('machine', 'M-08')->where('transition', 'T02')->count())->toBe(1);
});

it('rejects a stale version without effect', function () {
    $this->quote->forceFill(['lock_version' => $this->quote->lock_version + 1])->save();
    agrRefused(fn () => app(T02RecordExternalApproval::class)->handle($this->f->owner, $this->quote, [
        'channel' => 'paper', 'decided_on' => now()->toDateString(), 'content_hash' => $this->quote->content_hash,
    ], agrPdf(), $this->quote->lock_version - 1, agrKey()), StaleRecord::class);
});

it('refuses approving content other than what was sent', function () {
    agrRefused(fn () => app(T02RecordExternalApproval::class)->handle($this->f->owner, $this->quote, [
        'channel' => 'paper', 'decided_on' => now()->toDateString(), 'content_hash' => str_repeat('a', 64),
    ], agrPdf(), $this->quote->lock_version, agrKey()), InvalidTransition::class, 'content_changed');
});

it('lets only P-34 record an external approval', function () {
    agrRefused(fn () => agrApprove($this->f->sales, $this->quote), AuthorizationException::class);
});

it('lets the customer approve from their account once, and never a replaced document (AGR-04, AGR-09)', function () {
    $customer = new CustomerActor($this->f->order->customer_id, $this->f->order->customer->mobile);
    $replacement = app(ReviseDocument::class)->handle($this->f->sales, $this->quote, agrKey());
    agrSend($this->f->sales, $replacement);

    expect($this->quote->refresh()->status)->toBe(DocumentStatus::Replaced);
    agrRefused(fn () => app(CustomerDecisions::class)->approveDocument($customer, $this->quote, (string) $this->quote->content_hash), InvalidTransition::class, 'not_from_replaced');

    $replacement->refresh();
    app(CustomerDecisions::class)->approveDocument($customer, $replacement, (string) $replacement->content_hash);
    expect(CustomerDecision::query()->sole()->method)->toBe('customer_account')
        ->and(CustomerDecision::query()->sole()->customer_mobile)->toBe($this->f->order->customer->mobile);
    agrRefused(fn () => app(CustomerDecisions::class)->approveDocument($customer, $replacement->refresh(), (string) $replacement->content_hash), InvalidTransition::class, 'not_from_approved');

    // Another customer is refused.
    $other = new CustomerActor($this->f->inB->id, $this->f->inB->mobile);
    expect(fn () => app(CustomerDecisions::class)->approveDocument($other, $replacement, (string) $replacement->content_hash))->toThrow(AuthorizationException::class);
});

it('returns a change request to the preparer without cancelling the order (M-08-T03)', function () {
    $customer = new CustomerActor($this->f->order->customer_id, $this->f->order->customer->mobile);
    app(CustomerDecisions::class)->requestDocumentChanges($customer, $this->quote, (string) $this->quote->content_hash, 'خفض ارتفاع الخزائن');

    expect($this->quote->refresh()->status)->toBe(DocumentStatus::ChangesRequested)
        ->and($this->f->order->refresh()->status)->toBe('in_progress')
        ->and(OrderAgreement::current($this->f->order))->toBeNull();
});

it('expires an unapproved quote after its last day, refuses its approval, and extends it with the same content (T05, T06)', function () {
    $this->travel(15)->days();
    expect(app(T05ExpireDocuments::class)->handle())->toBe(1)
        ->and(app(T05ExpireDocuments::class)->handle())->toBe(0)
        ->and($this->quote->refresh()->status)->toBe(DocumentStatus::Expired);
    agrRefused(fn () => agrApprove($this->f->owner, $this->quote), InvalidTransition::class, 'not_from_expired');

    $hash = $this->quote->content_hash;
    app(T06ExtendDocument::class)->handle($this->f->sales, $this->quote, 7, $this->quote->lock_version);
    expect($this->quote->refresh()->status)->toBe(DocumentStatus::AwaitingCustomer)->and($this->quote->content_hash)->toBe($hash);
    agrApprove($this->f->owner, $this->quote);

    // An approval never expires later.
    $this->travel(30)->days();
    app(T05ExpireDocuments::class)->handle();
    expect($this->quote->refresh()->status)->toBe(DocumentStatus::Approved);
});

it('uploads a design as a private file, sends it and approves it; a later file replaces it only for the new content (AGR-03, AGR-05)', function () {
    $order = $this->f->order;
    $design = app(T00UploadDesign::class)->handle($this->f->manager, $order, agrPdf('kitchen.pdf'), 'تصميم المطبخ', [], null, agrKey());
    expect($design->content['file']['name'])->toBe('kitchen.pdf')->and($design->status)->toBe(DocumentStatus::Draft);
    $design = agrSend($this->f->sales, $design);
    $design = agrApprove($this->f->owner, $design);

    $second = app(T00UploadDesign::class)->handle($this->f->manager, $order, agrPdf('kitchen-v2.pdf'), 'سطح كوارتز', [], $design, agrKey());
    $second = agrSend($this->f->sales, $second);
    // The approved design stays approved until the replacement is approved.
    expect($design->refresh()->status)->toBe(DocumentStatus::Approved);
    agrApprove($this->f->owner, $second);
    expect($design->refresh()->status)->toBe(DocumentStatus::Replaced)
        ->and(CustomerDecision::query()->where('subject_id', $design->id)->where('decision', 'approved')->exists())->toBeTrue();
});

it('prepares a contract from the agreement with copied party data, or from an external file (Q-36 c)', function () {
    agrApprove($this->f->owner, $this->quote);
    $contract = app(T00PrepareContract::class)->handle($this->f->sales, $this->f->order, null, 'شرط إضافي', agrKey());

    expect($contract->content['source'])->toBe('agreement')
        ->and($contract->content['agreement_version'])->toBe(1)
        ->and($contract->content['totals']['total_minor'])->toBe(9_150_000)
        ->and($contract->content['parties']['customer']['name'])->toBe($this->f->order->customer->name)
        ->and($contract->content['missing'])->toContain('facility.commercial_registration');

    // Later profile edits never change the prepared contract.
    $this->f->order->customer->forceFill(['name' => 'اسم آخر'])->save();
    expect($contract->refresh()->content['parties']['customer']['name'])->not->toBe('اسم آخر');

    agrSend($this->f->sales, $contract);
    agrApprove($this->f->owner, $contract);
    expect(actionOf($this->f->order, 'contract_approval')->refresh()->source_reference)->toBe(['type' => 'agreement_document', 'id' => $contract->id]);
});

it('gives the evidence a signed link that re-checks access to the order', function () {
    agrApprove($this->f->owner, $this->quote);
    $attachment = Attachment::query()->where('purpose', 'approval_evidence')->sole();
    $files = app(AttachmentService::class);

    expect($files->canDownload($this->f->sales, $attachment))->toBeTrue()
        ->and($files->canDownload($this->f->org->member('sales', [$this->f->org->c]), $attachment))->toBeFalse()
        ->and(URL::hasValidSignature(request()->create($files->downloadUrl($attachment, $this->f->sales))))->toBeTrue();
});

it('lets the P5 action open with the result recorded early (quote approval before its stage)', function () {
    agrApprove($this->f->owner, $this->quote);
    $action = actionOf($this->f->order, 'quote_approval')->refresh();
    expect($action->status)->toBe(ActionStatus::Unavailable)
        ->and($action->source_reference)->not->toBeNull();
});
