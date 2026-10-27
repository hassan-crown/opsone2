<?php

/**
 * COM-02: contact persons of an organization — exactly one primary, removal keeps history, no effect on seats.
 */

use App\Actions\Customers\ManageContacts;
use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->sales = $this->org->member('sales', [$this->org->a]);
    $this->company = Customer::factory()->organization()->create(['branch_id' => $this->org->a->id]);
    $this->contacts = app(ManageContacts::class);
    $this->row = fn (string $name, string $mobile, bool $primary = false) => ['name' => $name, 'mobile' => $mobile, 'is_primary' => $primary];
});

function primaries(Customer $customer): array
{
    return CustomerContact::query()->where('customer_id', $customer->id)->whereNull('removed_at')->where('is_primary', true)->pluck('name')->all();
}

it('makes the first contact the primary and keeps exactly one primary', function () {
    $a = $this->contacts->add($this->sales, $this->company, ($this->row)('عادل', '0506621847'));
    $b = $this->contacts->add($this->sales, $this->company, ($this->row)('سلمان', '0553184402'));

    expect($a->is_primary)->toBeTrue()->and($b->is_primary)->toBeFalse()
        ->and(primaries($this->company))->toBe(['عادل']);

    $this->contacts->add($this->sales, $this->company, ($this->row)('فهد', '0569042271', true));
    expect(primaries($this->company))->toBe(['فهد']);

    $this->contacts->setPrimary($this->sales, $b);
    expect(primaries($this->company))->toBe(['سلمان']);
});

it('promotes another contact when the primary is removed, keeping the removed row', function () {
    $a = $this->contacts->add($this->sales, $this->company, ($this->row)('عادل', '0506621847'));
    $b = $this->contacts->add($this->sales, $this->company, ($this->row)('سلمان', '0553184402'));

    $this->contacts->remove($this->sales, $a);

    expect(primaries($this->company))->toBe(['سلمان'])
        ->and($a->refresh()->removed_at)->not->toBeNull()
        ->and($a->is_primary)->toBeFalse()
        ->and(CustomerContact::query()->count())->toBe(2);

    $this->contacts->remove($this->sales, $b);
    expect(primaries($this->company))->toBe([]);
});

it('lets the database refuse a second primary', function () {
    $this->contacts->add($this->sales, $this->company, ($this->row)('عادل', '0506621847'));

    expect(fn () => CustomerContact::query()->create(['customer_id' => $this->company->id, 'name' => 'x', 'mobile' => '+966501112233', 'is_primary' => true, 'primary_of' => $this->company->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('does not apply account uniqueness to contact mobiles', function () {
    $this->contacts->add($this->sales, $this->company, ($this->row)('عادل', $this->company->mobile));
    $other = Customer::factory()->organization()->create(['branch_id' => $this->org->a->id]);
    $this->contacts->add($this->sales, $other, ($this->row)('عادل', $this->company->mobile));

    expect(CustomerContact::query()->where('mobile', $this->company->mobile)->count())->toBe(2);
});

it('refuses contacts for an individual', function () {
    $person = Customer::factory()->create(['branch_id' => $this->org->a->id]);

    expect(fn () => $this->contacts->add($this->sales, $person, ($this->row)('x', '0506621847')))->toThrow(ValidationException::class);
});

it('requires P-10 over the customer', function () {
    $outsider = $this->org->member('sales', [$this->org->c]);

    $this->actingAs($outsider)->post('/customers/'.$this->company->id.'/contacts', ['name' => 'x', 'mobile' => '0506621847'])->assertForbidden();
    expect(CustomerContact::query()->count())->toBe(0);
});

it('manages contacts through the screens and validates the mobile', function () {
    $this->actingAs($this->sales)->post('/customers/'.$this->company->id.'/contacts', ['name' => 'عادل', 'mobile' => '123'])->assertSessionHasErrors('mobile');
    $this->actingAs($this->sales)->post('/customers/'.$this->company->id.'/contacts', ['name' => 'عادل', 'mobile' => '0506621847', 'job_title' => 'مشتريات'])->assertRedirect();

    $contact = CustomerContact::query()->firstOrFail();
    expect($contact->mobile)->toBe('+966506621847')->and($contact->is_primary)->toBeTrue();

    $this->actingAs($this->sales)->put('/customer-contacts/'.$contact->id, ['name' => 'عادل 2', 'mobile' => '0506621847'])->assertRedirect();
    $this->actingAs($this->sales)->delete('/customer-contacts/'.$contact->id)->assertRedirect();

    expect($contact->refresh()->removed_at)->not->toBeNull();
});
