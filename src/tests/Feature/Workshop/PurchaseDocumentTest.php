<?php

/**
 * ADR-0038 (product-owner requirement): the purchase order as a branded Arabic-first PDF laid out by the facility,
 * emailed to the supplier through the outbox with the PDF attached (queued, retried, status on the send log), and
 * the per-facility layout: chosen blocks and order, what the workshop may see of the customer, a preview, versions
 * recorded on each generated PDF, permissions and audit.
 */

use App\Mail\PurchaseOrderMail;
use App\Models\AuditEvent;
use App\Models\OutboxMessage;
use App\Models\Procurement\PurchaseDocumentVersion;
use App\Models\Procurement\PurchaseOrderDocument;
use App\Models\Procurement\PurchaseOrderSend;
use App\Models\Procurement\Supplier;
use App\Notifications\OutboxDeliverer;
use App\Procurement\PurchaseDocumentLayout;
use App\Procurement\PurchaseOrderDocuments;
use App\Procurement\PurchaseOrderPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/WorkshopTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = workshopFixture();
});

/**
 * @param  array<string, bool>  $flags
 * @return array<string, mixed>
 */
function layoutWith(array $flags = [], ?array $blocks = null): array
{
    $layout = PurchaseDocumentLayout::defaultLayout();
    $layout['flags'] = [...$layout['flags'], ...$flags];
    if ($blocks !== null) {
        $layout['blocks'] = $blocks;
    }

    return $layout;
}

it('renders the purchase order PDF and stores one copy per content and template version', function () {
    $po = workshopPo($this->f);

    $response = $this->actingAs($this->f->inventory)->get(route('purchases.pdf', $po))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');
    $this->get(route('purchases.pdf', $po))->assertOk();

    $document = PurchaseOrderDocument::query()->sole();
    expect($document->template_version)->toBe(0)
        ->and($document->attachment->purpose)->toBe('purchase_order_pdf');

    // A new layout version gives a new document that records it.
    $this->actingAs($this->f->owner)->put(route('settings.purchase-document.update'), [
        'version' => 0, 'layout' => layoutWith(['signature' => false]), 'email' => PurchaseDocumentLayout::defaultEmail(),
    ])->assertRedirect();
    $this->actingAs($this->f->inventory)->get(route('purchases.pdf', $po))->assertOk();
    expect(PurchaseOrderDocument::query()->orderByDesc('id')->value('template_version'))->toBe(1);

    // Someone without purchase permissions on the order never gets it.
    $this->actingAs($this->f->sales)->get(route('purchases.pdf', $po))->assertForbidden();
    $this->actingAs($this->f->inventoryB)->get(route('purchases.pdf', $po))->assertForbidden();
});

it('hides the customer from the workshop by default and prints only the chosen blocks in order', function () {
    $po = workshopPo($this->f);
    $pdf = app(PurchaseOrderPdf::class);

    [$default] = $pdf->data($po, PurchaseDocumentLayout::defaultLayout(), 0, 'ar', false);
    expect($default['orders'][0])->toBe(['number' => $this->f->order->number, 'customer' => null, 'phone' => null])
        ->and($default['blocks'])->toBe(PurchaseDocumentLayout::BLOCKS)
        ->and($default['lines'][0]['unit_cost'])->toBeNull();

    $blocks = [['key' => 'lines', 'visible' => true], ['key' => 'supplier', 'visible' => true], ['key' => 'terms', 'visible' => false]];
    [$custom] = $pdf->data($po, PurchaseDocumentLayout::normalize(layoutWith(['customer_name' => true, 'customer_phone' => true], $blocks)), 3, 'ar', false);
    expect($custom['orders'][0])->toMatchArray(['customer' => 'عميل سري', 'phone' => '0551234567'])
        ->and($custom['blocks'])->toBe(['lines', 'supplier'])
        ->and($custom['template_version'])->toBe(3);
});

