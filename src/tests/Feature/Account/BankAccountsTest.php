<?php

/*
| ADR-0046: the facility's bank accounts for customer transfers — Saudi IBAN validation (length, mod-97), one active
| default, P-02 to change (P-01 to view), every change audited with the IBAN redacted.
*/

use App\Audit\AuditRecorder;
use App\Finance\BankTransfer\BankTransferDetails;
use App\Finance\BankTransfer\SaudiIban;
use App\Models\AuditEvent;
use App\Models\FacilityBankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

uses(RefreshDatabase::class);

const BANK_IBAN = 'SA0380000000608010167519';
const BANK_IBAN_2 = 'SA1310000000000000000123';

beforeEach(function () {
    $this->org = Org::make();
    $this->valid = fn (array $overrides = []) => [
        'bank_name' => 'مصرف الراجحي',
        'beneficiary_name' => 'مؤسسة الركن الحديث للمطابخ',
        'iban' => 'sa03 8000 0000 6080 1016 7519',
        'account_number' => '608010167519',
        'swift_code' => 'rjhisari',
        'currency' => 'SAR',
        'transfer_note' => 'يرجى إرسال الإيصال بعد التحويل.',
        'is_default' => '1',
        'is_active' => '1',
        ...$overrides,
    ];
});

it('validates a Saudi IBAN: SA, 24 characters and the mod-97 check', function () {
    expect(SaudiIban::valid(BANK_IBAN))->toBeTrue()
        ->and(SaudiIban::valid('sa03 8000 0000 6080 1016 7519'))->toBeTrue()
        ->and(SaudiIban::valid('SA0480000000608010167519'))->toBeFalse() // wrong check digits
        ->and(SaudiIban::valid('SA038000000060801016751'))->toBeFalse() // 23 characters
        ->and(SaudiIban::valid('SA03800000006080101675199'))->toBeFalse() // 25 characters
        ->and(SaudiIban::valid('AE070331234567890123456'))->toBeFalse() // not Saudi
        ->and(SaudiIban::grouped(BANK_IBAN))->toBe('SA03 8000 0000 6080 1016 7519');

    foreach (['SA0480000000608010167519', 'SA03', 'GB82WEST12345698765432', ''] as $iban) {
        $this->actingAs($this->org->owner)->post(route('settings.bank.store'), ($this->valid)(['iban' => $iban]))
            ->assertSessionHasErrors('iban');
    }
    $this->actingAs($this->org->owner)->post(route('settings.bank.store'), ($this->valid)(['swift_code' => 'RJH']))
        ->assertSessionHasErrors('swift_code');
    expect(FacilityBankAccount::query()->count())->toBe(0);
});

it('saves an account normalised, makes the first active one the default and refuses a duplicate IBAN', function () {
    $this->actingAs($this->org->owner)->post(route('settings.bank.store'), ($this->valid)(['is_default' => '0']))
        ->assertRedirect(route('settings.bank'))->assertSessionHasNoErrors();

    $account = FacilityBankAccount::query()->sole();
    expect($account->iban)->toBe(BANK_IBAN)
        ->and($account->swift_code)->toBe('RJHISARI')
        ->and($account->currency)->toBe('SAR')
        ->and($account->is_default)->toBeTrue()
        ->and($account->updated_by)->toBe($this->org->owner->id);

    $this->actingAs($this->org->owner)->post(route('settings.bank.store'), ($this->valid)())->assertSessionHasErrors('iban');
});

it('keeps exactly one active default account', function () {
    $this->actingAs($this->org->owner)->post(route('settings.bank.store'), ($this->valid)());
    $this->actingAs($this->org->owner)->post(route('settings.bank.store'), ($this->valid)(['iban' => BANK_IBAN_2, 'bank_name' => 'البنك الأهلي', 'is_default' => '1']));
    [$first, $second] = FacilityBankAccount::query()->orderBy('id')->get()->all();
    expect($first->refresh()->is_default)->toBeFalse()->and($second->is_default)->toBeTrue()
        ->and(BankTransferDetails::forCustomer()['bank_name'])->toBe('البنك الأهلي');

    // Disabling the default hands the default to the other active account.
    $this->actingAs($this->org->owner)->put(route('settings.bank.update', $second), ($this->valid)(['iban' => BANK_IBAN_2, 'is_active' => '0', 'is_default' => '1']))
        ->assertSessionHasNoErrors();
    expect($second->refresh()->is_default)->toBeFalse()->and($second->is_active)->toBeFalse()
        ->and($first->refresh()->is_default)->toBeTrue()
        ->and(FacilityBankAccount::query()->where('is_default', true)->count())->toBe(1);

    // Deleting the last active account leaves nothing to show the customer.
    $this->actingAs($this->org->owner)->delete(route('settings.bank.destroy', $first))->assertRedirect(route('settings.bank'));
    expect(BankTransferDetails::forCustomer())->toBeNull()->and(BankTransferDetails::missing())->toBeTrue();
});

