<?php

/**
 * Regressions of the P6 security review (finance, settlement, commissions, agreements).
 */

use App\Access\Enums\Scope;
use App\Access\Exceptions\InvalidTransition;
use App\Actions\Commissions\UpdateCommissionSettings;
use App\Actions\Finance\TransferCredit;
use App\Actions\StateMachines\M22Membership\T00CreateMembership;
use App\Actions\StateMachines\M33Payout\T04CorrectPayout;
use App\Agreements\AgreementPresenter;
use App\Agreements\DiscountLimits;
use App\Audit\AuditAction;
use App\Audit\AuditRecorder;
use App\Enums\MemberGrantEffect;
use App\Enums\PayoutStatus;
use App\Finance\CollectionNotices;
use App\Finance\MoneyLedger;
use App\Models\AuditEvent;
use App\Models\Commissions\CommissionRate;
use App\Models\Order;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

require_once __DIR__.'/../Settlement/SettlementTestHelpers.php';
require_once __DIR__.'/../Commissions/CommissionTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = commissionFixture(500);
});

/** A member with the role template and extra tenant-wide permissions (member overrides). */
function p6Member(object $f, string $role, array $allow): User
{
    $user = $f->org->member($role, [$f->org->a], $f->org->d1);
    foreach ($allow as $permission) {
        Org::membershipOf($user)->grants()->create(['permission' => $permission, 'effect' => MemberGrantEffect::Allow, 'scope' => Scope::Tenant]);
    }

    return $user->refresh();
}

it('a credit transfer never takes the money a pending refund reserved on another payment', function () {
    finAgree($this->f, '1000');
    $older = finPay($this->f->finance, $this->f->order, '1500');
    $newer = finPay($this->f->finance, $this->f->order, '500');
    $refund = setRefund($this->f->finance, $this->f->order, $newer, '500');
    $other = openCustomOrder($this->f->sales, $this->f->order->customer);

    app(TransferCredit::class)->handle($this->f->finance, $this->f->order, ['to_order_id' => $other->id, 'amount' => '500'], finVersion($this->f->order), agrKey());

    // The older payment's free credit moved; the reserved payment's money stayed for its refund.
    expect(MoneyLedger::placesOf($newer->id, $this->f->order->id))->toBe([0 => 50_000])
        ->and(MoneyLedger::sharesOf($older->id)[$other->id] ?? 0)->toBe(50_000);
    finRefused(fn () => app(TransferCredit::class)->handle($this->f->finance, $this->f->order, ['to_order_id' => $other->id, 'amount' => '1'], null, agrKey()), InvalidTransition::class, 'not_enough_credit');
    expect(setPaid($this->f->finance, $refund)->number)->not->toBeNull();
    setLedgerBalanced();
});

it('money notices reach an assignee only when they may see the order\'s money (P-37)', function () {
    finAgree($this->f, '1000');
    $supervisor = $this->f->supervisor; // P-16 without P-37
    $this->f->order->forceFill(['assignee_id' => $supervisor->id])->save();
    finPay($this->f->finance, $this->f->order, '100');
    expect(UserNotification::query()->where('user_id', $supervisor->id)->where('type', CollectionNotices::RECEIPT_RECORDED)->count())->toBe(0);

    $this->f->order->forceFill(['assignee_id' => $this->f->sales->id])->save();
    finPay($this->f->finance, $this->f->order, '100');
    expect(UserNotification::query()->where('user_id', $this->f->sales->id)->where('type', CollectionNotices::RECEIPT_RECORDED)->count())->toBe(1);
});

