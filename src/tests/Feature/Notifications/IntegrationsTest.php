<?php

use App\Integrations\FacilityMail;
use App\Integrations\IntegrationSettings;
use App\Integrations\PublicHost;
use App\Mail\OrgMessage;
use App\Models\AuditEvent;
use App\Models\IntegrationSetting;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

uses(RefreshDatabase::class);

const SECRET = 'S3cret-Pass-Word!';

beforeEach(function () {
    $this->org = Org::make();
    $this->smtp = fn (array $o = []) => [
        'host' => 'smtp.example.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'mailer@example.com',
        'password' => SECRET,
        'from_address' => 'noreply@example.com',
        'from_name' => 'Facility',
        ...$o,
    ];
});

it('is limited to those who may edit the facility', function () {
    $manager = $this->org->member('manager', [$this->org->a]);
    $sales = $this->org->member('sales', [$this->org->a]);

    foreach ([$this->org->owner, $manager] as $user) {
        $this->actingAs($user)->get(route('integrations.edit'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('account/settings-integrations'));
    }

    $this->actingAs($sales);
    $this->get(route('integrations.edit'))->assertForbidden();
    $this->put(route('integrations.smtp.update'), ($this->smtp)())->assertForbidden();
    $this->post(route('integrations.smtp.test'))->assertForbidden();
    $this->post(route('integrations.smtp.enable'))->assertForbidden();
    $this->post(route('integrations.smtp.disable'))->assertForbidden();
    expect(IntegrationSetting::query()->count())->toBe(0);

    auth()->logout();
    $this->get(route('integrations.edit'))->assertRedirect(route('login'));
});

it('stores the account encrypted and never sends the password back', function () {
    $this->actingAs($this->org->owner);
    $this->put(route('integrations.smtp.update'), ($this->smtp)())->assertRedirect();

    $raw = (string) DB::table('integration_settings')->where('key', 'smtp')->value('config');
    expect($raw)->not->toContain(SECRET)->and($raw)->not->toContain('smtp.example.com');

    $response = $this->get(route('integrations.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('smtp.host', 'smtp.example.com')
            ->where('smtp.has_password', true)
            ->missing('smtp.password'));

    // Neither the Inertia payload nor the rendered page carries the secret.
    expect($response->getContent())->not->toContain(SECRET);
    expect(json_encode(IntegrationSetting::query()->first()))->not->toContain(SECRET);
    expect(json_encode($this->get(route('dashboard'))->viewData('page')['props']))->not->toContain(SECRET);
});

it('keeps the saved password when the field is left blank', function () {
    $this->actingAs($this->org->owner);
    $this->put(route('integrations.smtp.update'), ($this->smtp)())->assertRedirect();
    $this->put(route('integrations.smtp.update'), ($this->smtp)(['password' => '', 'from_name' => 'Renamed']))->assertRedirect();

    expect(IntegrationSetting::query()->first()->config['password'])->toBe(SECRET);
});

it('requires a successful test before enabling, and a connection change withdraws it', function () {
    Mail::fake();
    $this->actingAs($this->org->owner);
    $this->put(route('integrations.smtp.update'), ($this->smtp)())->assertRedirect();

    $this->post(route('integrations.smtp.enable'))->assertRedirect();
    expect(IntegrationSetting::query()->first()->enabled)->toBeFalse();

    $this->post(route('integrations.smtp.test'))->assertRedirect();
    Mail::assertSent(OrgMessage::class, 1);
    expect(IntegrationSetting::query()->first()->test_ok)->toBeTrue();

    $this->post(route('integrations.smtp.enable'))->assertRedirect();
    expect(IntegrationSetting::query()->first()->enabled)->toBeTrue()
        ->and(app(FacilityMail::class)->mailer())->toBe('facility')
        ->and(app(FacilityMail::class)->from()['address'])->toBe('noreply@example.com');

    // Changing the host disables it again until the new settings pass a test.
    $this->put(route('integrations.smtp.update'), ($this->smtp)(['host' => 'smtp.other.example.com', 'password' => '']))->assertRedirect();
    $row = IntegrationSetting::query()->first();
    expect($row->enabled)->toBeFalse()->and($row->test_ok)->toBeNull();

    $this->post(route('integrations.smtp.disable'))->assertRedirect();
});

it('reports a failed test without leaking the secret', function () {
    $this->actingAs($this->org->owner);
    // A closed local port: the connection is refused at once.
    $this->put(route('integrations.smtp.update'), ($this->smtp)(['host' => '127.0.0.1', 'port' => 2525]))->assertRedirect();

    $response = $this->post(route('integrations.smtp.test'))->assertRedirect();

    expect(IntegrationSetting::query()->first()->test_ok)->toBeFalse()
        ->and(json_encode(session()->all()))->not->toContain(SECRET);
    $this->post(route('integrations.smtp.enable'));
    expect(IntegrationSetting::query()->first()->enabled)->toBeFalse();
});

it('validates the smtp form and refuses internal hosts outside local development', function () {
    $this->actingAs($this->org->owner);

    $this->put(route('integrations.smtp.update'), ($this->smtp)(['port' => 22]))->assertSessionHasErrors('port');
    $this->put(route('integrations.smtp.update'), ($this->smtp)(['encryption' => 'none']))->assertSessionHasErrors('encryption');
    $this->put(route('integrations.smtp.update'), ($this->smtp)(['host' => 'not a host']))->assertSessionHasErrors('host');

    // Outside local development (CSRF is enforced there, so send the token).
    app()->detectEnvironment(fn () => 'production');
    $this->withSession(['_token' => 'csrf']);
    foreach (['10.0.0.5', '127.0.0.1', 'db.internal'] as $host) {
        $this->put(route('integrations.smtp.update'), ($this->smtp)(['host' => $host, '_token' => 'csrf']))->assertSessionHasErrors('host');
    }
    expect(IntegrationSetting::query()->count())->toBe(0);
});

it('falls back to the application mailer when the account is not enabled', function () {
    $this->actingAs($this->org->owner);
    $this->put(route('integrations.smtp.update'), ($this->smtp)())->assertRedirect();

    expect(app(IntegrationSettings::class)->smtp())->toBeNull()
        ->and(app(FacilityMail::class)->mailer())->toBe(config('mail.default'));
});

it('shows sms as not configured until a provider is connected', function () {
    app()->bind(SmsChannel::class, fn () => new class implements SmsChannel
    {
        public function isConfigured(): bool
        {
            return false;
        }

        public function send(string $to, string $text): void {}
    });

    $this->actingAs($this->org->owner)->get(route('integrations.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('sms.configured', false));
});

it('audits settings changes without credentials', function () {
    $this->actingAs($this->org->owner);
    $this->put(route('integrations.smtp.update'), ($this->smtp)())->assertRedirect();
    $this->post(route('integrations.smtp.disable'))->assertRedirect();

    $events = AuditEvent::query()->where('area', 'integrations')->get();
    expect($events->pluck('action')->all())->toContain('integrations.smtp_saved', 'integrations.smtp_disabled')
        ->and(json_encode($events->map->only(['before', 'after'])))->not->toContain(SECRET)
        ->and(json_encode($events->map->only(['before', 'after'])))->not->toContain('mailer@example.com');
});

it('refuses a public-looking host that resolves inside the network (SSRF)', function () {
    app()->detectEnvironment(fn () => 'production');
    app()->instance(PublicHost::class, new class extends PublicHost
    {
        protected function resolve(string $host): array
        {
            return $host === 'smtp.example.com' ? ['93.184.216.34'] : ['169.254.169.254'];
        }
    });
    $this->actingAs($this->org->owner)->withSession(['_token' => 'csrf']);

    $this->put(route('integrations.smtp.update'), ($this->smtp)(['host' => 'rebind.example.net', '_token' => 'csrf']))->assertSessionHasErrors('host');
    $this->put(route('integrations.smtp.update'), ($this->smtp)(['_token' => 'csrf']))->assertSessionHasNoErrors();

    // Re-checked before connecting: a saved host that now resolves privately is refused.
    expect(fn () => app(FacilityMail::class)->register(($this->smtp)(['host' => 'rebind.example.net'])))->toThrow(RuntimeException::class);
});

it('keeps SMTP failure details out of the log', function () {
    Log::spy();
    $this->actingAs($this->org->owner);
    $this->put(route('integrations.smtp.update'), ($this->smtp)(['host' => '127.0.0.1', 'port' => 2525]));
    $this->post(route('integrations.smtp.test'));

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'SMTP test failed'
        && ! str_contains(json_encode($context), 'mailer@example.com'));
});
