<?php

/**
 * WEB-02 / P-72: the public contact form creates exactly one Interest per submission, returns a reference, and is
 * protected without a third-party captcha (honeypot, form-age token, throttle).
 */

use App\Models\Branch;
use App\Models\Interest;
use App\Models\SiteLeadSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    config(['site.lead.min_seconds' => 3]);
    RateLimiter::clear('x');
});

function leadPayload(array $override = []): array
{
    return array_merge([
        'name' => 'سلمان', 'mobile' => '0551234567', 'email' => null, 'need' => 'أحتاج مطبخًا جديدًا',
        'submission' => (string) Str::uuid(),
        'form_token' => Crypt::encryptString((string) (time() - 30)),
        'website' => '',
    ], $override);
}

it('creates exactly one website interest with a reference and no account', function () {
    $payload = leadPayload();

    $this->post('/ar/contact', $payload)->assertRedirect()->assertSessionHas('lead_reference');

    expect(Interest::query()->count())->toBe(1);
    $interest = Interest::query()->firstOrFail();
    expect($interest->channel->value)->toBe('website')
        ->and($interest->status->value)->toBe('new')
        ->and($interest->reference)->toBe(session('lead_reference'))
        ->and($interest->branch_id)->toBe($this->org->a->id)
        ->and($interest->mobile)->toBe('+966551234567')
        ->and($interest->created_by)->toBeNull()
        ->and($interest->customer_id)->toBeNull();
});

it('a retry with the same submission key returns the same interest and never duplicates', function () {
    $payload = leadPayload();

    $this->post('/en/contact', $payload)->assertSessionHas('lead_reference');
    $first = session('lead_reference');
    $this->post('/en/contact', $payload)->assertSessionHas('lead_reference', $first);

    expect(Interest::query()->count())->toBe(1)->and(SiteLeadSubmission::query()->count())->toBe(1);
});

it('a new key makes a new interest', function () {
    $this->post('/en/contact', leadPayload())->assertSessionHas('lead_reference');
    $this->post('/en/contact', leadPayload(['name' => 'Another']))->assertSessionHas('lead_reference');

    expect(Interest::query()->count())->toBe(2);
});

it('the honeypot silently drops bots without storing anything', function () {
    $this->post('/en/contact', leadPayload(['website' => 'http://spam.example']))
        ->assertRedirect()->assertSessionHas('lead_reference'); // same answer as a human gets

    expect(Interest::query()->count())->toBe(0);
});

it('a form submitted faster than a human or with a forged token is dropped', function () {
    $this->post('/en/contact', leadPayload(['form_token' => Crypt::encryptString((string) time())]))->assertSessionHas('lead_reference');
    $this->post('/en/contact', leadPayload(['form_token' => 'forged']))->assertSessionHas('lead_reference');

    expect(Interest::query()->count())->toBe(0);
});

it('validates the fields and needs a way to reach the visitor', function () {
    $this->post('/en/contact', leadPayload(['name' => '', 'mobile' => null, 'email' => null, 'need' => 'x']))
        ->assertSessionHasErrors(['name', 'mobile', 'email', 'need']);
    $this->post('/en/contact', leadPayload(['mobile' => null, 'email' => 'not-an-email']))->assertSessionHasErrors('email');
    $this->post('/en/contact', leadPayload(['mobile' => null, 'email' => 'a@b.sa']))->assertSessionHasNoErrors();

    expect(Interest::query()->count())->toBe(1);
});

it('is throttled per IP after the hourly limit', function () {
    config(['site.lead.per_ip_per_hour' => 5, 'site.lead.per_ip_per_day' => 20]);
    for ($i = 0; $i < 5; $i++) {
        $this->post('/en/contact', leadPayload())->assertStatus(302);
    }

    $this->post('/en/contact', leadPayload())->assertStatus(429);
    expect(Interest::query()->count())->toBe(5);
});

it('fails cleanly when the facility has no active branch', function () {
    Branch::query()->update(['status' => 'disabled']);

    $this->post('/en/contact', leadPayload())->assertSessionHasErrors('form');
    expect(Interest::query()->count())->toBe(0);
});

it('the public page renders the form with a token, a key and a hidden honeypot', function () {
    $html = $this->get('/en')->assertOk()->getContent();

    expect($html)->toContain('name="form_token"')->and($html)->toContain('name="submission"')
        ->and($html)->toContain('name="website"')->and($html)->toContain('action="'.url('/en/contact').'"');
});

it('the form keeps the language of the page it was sent from', function () {
    $this->get('/ar')->assertSee('action="'.url('/ar/contact').'"', false);
});
