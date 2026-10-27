<?php

/**
 * COM-01: uniqueness of mobile and email inside the facility on normalized values, VAT/CR formats for
 * organizations, branch ownership (P-09) and the Q-27 email rule.
 */

use App\Actions\Customers\CreateCustomer;
use App\Audit\AuditAction;
use App\Models\AuditEvent;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->sales = $this->org->member('sales', [$this->org->a]);
    $this->base = ['kind' => 'person', 'name' => 'منيرة الشمري', 'mobile' => '0552147781', 'branch_id' => $this->org->a->id];
});

it('creates a customer with a normalized mobile and no email (Q-27)', function () {
    $this->actingAs($this->sales)->post('/customers', $this->base)->assertRedirect();

    $customer = Customer::query()->firstOrFail();
    expect($customer->mobile)->toBe('+966552147781')
        ->and($customer->email)->toBeNull()
        ->and($customer->branch_id)->toBe($this->org->a->id)
        ->and($customer->created_by)->toBe($this->sales->id);
});

it('audits the creation without sensitive values', function () {
    $this->actingAs($this->org->owner)->post('/customers', [
        'kind' => 'organization', 'name' => 'شركة', 'mobile' => '0501234567', 'branch_id' => $this->org->a->id,
        'vat_number' => '310458712300003', 'internal_notes' => 'سري',
    ])->assertRedirect();

    $event = AuditEvent::query()->where('action', AuditAction::CustomerCreated->value)->firstOrFail();
    expect($event->after['vat_number'])->toBe('[redacted]')
        ->and($event->after['internal_notes'])->toBe('[redacted]')
        ->and($event->actor_user_id)->toBe($this->org->owner->id);
});

it('refuses a duplicate mobile written in any format', function (string $again) {
    Customer::factory()->create(['mobile' => '+966552147781', 'branch_id' => $this->org->a->id]);

    $this->actingAs($this->sales)->post('/customers', ['mobile' => $again] + $this->base)->assertSessionHasErrors('mobile');

    expect(Customer::query()->count())->toBe(1);
})->with(['0552147781', '+966 55 214 7781', '00966552147781', '٠٥٥٢١٤٧٧٨١']);

it('refuses a duplicate email regardless of letter case', function () {
    Customer::factory()->create(['email' => 'munira@example.test', 'branch_id' => $this->org->a->id]);

    $this->actingAs($this->sales)->post('/customers', ['email' => 'Munira@Example.TEST'] + $this->base)->assertSessionHasErrors('email');
});

it('lets two customers share nothing but a branch, and an edit keep its own mobile', function () {
    $customer = Customer::factory()->create(['mobile' => '+966552147781', 'branch_id' => $this->org->a->id, 'created_by' => $this->sales->id]);

    $this->actingAs($this->sales)->put('/customers/'.$customer->id, ['name' => 'اسم جديد', 'kind' => 'person', 'mobile' => '0552147781'])
        ->assertSessionHasNoErrors();

    expect($customer->refresh()->name)->toBe('اسم جديد');
});

it('requires an email only when customer-area login is enabled', function () {
    $this->actingAs($this->sales)->post('/customers', ['portal_login_enabled' => true] + $this->base)->assertSessionHasErrors('email');
    expect(Customer::query()->count())->toBe(0);

    $this->actingAs($this->sales)->post('/customers', ['portal_login_enabled' => true, 'email' => 'a@example.test'] + $this->base)->assertSessionHasNoErrors();
});

it('validates the VAT number and commercial registration only when filled, for organizations', function () {
    $org = ['kind' => 'organization', 'mobile' => '0509998877'] + $this->base;

    $this->actingAs($this->sales)->post('/customers', $org)->assertSessionHasNoErrors();

    foreach (['vat_number' => ['123', '410458712300003', '310458712300004', '31045871230000X'], 'commercial_registration' => ['12345', '10105873421', 'abcdefghij']] as $field => $bad) {
        foreach ($bad as $i => $value) {
            $this->actingAs($this->sales)->post('/customers', [$field => $value, 'mobile' => '05011122'.$i.'3'] + $org)->assertSessionHasErrors($field);
        }
    }

    $this->actingAs($this->sales)->post('/customers', ['vat_number' => '310458712300003', 'commercial_registration' => '1010587342', 'office_phone' => '011 465 2290', 'mobile' => '0501112233'] + $org)
        ->assertSessionHasNoErrors();
    expect(Customer::query()->where('vat_number', '310458712300003')->value('office_phone'))->toBe('+966114652290');
});

it('drops business fields sent for an individual', function () {
    $this->actingAs($this->sales)->post('/customers', ['vat_number' => '310458712300003', 'commercial_registration' => '1010587342'] + $this->base)->assertSessionHasNoErrors();

    expect(Customer::query()->firstOrFail()->only(['vat_number', 'commercial_registration']))->toBe(['vat_number' => null, 'commercial_registration' => null]);
});

it('rejects an invalid mobile', function () {
    $this->actingAs($this->sales)->post('/customers', ['mobile' => '12345'] + $this->base)->assertSessionHasErrors('mobile');
});

it('keeps branch ownership inside the creator grant', function () {
    $this->actingAs($this->sales)->post('/customers', ['branch_id' => $this->org->c->id] + $this->base)->assertForbidden();

    expect(Customer::query()->count())->toBe(0);
});

it('forbids creating without a P-09 grant', function () {
    $executor = $this->org->member('executor', [$this->org->a]);

    $this->actingAs($executor)->post('/customers', $this->base)->assertForbidden();
});

it('turns a lost uniqueness race into the same validation error (database keys decide)', function () {
    Customer::factory()->create(['mobile' => '+966552147781', 'branch_id' => $this->org->a->id]);

    expect(fn () => app(CreateCustomer::class)->handle($this->sales, $this->base))->toThrow(ValidationException::class);
    expect(Customer::query()->count())->toBe(1);
});
