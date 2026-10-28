<?php

/**
 * Product-owner decision (2026-10-28, ADR-0014): «proof file required for cash collections» is a facility setting the
 * owner alone controls. Default required; off, cash may be recorded without a file but with its collector and a note;
 * every other method still needs a proof.
 */

use App\Actions\Settings\UpdateCashProofPolicy;
use App\Actions\StateMachines\M11Payment\T01RecordPayment;
use App\Audit\AuditAction;
use App\Models\AuditEvent;
use App\Models\Finance\Payment;
use App\Models\FinanceSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/FinanceTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
    finAgree($this->f, '20000');
});

/**
 * @param  array<string, mixed>  $extra
 */
function cashWithoutFile(object $f, array $extra = []): Payment
{
    return app(T01RecordPayment::class)->handle($f->finance, $f->order->refresh(), [
        'amount' => '500', 'method' => 'cash', 'paid_on' => now()->toDateString(), 'acknowledge_similar' => true, ...$extra,
    ], null, null, agrKey());
}

it('requires a proof for cash by default (the earlier M-11-T01 behaviour)', function () {
    expect(FinanceSettings::current()->cashProofRequired())->toBeTrue();

    finRefused(fn () => cashWithoutFile($this->f, ['note' => 'استلمه المندوب']), ValidationException::class);
    expect(finPay($this->f->finance, $this->f->order, '500', ['method' => 'cash', 'reference' => null])->attachments()->count())->toBe(1);
});

it('off: a cash collection is recorded without a file, by its collector, with a mandatory note', function () {
    app(UpdateCashProofPolicy::class)->handle($this->f->owner, ['cash_proof_required' => false], null);

    finRefused(fn () => cashWithoutFile($this->f), ValidationException::class);

    $payment = cashWithoutFile($this->f, ['note' => 'استلمه المندوب نقدًا في الموقع']);
    expect($payment->status->value)->toBe('confirmed')
        ->and($payment->recorded_by)->toBe($this->f->finance->id)
        ->and($payment->note)->toBe('استلمه المندوب نقدًا في الموقع')
        ->and($payment->attachments()->count())->toBe(0)
        ->and($payment->receipt)->not->toBeNull();
});

it('off: a transfer, card or cheque still requires its proof', function (string $method) {
    app(UpdateCashProofPolicy::class)->handle($this->f->owner, ['cash_proof_required' => false], null);

    finRefused(fn () => app(T01RecordPayment::class)->handle($this->f->finance, $this->f->order->refresh(), [
        'amount' => '500', 'method' => $method, 'paid_on' => now()->toDateString(), 'reference' => 'FT123456', 'note' => 'x', 'acknowledge_similar' => true,
    ], null, null, agrKey()), ValidationException::class);
})->with(['transfer', 'card', 'cheque']);

it('only the facility owner changes it, and the change is audited', function () {
    foreach ([$this->f->manager, $this->f->finance] as $other) {
        expect(fn () => app(UpdateCashProofPolicy::class)->handle($other, ['cash_proof_required' => false], null))
            ->toThrow(AuthorizationException::class);
    }
    $this->actingAs($this->f->manager)->put('/settings/sales/cash-proof', ['cash_proof_required' => false])->assertForbidden();
    expect(FinanceSettings::current()->cashProofRequired())->toBeTrue();

    $this->actingAs($this->f->owner)->put('/settings/sales/cash-proof', ['cash_proof_required' => false])->assertRedirect('/settings/sales');
    expect(FinanceSettings::current()->cashProofRequired())->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::CashProofPolicyUpdated->value)->sole()->only(['before', 'after']))->toBe(['before' => ['cash_evidence_required' => true], 'after' => ['cash_evidence_required' => false]]);

    $this->actingAs($this->f->owner)->get('/settings/sales')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('cashProofRequired', false)->where('canEditCashProof', true));
    $this->actingAs($this->f->manager)->get('/settings/sales')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canEditCashProof', false));
});

it('records cash without a file over HTTP when allowed, and the finance payload says so', function () {
    app(UpdateCashProofPolicy::class)->handle($this->f->owner, ['cash_proof_required' => false], null);

    $this->actingAs($this->f->finance)->getJson("/orders/{$this->f->order->id}/finance")->assertJsonPath('finance.cash_proof_required', false);
    $this->actingAs($this->f->finance)->post("/orders/{$this->f->order->id}/finance/payments", [
        'idempotency_key' => agrKey(), 'lock_version' => finVersion($this->f->order), 'amount' => '250', 'method' => 'cash',
        'paid_on' => now()->toDateString(), 'note' => 'استلمه المندوب', 'acknowledge_similar' => '1',
    ], ['Accept' => 'application/json'])->assertCreated();
    $this->actingAs($this->f->finance)->post("/orders/{$this->f->order->id}/finance/payments", [
        'idempotency_key' => agrKey(), 'lock_version' => finVersion($this->f->order), 'amount' => '250', 'method' => 'transfer',
        'reference' => 'FT99887766', 'paid_on' => now()->toDateString(), 'note' => 'x', 'acknowledge_similar' => '1',
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('evidence');
});
