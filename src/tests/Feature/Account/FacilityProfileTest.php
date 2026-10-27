<?php

use App\Models\Facility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->version = fn () => Facility::currentOrFail()->updated_at->toIso8601String();
    $this->valid = fn (array $overrides = []) => [
        'name_ar' => 'مطابخ الركن الحديث',
        'name_en' => 'Modern Corner Kitchens',
        'contact_email' => 'info@example.com',
        'contact_phone' => '+966 11 555 0100',
        'address' => 'Riyadh',
        'default_locale' => 'ar',
        'timezone' => 'Asia/Riyadh',
        'currency' => 'SAR',
        'version' => ($this->version)(),
        ...$overrides,
    ];
});

it('shows the profile to the owner and a manager', function () {
    $manager = $this->org->member('manager', [$this->org->a]);

    foreach ([$this->org->owner, $manager] as $user) {
        $this->actingAs($user)->get(route('settings.basic'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('account/settings-basic')
                ->where('canEdit', true)
                ->where('facility.name_ar', 'منشأة الاختبار')
                ->where('facility.currency_locked', false));
    }
});

it('denies the profile page to staff without facility permissions', function () {
    $sales = $this->org->member('sales', [$this->org->a]);

    $this->actingAs($sales)->get(route('settings.basic'))->assertForbidden();
    $this->actingAs($sales)->put(route('settings.basic.update'), ($this->valid)())->assertForbidden();
});

it('saves the profile', function () {
    $this->actingAs($this->org->owner)->put(route('settings.basic.update'), ($this->valid)([
        'default_locale' => 'en',
        'timezone' => 'Asia/Dubai',
        'currency' => 'AED',
    ]))->assertRedirect(route('settings.basic'));

    $facility = Facility::currentOrFail();
    expect($facility->name_en)->toBe('Modern Corner Kitchens')
        ->and($facility->contact_email)->toBe('info@example.com')
        ->and($facility->default_locale)->toBe('en')
        ->and($facility->timezone)->toBe('Asia/Dubai')
        ->and($facility->currency)->toBe('AED');
});

it('validates the profile', function (array $overrides, string $field) {
    $this->actingAs($this->org->owner)
        ->from(route('settings.basic'))
        ->put(route('settings.basic.update'), ($this->valid)($overrides))
        ->assertRedirect(route('settings.basic'))
        ->assertSessionHasErrors($field);

    expect(Facility::currentOrFail()->name_ar)->toBe('منشأة الاختبار');
})->with([
    'missing arabic name' => [['name_ar' => ''], 'name_ar'],
    'bad email' => [['contact_email' => 'not-an-email'], 'contact_email'],
    'bad phone' => [['contact_phone' => 'call me'], 'contact_phone'],
    'unknown locale' => [['default_locale' => 'fr'], 'default_locale'],
    'unknown timezone' => [['timezone' => 'Mars/Base'], 'timezone'],
    'unknown currency' => [['currency' => 'XXX'], 'currency'],
    'missing version' => [['version' => ''], 'version'],
]);

it('refuses a currency change once a financial movement locked it', function () {
    Facility::query()->update(['currency_locked_at' => now()]);

    $this->actingAs($this->org->owner)
        ->from(route('settings.basic'))
        ->put(route('settings.basic.update'), ($this->valid)(['currency' => 'AED']))
        ->assertSessionHasErrors('currency');

    expect(Facility::currentOrFail()->currency)->toBe('SAR');
});

it('does not overwrite a newer save', function () {
    $stale = ($this->version)();
    $this->travel(5)->seconds();
    Facility::query()->update(['name_ar' => 'اسم أحدث']);
    Facility::currentOrFail()->touch();

    $this->actingAs($this->org->owner)
        ->from(route('settings.basic'))
        ->put(route('settings.basic.update'), ($this->valid)(['version' => $stale, 'name_ar' => 'اسم قديم']))
        ->assertSessionHasErrors('version');

    expect(Facility::currentOrFail()->name_ar)->toBe('اسم أحدث');
});

it('blocks saving in the read-only license phase', function () {
    LicenseFactory::install([
        'starts_at' => now()->subDays(400)->toIso8601ZuluString(),
        'ends_at' => now()->subDays(100)->toIso8601ZuluString(),
        'issued_at' => now()->addMinute()->toIso8601ZuluString(),
    ]);

    $this->actingAs($this->org->owner)->put(route('settings.basic.update'), ($this->valid)())->assertForbidden();
});