it('shows prices only when the layout asks AND the viewer may see purchase costs', function () {
    $po = workshopPo($this->f);
    $this->actingAs($this->f->owner)->put(route('settings.purchase-document.update'), [
        'version' => 0, 'layout' => layoutWith(['prices' => true]), 'email' => PurchaseDocumentLayout::defaultEmail(),
    ])->assertRedirect();

    $docs = app(PurchaseOrderDocuments::class);
    $withCosts = $docs->document($this->f->inventory, $po, 'ar');
    procDeny($this->f->manager, 'P-82');
    $withoutCosts = $docs->document($this->f->manager, $po, 'ar');

    // Different content, so a different stored PDF: the one made without P-82 carries no price.
    expect($withCosts->id)->not->toBe($withoutCosts->id);
    [$data] = app(PurchaseOrderPdf::class)->data($po, PurchaseDocumentLayout::current()['layout'], 1, 'ar', false);
    expect($data['lines'][0]['unit_cost'])->toBeNull()->and($data['total'])->toBeNull();
});

it('emails the PDF to the supplier through the outbox once per key and logs the send', function () {
    Mail::fake();
    $po = workshopPo($this->f);
    $key = procKey();

    $this->actingAs($this->f->inventory)->post(route('purchases.email', $po), ['idempotency_key' => $key])->assertRedirect(route('purchases.show', $po));
    $this->post(route('purchases.email', $po), ['idempotency_key' => $key])->assertRedirect();

    $send = PurchaseOrderSend::query()->sole();
    expect($send->recipient)->toBe('orders@workshop.test')
        ->and($send->subject)->toContain($po->number)
        ->and($send->status)->toBe(PurchaseOrderSend::SENT)
        ->and($send->document->template_version)->toBe(0)
        ->and(OutboxMessage::query()->where('kind', 'purchase_order')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'purchase.emailed')->count())->toBe(1);

    Mail::assertSent(PurchaseOrderMail::class, fn (PurchaseOrderMail $m) => $m->hasTo('orders@workshop.test')
        && count($m->attachments()) === 1 && $m->attachments()[0]->as === $po->number.'.pdf'
        && $m->mailSubject === $send->subject);

    // Edited subject, body and recipient are used as typed.
    $this->post(route('purchases.email', $po), ['idempotency_key' => procKey(), 'recipient' => 'other@workshop.test', 'subject' => 'عاجل', 'body' => 'نرجو الاستعجال'])->assertRedirect();
    expect(PurchaseOrderSend::query()->latest('id')->first())->toMatchArray(['recipient' => 'other@workshop.test', 'subject' => 'عاجل', 'body' => 'نرجو الاستعجال']);

    $this->get(route('purchases.show', $po))->assertInertia(fn (Assert $p) => $p->has('purchase.sends', 2)
        ->where('purchase.sends.1.status', 'sent')->where('purchase.can.email', true));
});