it('the audit log hides amount maps and commission figures from a viewer without P-37 / P-94', function () {
    finAgree($this->f, '1000');
    finPay($this->f->finance, $this->f->order, '1000');
    comPay($this->f->finance, $this->f->sales);
    $auditor = p6Member($this->f, 'supervisor', ['P-63']);

    $recorded = AuditEvent::query()->where('action', AuditAction::PaymentRecorded->value)->sole();
    $this->actingAs($auditor)->get('/org/audit?event='.$recorded->id)
        ->assertInertia(fn (Assert $page) => $page->where('selected.after.shares', AuditRecorder::REDACTED)
            ->where('selected.after.amount_minor', AuditRecorder::REDACTED));

    $paid = AuditEvent::query()->where('action', AuditAction::CommissionPayoutPaid->value)->sole();
    $this->actingAs($auditor)->get('/org/audit?event='.$paid->id)
        ->assertInertia(fn (Assert $page) => $page->where('selected.id', $paid->id)
            ->where('selected.after.amount_minor', AuditRecorder::REDACTED));
    $this->actingAs($this->f->owner)->get('/org/audit?event='.$paid->id)
        ->assertInertia(fn (Assert $page) => $page->where('selected.after.amount_minor', fn ($v) => is_int($v) && $v > 0));
});

it('a payment correction belongs to an order the payment is on', function () {
    finAgree($this->f, '1000');
    $payment = finPay($this->f->finance, $this->f->order, '500');
    $other = openCustomOrder($this->f->sales, $this->f->order->customer);

    finRefused(fn () => setCorrect($this->f->finance, $other, $payment, ['kind' => 'void']), InvalidTransition::class, 'payment_not_on_order');
    expect($payment->refresh()->status->value)->toBe('confirmed');
});

it('nobody changes their own commission rate; their row may be resent unchanged', function () {
    $finance = $this->f->finance;
    $settings = fn (?int $rate) => ['enabled' => true, 'default_rate_bp' => 500, 'manual_lines_eligible' => true, 'roles' => [],
        'members' => [['user_id' => $finance->id, 'rate_bp' => $rate]]];

    expect(fn () => app(UpdateCommissionSettings::class)->handle($finance, $settings(10_000), null))->toThrow(ValidationException::class);
    expect(CommissionRate::query()->where('user_id', $finance->id)->exists())->toBeFalse();

    app(UpdateCommissionSettings::class)->handle($this->f->owner, $settings(300), null);
    app(UpdateCommissionSettings::class)->handle($finance, $settings(300), null);
    expect(CommissionRate::query()->where('user_id', $finance->id)->value('rate_bp'))->toBe(300);
});

it('a P-97 holder without P-96 can correct a paid payout with its proof', function () {
    agrSettings(0);
    agrAgreed($this->f, [finLine('مطبخ', '1000')]);
    finPay($this->f->finance, $this->f->order, '1000');
    $payout = comPay($this->f->finance, $this->f->sales);
    $corrector = p6Member($this->f, 'supervisor', ['P-97', 'P-94']);

    $corrected = app(T04CorrectPayout::class)->handle($corrector, $payout->refresh(), ['amount' => '40', 'reason' => 'خطأ في المبلغ'],
        agrPdf('fix.pdf', Str::random(8)), $payout->lock_version, agrKey());

    expect($corrected->status)->toBe(PayoutStatus::Corrected)->and($corrected->corrected_minor)->toBe(4_000);
});

it('a discount limit comes only from the roles whose scope covers the order', function () {
    $user = User::factory()->create();
    app(T00CreateMembership::class)->handle($user, null, [$this->f->org->a->id, $this->f->org->b->id], [
        ['role_template_id' => Org::templateId('manager'), 'scope' => 'branches', 'targets' => [$this->f->org->b->id]],
        ['role_template_id' => Org::templateId('sales'), 'scope' => 'branches', 'targets' => [$this->f->org->a->id]],
    ], null, $this->f->owner, 'seeder');
    $inB = (new Order)->forceFill(['branch_id' => $this->f->org->b->id]);

    // manager 20% in branch B only; sales 10% elsewhere.
    expect(DiscountLimits::of($user, 'custom_bp', $this->f->order))->toBe(1_000)
        ->and(DiscountLimits::of($user, 'custom_bp', $inB))->toBe(2_000);
});

it('an agreement write answers with the agreement only to a viewer of the order', function () {
    expect(AgreementPresenter::forResponse($this->f->order, $this->f->executor))->toBeNull()
        ->and(AgreementPresenter::forResponse($this->f->order, $this->f->owner))->toBeArray();
});
