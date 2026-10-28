<?php

/**
 * Customer isolation (WEB-02, P-66..P-70, P-99): customer A signed in can neither read nor act on customer B's order,
 * document, proof, receipt file, appointment or cancellation through any customer route. Every foreign id answers 404
 * (never 403: existence is not revealed) and leaves B's records untouched. Payloads carry no staff-only data.
 */

use App\Agreements\CustomerActor;
use App\Models\Attachment;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Finance\PaymentProof;
use App\Portal\CustomerCancellations;
use App\Portal\PortalFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/PortalTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = portalFixture();
    Queue::fake();
    $this->cancelB = app(CustomerCancellations::class)->request(new CustomerActor($this->f->b->id, $this->f->b->mobile), $this->f->orderB, 'تغيرت الخطة');
    $this->f->recB->proof->forceFill(['status' => 'needs_correction', 'correction_reason' => 'غير واضح'])->save();
    actingAsCustomer($this->f->a);
});

/**
 * @return array<string, mixed>
 */
function portalStateOf(object $rec, OrderCancellation $cancellation): array
{
    return [
        'order' => DB::table('orders')->where('id', $rec->order->id)->first(['status', 'lock_version']),
        'design' => DB::table('agreement_documents')->where('id', $rec->design->id)->first(['status', 'lock_version']),
        'proofs' => DB::table('payment_proofs')->where('order_id', $rec->order->id)->get(['id', 'status', 'lock_version'])->all(),
        'appointment' => DB::table('appointments')->where('id', $rec->appointment->id)->first(['status', 'customer_request', 'lock_version']),
        'cancellation' => DB::table('order_cancellations')->where('id', $cancellation->id)->first(['status', 'lock_version']),
        'decisions' => DB::table('customer_decisions')->where('order_id', $rec->order->id)->count(),
    ];
}

it('answers 404 to every customer route aimed at another customer\'s records, without effect', function () {
    $b = $this->f->recB;
    $before = portalStateOf($b, $this->cancelB);
    $routes = [
        ['get', "/portal/orders/{$b->order->id}", []],
        ['get', "/portal/orders/{$b->order->id}/pay", []],
        ['get', "/portal/orders/{$b->order->id}/cancel", []],
        ['post', "/portal/orders/{$b->order->id}/proofs", portalProofForm()],
        ['post', "/portal/orders/{$b->order->id}/cancellation", ['reason' => 'محاولة']],
        ['get', "/portal/documents/{$b->design->id}", []],
        ['get', "/portal/documents/{$b->quote->id}", []],
        ['post', "/portal/documents/{$b->design->id}/approve", ['content_hash' => $b->design->content_hash]],
        ['post', "/portal/documents/{$b->design->id}/changes", ['content_hash' => $b->design->content_hash, 'note' => 'تعديل']],
        ['post', "/portal/proofs/{$b->proof->id}/resubmit", portalProofForm()],
        ['post', "/portal/proofs/{$b->proof->id}/withdraw", []],
        ['post', "/portal/appointments/{$b->appointment->id}/confirm", []],
        ['post', "/portal/appointments/{$b->appointment->id}/change", ['note' => 'غير مناسب']],
        ['post', "/portal/cancellations/{$this->cancelB->id}/withdraw", []],
    ];
    foreach ($routes as [$method, $uri, $data]) {
        $this->{$method}($uri, $data)->assertNotFound();
    }
    // Non-numeric and unknown ids are simply not found too.
    $this->get('/portal/orders/abc')->assertNotFound();
    $this->get('/portal/orders/999999')->assertNotFound();

    expect(portalStateOf($b, $this->cancelB))->toEqual($before);
});

it('never lists another customer\'s records', function () {
    $b = $this->f->recB;
    $pages = [
        '/portal' => 'portal/home', '/portal/orders' => 'portal/orders', '/portal/documents' => 'portal/documents',
        '/portal/notifications' => 'portal/notifications', "/portal/orders/{$this->f->orderA->id}" => 'portal/order',
    ];
    foreach ($pages as $uri => $component) {
        $response = $this->get($uri)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component($component));
        $json = json_encode($response->viewData('page')['props'], JSON_UNESCAPED_UNICODE);
        expect($json)->not->toContain($b->order->number)
            ->not->toContain($b->design->number)
            ->not->toContain($b->receipt->number)
            ->and($json)->not->toContain($this->f->b->name);
    }
});

it('serves files only to the customer the signed link is bound to and only for their own records', function () {
    $own = Attachment::query()->where('attachable_type', (new PaymentProof)->getMorphClass())->where('attachable_id', $this->f->recA->proof->id)->sole();
    $foreign = Attachment::query()->where('attachable_type', (new PaymentProof)->getMorphClass())->where('attachable_id', $this->f->recB->proof->id)->sole();

    $this->get(PortalFiles::url($own, $this->f->a))->assertOk();
    // B's file with a link signed for A: not found. A link issued for B: refused for A.
    $this->get(PortalFiles::url($foreign, $this->f->a))->assertNotFound();
    $this->get(PortalFiles::url($foreign, $this->f->b))->assertForbidden();
    // Tampered or unsigned links are refused.
    $this->get('/portal/files/'.$own->uuid.'?c='.$this->f->a->id)->assertForbidden();
    // A staff signed link is not a customer link (and the staff route needs a staff session).
    expect($this->get('/files/'.$own->uuid)->status())->toBeIn([302, 403]);
});

it('shows no staff-only data: no internal notes, staff names, costs or commission flags', function () {
    $this->f->a->forceFill(['internal_notes' => 'ملاحظة داخلية سرية'])->save();
    $this->f->orderA->forceFill(['assignee_id' => $this->f->sales->id])->save();
    $responses = [
        $this->get("/portal/orders/{$this->f->orderA->id}"),
        $this->get("/portal/documents/{$this->f->recA->quote->id}"),
        $this->get("/portal/documents/{$this->f->recA->design->id}"),
        $this->get('/portal'),
    ];
    foreach ($responses as $response) {
        $json = json_encode($response->assertOk()->viewData('page')['props'], JSON_UNESCAPED_UNICODE);
        expect($json)->not->toContain('ملاحظة داخلية سرية')
            ->not->toContain($this->f->sales->name)
            ->not->toContain($this->f->tech->name)
            ->not->toContain('commissionable')
            ->not->toContain('products_minor')
            ->not->toContain('orgNav')
            ->not->toContain('"user":{');
    }
});