it('refuses to email a draft, without a supplier email, or without P-46 on the order', function () {
    Mail::fake();
    $draft = workshopPo($this->f, confirm: false);
    $this->actingAs($this->f->inventory)->postJson(route('purchases.email', $draft), ['idempotency_key' => procKey()])->assertStatus(409);

    $po = workshopPo($this->f, supplier: procSupplier(['is_workshop' => true]));
    $this->post(route('purchases.email', $po), ['idempotency_key' => procKey()])->assertSessionHasErrors('recipient');

    $po = workshopPo($this->f);
    $this->actingAs($this->f->sales)->post(route('purchases.email', $po), ['idempotency_key' => procKey()])->assertForbidden();
    $this->actingAs($this->f->inventoryB)->post(route('purchases.email', $po), ['idempotency_key' => procKey()])->assertForbidden();

    expect(PurchaseOrderSend::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('retries a failed send with backoff, marks it failed when retries run out, and lets a buyer resend it', function () {
    config(['mail.default' => 'missing-mailer']);
    $po = workshopPo($this->f);
    $this->actingAs($this->f->inventory)->post(route('purchases.email', $po), ['idempotency_key' => procKey()])->assertRedirect();
    $send = PurchaseOrderSend::query()->sole();
    expect($send->status)->toBe(PurchaseOrderSend::QUEUED)->and($send->outbox->attempts)->toBe(1);

    // Only a send whose retries ran out is repeated by hand.
    $this->post(route('purchases.email.retry', [$po, $send]), ['idempotency_key' => procKey()])->assertStatus(409);

    for ($i = 0; $i < OutboxDeliverer::MAX_ATTEMPTS; $i++) {
        $this->travel(3)->hours();
        app(OutboxDeliverer::class)->deliver((int) $send->outbox_message_id);
    }
    expect($send->refresh()->status)->toBe(PurchaseOrderSend::FAILED);

    Mail::fake();
    $this->post(route('purchases.email.retry', [$po, $send]), ['idempotency_key' => procKey()])->assertRedirect();
    $retry = PurchaseOrderSend::query()->latest('id')->first();
    expect($retry->retry_of)->toBe($send->id)->and($retry->status)->toBe(PurchaseOrderSend::SENT);
    Mail::assertSent(PurchaseOrderMail::class, 1);
});

it('lets the owner arrange the document as a new audited version, with closed placeholders and a preview', function () {
    $this->actingAs($this->f->owner)->get(route('settings.purchase-document'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('purchases/document-settings')->where('version', 0)->has('options.blocks', count(PurchaseDocumentLayout::BLOCKS)));

    $email = ['subject' => ['ar' => 'طلب {number}', 'en' => ''], 'body' => ['ar' => 'إلى {supplier}', 'en' => '']];
    $blocks = [['key' => 'supplier', 'visible' => true], ['key' => 'lines', 'visible' => true]];
    $this->put(route('settings.purchase-document.update'), ['version' => 0, 'layout' => layoutWith(['customer_name' => true], $blocks), 'email' => $email])->assertRedirect();

    $saved = PurchaseDocumentVersion::query()->sole();
    expect($saved->version)->toBe(1)
        ->and(array_column($saved->layout['blocks'], 'key'))->toBe(['supplier', 'lines', 'facility', 'branch', 'order_reference', 'expected_on', 'delivery_address', 'notes', 'terms', 'signatures'])
        ->and($saved->layout['blocks'][2]['visible'])->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'purchase.document_saved')->count())->toBe(1);

    // An unknown placeholder, a stale version and an unchanged save are refused or ignored.
    $this->put(route('settings.purchase-document.update'), ['version' => 1, 'layout' => layoutWith(), 'email' => ['subject' => ['ar' => '{password}'], 'body' => ['ar' => 'x']]])
        ->assertSessionHasErrors('email.subject.ar');
    $this->put(route('settings.purchase-document.update'), ['version' => 0, 'layout' => layoutWith(), 'email' => $email])->assertSessionHasErrors('lock_version');
    $this->put(route('settings.purchase-document.update'), ['version' => 1, 'layout' => $saved->layout, 'email' => $saved->email])->assertRedirect();
    expect(PurchaseDocumentVersion::query()->count())->toBe(1);

    // The email texts follow the saved version.
    $po = workshopPo($this->f);
    expect(PurchaseOrderDocuments::texts($po, 'ar'))->toBe(['subject' => 'طلب '.$po->number, 'body' => 'إلى ورشة الدمام']);

    $preview = $this->post(route('settings.purchase-document.preview'), ['layout' => layoutWith(['stamp' => false])])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $preview->getContent(), 0, 4))->toBe('%PDF')->and(PurchaseDocumentVersion::query()->count())->toBe(1);

    // P-45 only: a buyer without it can neither open, save nor preview.
    $this->actingAs($this->f->sales)->get(route('settings.purchase-document'))->assertForbidden();
    $this->put(route('settings.purchase-document.update'), ['version' => 1, 'layout' => layoutWith(), 'email' => $email])->assertForbidden();
    $this->post(route('settings.purchase-document.preview'), ['layout' => layoutWith()])->assertForbidden();
});

it('marks workshops on the supplier profile with their lead time', function () {
    $this->actingAs($this->f->inventory)->post(route('suppliers.store'), [
        'name' => ['ar' => 'ورشة ألمنيوم'], 'is_workshop' => true, 'lead_days' => 10, 'idempotency_key' => procKey(),
    ])->assertRedirect();
    $supplier = Supplier::query()->latest('id')->first();
    expect($supplier->is_workshop)->toBeTrue()->and($supplier->lead_days)->toBe(10);

    // A purchase order to it proposes the expected date from the lead time.
    $po = procDraft($this->f, $this->f->inventory, [procLine('material', $this->f->plain->id, 1000)], ['supplier_id' => $supplier->id, 'expected_on' => null]);
    expect($po->expected_on->toDateString())->toBe(now()->addDays(10)->toDateString());
});