it('lets P-01 view but only P-02 change, and refuses staff without facility permissions', function () {
    $this->actingAs($this->org->owner)->post(route('settings.bank.store'), ($this->valid)());
    $account = FacilityBankAccount::query()->sole();
    $sales = $this->org->member('sales', [$this->org->a]);

    $this->actingAs($sales)->get(route('settings.bank'))->assertForbidden();
    $this->actingAs($sales)->post(route('settings.bank.store'), ($this->valid)(['iban' => BANK_IBAN_2]))->assertForbidden();
    $this->actingAs($sales)->put(route('settings.bank.update', $account), ($this->valid)(['bank_name' => 'x']))->assertForbidden();
    $this->actingAs($sales)->delete(route('settings.bank.destroy', $account))->assertForbidden();
    expect($account->refresh()->bank_name)->toBe('مصرف الراجحي')->and(FacilityBankAccount::query()->count())->toBe(1);

    $this->actingAs($this->org->owner)->get(route('settings.bank'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('account/bank-accounts')
            ->where('canEdit', true)
            ->where('accounts.0.iban_grouped', 'SA03 8000 0000 6080 1016 7519'));
});

it('audits every change with the IBAN redacted and only its last four digits kept', function () {
    $this->actingAs($this->org->owner)->post(route('settings.bank.store'), ($this->valid)());
    $account = FacilityBankAccount::query()->sole();
    $this->actingAs($this->org->owner)->put(route('settings.bank.update', $account), ($this->valid)(['beneficiary_name' => 'شركة الركن الحديث']));
    $this->actingAs($this->org->owner)->delete(route('settings.bank.destroy', $account));

    $events = AuditEvent::query()->where('area', 'settings')->where('action', 'like', 'settings.bank_account_%')->orderBy('id')->get();
    expect($events->pluck('action')->all())->toBe(['settings.bank_account_created', 'settings.bank_account_updated', 'settings.bank_account_deleted'])
        ->and($events->every(fn (AuditEvent $e) => $e->actor_user_id === $this->org->owner->id))->toBeTrue();

    [$created, $updated, $deleted] = $events->all();
    expect($created->after['ending'])->toBe('…7519')
        ->and($created->after['account_ending'])->toBe('…7519')
        ->and($created->after)->not->toHaveKey('iban')
        ->and($updated->before)->toBe(['beneficiary_name' => 'مؤسسة الركن الحديث للمطابخ'])
        ->and($updated->after)->toBe(['beneficiary_name' => 'شركة الركن الحديث'])
        ->and($deleted->before['bank_name'])->toBe('مصرف الراجحي');
    foreach ($events as $event) {
        expect(json_encode([$event->before, $event->after]))->not->toContain('608010167519');
    }
    expect(AuditRecorder::redact(['iban' => BANK_IBAN]))->toBe(['iban' => AuditRecorder::REDACTED]);
});

it('reminds the owner in the settings and on the home page until an active account exists', function () {
    $this->actingAs($this->org->owner)->get(route('settings.home'))
        ->assertInertia(fn (Assert $page) => $page->where('bankMissing', true));
    $this->actingAs($this->org->owner)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->missing('bankMissing')
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('bankMissing', true)));

    $sales = $this->org->member('sales', [$this->org->a]);
    $this->actingAs($sales)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->where('bankMissing', false)));

    $this->actingAs($this->org->owner)->post(route('settings.bank.store'), ($this->valid)());
    $this->actingAs($this->org->owner)->get(route('settings.home'))
        ->assertInertia(fn (Assert $page) => $page->where('bankMissing', false));
    $this->actingAs($this->org->owner)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->where('bankMissing', false)));
});
