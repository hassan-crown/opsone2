<?php

/**
 * The customer workspace screens and actions (WEB-03, WEB-09..13): «مطلوب منك», orders and their public timeline,
 * document approval recorded once on the exact content (P-67), transfer proofs under review (P-68), appointment
 * confirmation and change requests (P-70), and the cancellation request the facility decides (P-99).
 */

use App\Actions\StateMachines\M16Appointment\T02ConfirmAppointment;
use App\Enums\AppointmentStatus;
use App\Enums\CancellationStatus;
use App\Enums\CustomOrderStatus;
use App\Enums\DocumentStatus;
use App\Enums\ProofStatus;
use App\Models\Agreements\CustomerDecision;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Finance\PaymentProof;
use App\Models\StateTransition;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/PortalTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = portalFixture();
    Queue::fake();
    $this->rec = $this->f->recA;
    actingAsCustomer($this->f->a);
});

it('starts with what is required from the customer, each with its place to act', function () {
    $this->get('/portal')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('portal/home')
        ->where('required', fn ($items) => collect($items)->pluck('type')->sort()->values()->all() === ['appointment', 'document', 'payment'])
        ->has('orders', 1)
        ->where('orders.0.number', $this->f->orderA->number));
});

it('shows the order with its public timeline, money and records', function () {
    $this->get("/portal/orders/{$this->f->orderA->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('portal/order')
        ->where('order.number', $this->f->orderA->number)
        ->where('order.finance.agreed_minor', 1_000_000)
        ->where('order.finance.collected_minor', 200_000)
        ->where('order.finance.pending_proofs_minor', 100_000)
        ->has('order.proofs', 1)
        ->has('order.receipts', 1)
        ->has('order.appointments', 1)
        ->where('order.can.upload_proof', true)
        ->where('order.can.request_cancellation', true)
        ->where('order.timeline', fn ($stages) => collect($stages)->every(fn ($s) => array_keys($s) === ['name', 'status', 'started_on', 'completed_on'])));
});

it('records a document approval once on the exact content; a repeat is answered without a second decision', function () {
    $design = $this->rec->design;
    $this->get("/portal/documents/{$design->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('portal/document')
        ->where('document.can.decide', true)->where('document.content_hash', $design->content_hash));

    $this->post("/portal/documents/{$design->id}/approve", ['content_hash' => $design->content_hash])->assertRedirect("/portal/documents/{$design->id}");
    $this->post("/portal/documents/{$design->id}/approve", ['content_hash' => $design->content_hash])->assertRedirect("/portal/documents/{$design->id}")
        ->assertSessionHasNoErrors();

    $decisions = CustomerDecision::query()->where('subject_type', 'document')->where('subject_id', $design->id)->get();
    expect($design->refresh()->status)->toBe(DocumentStatus::Approved)
        ->and($decisions)->toHaveCount(1)
        ->and($decisions[0]->method)->toBe(CustomerDecision::METHOD_ACCOUNT)
        ->and($decisions[0]->customer_mobile)->toBe($this->f->a->mobile)
        ->and(StateTransition::query()->where('subject_id', $design->id)->where('transition', 'T02')->count())->toBe(1);
});

it('refuses an approval of content other than what was shown', function () {
    $design = $this->rec->design;
    $this->from("/portal/documents/{$design->id}")->post("/portal/documents/{$design->id}/approve", ['content_hash' => str_repeat('a', 64)])
        ->assertSessionHasErrors('transition');
    expect($design->refresh()->status)->toBe(DocumentStatus::AwaitingCustomer);
});

it('sends a change request on a document with a note', function () {
    $design = $this->rec->design;
    $this->post("/portal/documents/{$design->id}/changes", ['content_hash' => $design->content_hash, 'note' => 'نقل الثلاجة'])->assertRedirect();
    expect($design->refresh()->status)->toBe(DocumentStatus::ChangesRequested);
});

it('uploads a transfer proof under review; it is never counted as paid', function () {
    $order = $this->f->orderA;
    $this->get("/portal/orders/{$order->id}/pay")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('portal/pay')->where('accepts_proofs', true));
    $this->post("/portal/orders/{$order->id}/proofs", portalProofForm('750.00'))->assertRedirect("/portal/orders/{$order->id}");

    $proof = PaymentProof::query()->latest('id')->firstOrFail();
    expect($proof->status)->toBe(ProofStatus::UnderReview)
        ->and($proof->claimed_minor)->toBe(75_000)
        ->and($proof->submitted_mobile)->toBe($this->f->a->mobile);
    $this->get("/portal/orders/{$order->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('order.finance.collected_minor', 200_000)
        ->where('order.finance.pending_proofs_minor', 175_000));

    $this->post("/portal/proofs/{$proof->id}/withdraw")->assertRedirect();
    expect($proof->refresh()->status)->toBe(ProofStatus::Cancelled);
});

