<?php

/**
 * Scope matrix of P-94..P-98 (SAL-15, SAL-16), the sale owner / rate corrections (Q-37 a) and the HTTP screens.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\Commissions\DecideSaleCorrection;
use App\Actions\Commissions\RequestSaleCorrection;
use App\Commissions\CommissionBalances;
use App\Models\Commissions\CommissionEntry;
use App\Models\Commissions\CommissionSettings;
use App\Models\Commissions\OrderSale;
use App\Models\Commissions\SaleCorrection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/CommissionTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = commissionFixture(500);
    agrSettings(0);
    agrAgreed($this->f, [finLine('مطبخ', '1000')]);
    finPay($this->f->finance, $this->f->order, '1000');
    // A second sale, of sales2.
    $first = $this->f->order;
    $this->f->order = openCustomOrder($this->f->sales2, $first->customer);
    agrAgreed($this->f, [finLine('مطبخ', '2000')]);
    finPay($this->f->finance, $this->f->order, '2000');
    $this->other = $this->f->order;
    $this->f->order = $first;
    $this->f->managerB = $this->f->org->member('manager', [$this->f->org->b]);
});

function comMembers(object $test, User $viewer): array
{
    return collect($test->actingAs($viewer)->getJson('/finance/commissions')->assertOk()->json('members'))->pluck('available_minor', 'id')->all();
}

it('P-94 scope: a sales person sees only their own commission; finance and the owner see everyone; a branch manager elsewhere sees nothing', function () {
    $f = $this->f;
    expect(comMembers($this, $f->sales))->toBe([$f->sales->id => 5_000])
        ->and(comMembers($this, $f->sales2))->toBe([$f->sales2->id => 10_000])
        ->and(comMembers($this, $f->finance))->toEqualCanonicalizing([$f->sales->id => 5_000, $f->sales2->id => 10_000])
        ->and(comMembers($this, $f->owner))->toEqualCanonicalizing([$f->sales->id => 5_000, $f->sales2->id => 10_000])
        ->and(comMembers($this, $f->managerB))->toBe([]);

    $this->actingAs($f->sales)->getJson("/finance/commissions/members/{$f->sales2->id}")->assertForbidden();
    $this->actingAs($f->sales)->getJson("/finance/commissions/members/{$f->sales->id}")->assertOk()
        ->assertJsonPath('summary.available_minor', 5_000)->assertJsonPath('can.pay', false)->assertJsonPath('payable', null);
    $this->actingAs($f->supervisor)->getJson('/finance/commissions')->assertForbidden();
    $this->actingAs($f->executor)->getJson('/finance/commissions')->assertForbidden();
});

it('P-96 scope: only a payout holder records a payout, never a sales person', function () {
    $f = $this->f;
    $this->actingAs($f->sales)->postJson("/finance/commissions/members/{$f->sales2->id}/payouts", [
        'idempotency_key' => agrKey(), 'period_from' => now()->startOfMonth()->toDateString(), 'period_to' => now()->toDateString(),
    ])->assertForbidden();
    $this->actingAs($f->managerB)->postJson("/finance/commissions/members/{$f->sales->id}/payouts", [
        'idempotency_key' => agrKey(), 'period_from' => now()->startOfMonth()->toDateString(), 'period_to' => now()->toDateString(),
    ])->assertForbidden();

    $created = $this->actingAs($f->finance)->postJson("/finance/commissions/members/{$f->sales->id}/payouts", [
        'idempotency_key' => agrKey(), 'period_from' => now()->startOfMonth()->toDateString(), 'period_to' => now()->toDateString(),
    ])->assertCreated()->assertJsonPath('statement.payouts.0.status', 'draft');

    $id = $created->json('payout_id');
    $this->actingAs($f->finance)->post("/finance/commissions/payouts/{$id}/approve", [
        'idempotency_key' => agrKey(), 'lock_version' => 1, 'pay_now' => '1', ...comExecution(),
        'proof' => UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\npayout"),
    ], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('statement.payouts.0.status', 'paid')
        ->assertJsonPath('statement.payouts.0.amount_minor', 5_000)
        ->assertJsonPath('statement.summary.available_minor', 0);
});

it('owner correction (SAL-16): requested with a reason by P-98, approved by another P-97 holder; accrual moves once, paid stays', function () {
    $f = $this->f;
    comPay($f->finance, $f->sales);

    // A sales person cannot reassign their sale; nobody moves commission to themselves.
    comRefused(fn () => app(RequestSaleCorrection::class)->handle($f->sales, $f->order, ['kind' => 'owner', 'to_user_id' => $f->sales2->id, 'reason' => 'خطأ']), AuthorizationException::class);
    comRefused(fn () => app(RequestSaleCorrection::class)->handle($f->manager, $f->order, ['kind' => 'owner', 'to_user_id' => $f->manager->id, 'reason' => 'خطأ']), InvalidTransition::class, 'own_sale');

    $correction = app(RequestSaleCorrection::class)->handle($f->manager, $f->order, ['kind' => 'owner', 'to_user_id' => $f->sales2->id, 'reason' => 'سجل باسم الموظف الخطأ']);
    expect(comSale($f->order)->user_id)->toBe($f->sales->id);

    comRefused(fn () => app(DecideSaleCorrection::class)->approve($f->manager, $correction, $correction->lock_version), AuthorizationException::class);
    comRefused(fn () => app(RequestSaleCorrection::class)->handle($f->owner, $f->order, ['kind' => 'rate', 'rate_bp' => 700, 'reason' => 'x']), InvalidTransition::class, 'correction_open');

    app(DecideSaleCorrection::class)->approve($f->finance, $correction, $correction->lock_version);

    $sale = comSale($f->order);
    expect($sale->user_id)->toBe($f->sales2->id)
        ->and($sale->rate_bp)->toBe(500)
        ->and(OrderSale::query()->where('order_id', $f->order->id)->orderBy('id')->pluck('status')->all())->toBe(['superseded', 'active'])
        ->and(comAccrued($f->order, $f->sales))->toBe(0)
        ->and(comAccrued($f->order, $f->sales2))->toBe(5_000)
        ->and(comAccrued($f->order))->toBe(5_000)
        // The 50 paid to sales stays paid; their statement carries −50, sales2 gains 50.
        ->and(app(CommissionBalances::class)->summary($f->sales->id)['available_minor'])->toBe(-5_000)
        ->and(app(CommissionBalances::class)->summary($f->sales2->id)['available_minor'])->toBe(15_000);
});

it('rate correction (P-97): approval writes one difference on the new sale; a rejection changes nothing', function () {
    $f = $this->f;
    $correction = app(RequestSaleCorrection::class)->handle($f->finance, $f->order, ['kind' => 'rate', 'rate_bp' => 700, 'reason' => 'نسبة الاتفاق الموقع 7%']);
    comRefused(fn () => app(DecideSaleCorrection::class)->approve($f->finance, $correction, $correction->lock_version), InvalidTransition::class, 'own_request');
    comRefused(fn () => app(DecideSaleCorrection::class)->approve($f->sales, $correction, $correction->lock_version), AuthorizationException::class);

    app(DecideSaleCorrection::class)->approve($f->owner, $correction, $correction->lock_version);
    expect(comSale($f->order)->rate_bp)->toBe(700)
        ->and(comAccrued($f->order, $f->sales))->toBe(7_000)
        ->and(CommissionEntry::query()->where('order_id', $f->order->id)->orderBy('id')->pluck('kind')->all())
        ->toBe(['accrual', 'transfer_out', 'transfer_in', 'accrual']);

    $second = app(RequestSaleCorrection::class)->handle($f->finance, $f->order, ['kind' => 'rate', 'rate_bp' => 100, 'reason' => 'خطأ']);
    app(DecideSaleCorrection::class)->reject($f->owner, $second, 'لا يوجد ما يبرره', $second->lock_version);
    expect($second->refresh()->status)->toBe(SaleCorrection::REJECTED)->and(comSale($f->order)->rate_bp)->toBe(700);
});

it('renders the screens: FinCommissions, the member statement, the sale page and the commission settings', function () {
    $f = $this->f;
    $this->actingAs($f->finance)->get('/finance/commissions')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('finance/commissions')->has('members', 2)->where('can.settings', true));
    $this->actingAs($f->sales)->get('/finance/commissions/mine')->assertRedirect("/finance/commissions/members/{$f->sales->id}");
    $this->actingAs($f->sales)->get("/finance/commissions/members/{$f->sales->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('finance/commission-member')->has('sales', 1)->where('can.pay', false));
    $this->actingAs($f->manager)->get("/orders/{$f->order->id}/sale")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('orders/sale')->where('sale.sale.member.id', $f->sales->id)
            ->where('sale.can.request_owner', true)->where('sale.can.request_rate', false));
    $this->actingAs($f->executor)->get("/orders/{$f->order->id}/sale")->assertForbidden();
    $this->actingAs($f->finance)->get('/settings/sales')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canEditCommissions', true)->where('commissions.default_rate_bp', 500));

    $this->actingAs($f->manager)->put('/settings/sales/commissions', ['enabled' => true, 'default_rate_bp' => 100, 'manual_lines_eligible' => true, 'roles' => [], 'members' => []])
        ->assertForbidden();
    $this->actingAs($f->finance)->put('/settings/sales/commissions', [
        'enabled' => true, 'default_rate_bp' => 250, 'manual_lines_eligible' => false, 'roles' => [], 'members' => [], 'lock_version' => CommissionSettings::current()->lock_version,
    ])->assertRedirect('/settings/sales');
    expect(CommissionSettings::current()->default_rate_bp)->toBe(250);
});