it('uploads a replacement after a correction request', function () {
    $proof = $this->rec->proof;
    $proof->forceFill(['status' => ProofStatus::NeedsCorrection, 'correction_reason' => 'الصورة غير واضحة'])->save();
    $this->get('/portal')->assertInertia(fn (AssertableInertia $p) => $p->where('required', fn ($items) => collect($items)->contains('type', 'proof')));

    $this->post("/portal/proofs/{$proof->id}/resubmit", portalProofForm('1000.00'))->assertRedirect();
    expect(PaymentProof::query()->where('replaces_id', $proof->id)->sole()->status)->toBe(ProofStatus::UnderReview);
});

it('confirms an appointment sent for confirmation and lets the customer ask to change it', function () {
    $appointment = $this->rec->appointment;
    $this->post("/portal/appointments/{$appointment->id}/confirm")->assertRedirect();
    $appointment->refresh();
    expect($appointment->status)->toBe(AppointmentStatus::Confirmed)
        ->and($appointment->confirmation_method)->toBe('customer_account')
        ->and($appointment->confirmed_by)->toBeNull();
    // A second confirmation is refused without effect.
    $this->post("/portal/appointments/{$appointment->id}/confirm")->assertSessionHasErrors('transition');

    $this->post("/portal/appointments/{$appointment->id}/change", ['note' => 'لست في المدينة', 'preferred' => 'الأحد مساءً'])->assertRedirect();
    $appointment->refresh();
    expect($appointment->status)->toBe(AppointmentStatus::Confirmed) // the original stays booked
        ->and($appointment->customer_request['note'])->toBe('لست في المدينة')
        ->and(UserNotification::query()->where('type', 'portal.appointment_change_requested')->count())->toBeGreaterThan(0);
    $this->post("/portal/appointments/{$appointment->id}/change", ['note' => 'مرة أخرى'])->assertSessionHasErrors('transition');
});

it('refuses to confirm over another booking of the same member', function () {
    $appointment = $this->rec->appointment;
    $other = propose($this->f->sales, $this->f->orderA, [$this->f->tech->id], '2026-11-20 09:00', '2026-11-20 10:00');
    app(T02ConfirmAppointment::class)->handle($this->f->sales, $other, 'phone');
    $other->forceFill(['starts_at' => $appointment->starts_at, 'ends_at' => $appointment->ends_at])->save();

    $this->post("/portal/appointments/{$appointment->id}/confirm")->assertSessionHasErrors('appointment');
    expect($appointment->refresh()->status)->toBe(AppointmentStatus::AwaitingConfirmation);
});

it('sends a cancellation request the facility decides; the customer can withdraw it before the decision', function () {
    $order = $this->f->orderA;
    $this->get("/portal/orders/{$order->id}/cancel")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('portal/cancel')->where('can_request', true));
    $this->post("/portal/orders/{$order->id}/cancellation", ['reason' => 'تغيرت الخطة'])->assertRedirect("/portal/orders/{$order->id}");

    $cancellation = OrderCancellation::query()->where('order_id', $order->id)->sole();
    expect($cancellation->status)->toBe(CancellationStatus::Pending)
        ->and($cancellation->source)->toBe(OrderCancellation::SOURCE_ACCOUNT)
        ->and($cancellation->requested_by)->toBeNull()
        ->and($order->refresh()->status)->toBe(CustomOrderStatus::CancellationPending->value);

    // One active request per order.
    $this->post("/portal/orders/{$order->id}/cancellation", ['reason' => 'مرة ثانية'])->assertSessionHasErrors('transition');
    expect(OrderCancellation::query()->where('order_id', $order->id)->count())->toBe(1);

    $this->post("/portal/cancellations/{$cancellation->id}/withdraw")->assertRedirect();
    expect($cancellation->refresh()->status)->toBe(CancellationStatus::Withdrawn)
        ->and($order->refresh()->status)->toBe(CustomOrderStatus::InProgress->value);
});

it('changes the workspace language and lists updates', function () {
    $this->put('/portal/locale', ['locale' => 'en'])->assertRedirect();
    expect($this->f->a->refresh()->portal_locale)->toBe('en');
    $this->get('/portal/settings')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('portal/settings')->where('locale', 'en')
        ->where('account.name', $this->f->a->name));
    $this->get('/portal/notifications')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('portal/notifications')
        ->where('updates', fn ($u) => count($u) > 0));
});
