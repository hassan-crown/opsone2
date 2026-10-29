<?php

/**
 * Binding scenario 16 «تسليم اشتراك يتعثر إشعاره» («اختبارات ترابط ملزمة»), facility side: the subscription term does
 * not start before a proven delivery; delivering again creates no second period and no invitation carrying a ready
 * password.
 *
 * Traces: SRS-SUB-21 (one start from the delivery; a notice failing before completion does not start the counter;
 * repetition never extends the term), the delivery event (valid access invitation; payment, copying or uploading the
 * license file is not a delivery), P08; M-19 (license install), ADR-0004, ADR-0003.
 *
 * Out of scope: the central app (payment, readiness checks, the delivery event itself, its notices). It reaches the
 * deployment only as signed license files (`license:install`) and the provisioning invitation (`org:invite-owner`),
 * both run as the operator would. The owner then acts over HTTP (`/owner/invite/{token}`, `/dashboard`).
 */

use App\Licensing\CurrentLicense;
use App\Licensing\LicenseStatus;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\OutboxMessage;
use App\Models\OwnerInvitation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LicenseFactory;

require_once __DIR__.'/AcceptancePortalHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake(); // outbound mail stays in the outbox, readable
    $this->travelTo(CarbonImmutable::parse('2026-10-01T09:00:00Z'));
    $this->dir = storage_path('framework/testing/acc16-'.bin2hex(random_bytes(4)));
    @mkdir($this->dir, 0755, true);
    config(['license.path' => $this->dir.'/license.json']);
    app()->forgetScopedInstances();
    (new RoleTemplateSeeder)->run();
    Facility::query()->create(['name_ar' => 'منشأة التسليم', 'name_en' => 'Delivery facility']);
});

afterEach(function () {
    array_map(unlink(...), glob($this->dir.'/*') ?: []);
    @rmdir($this->dir);
});

/**
 * The operator installs a license file received from the central app.
 *
 * @param  array<string, mixed>  $overrides
 */
function accP16Install(object $test, array $overrides, string $name): string
{
    $file = $test->dir.'/'.$name.'.json';
    if (! is_file($file)) {
        file_put_contents($file, LicenseFactory::signed($overrides));
    }
    $test->artisan('license:install', ['path' => $file])->assertSuccessful();

    return $file;
}

function accP16OwnerInvites(): int
{
    return OwnerInvitation::query()->where('status', 'pending')->count();
}

it('the term starts only from the proven delivery; re-delivery creates no second period and no ready-password invitation', function () {
    $license = app(CurrentLicense::class);
    // Freshly provisioned deployment: no license, no counter.
    expect($license->refresh()->status)->toBe(LicenseStatus::NeedsAttention)->and($license->daysRemaining())->toBeNull();

    // Provisioning installs the signed but not yet activated license (no delivery yet): still no counter.
    accP16Install($this, ['activation_id' => null, 'starts_at' => null, 'ends_at' => null, 'issued_at' => now('UTC')->toIso8601ZuluString()], 'provisioned');
    expect($license->refresh()->status)->toBe(LicenseStatus::Provisioned)
        ->and($license->daysRemaining())->toBeNull()
        ->and($license->snapshot()->phaseEndsAt())->toBeNull()
        ->and($license->acceptsNewWork())->toBeFalse();

    // The owner's access invitation: a single-use link, never an account or a password.
    $this->artisan('org:invite-owner', ['email' => 'owner@example.com', '--name' => 'Fahad'])->assertSuccessful();
    $mail = OutboxMessage::query()->where('idempotency_key', 'like', 'invite:OwnerInvitation:%')->sole();
    $mailText = (string) json_encode($mail->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    expect(accP16OwnerInvites())->toBe(1)
        ->and(User::query()->count())->toBe(0)
        ->and($mailText)->toContain('/owner/invite/')
        ->and(mb_strtolower($mailText))->not->toContain('password')
        ->and($mailText)->not->toContain('كلمة المرور');

    // The delivery notice stalls for 20 days: the counter has not started.
    $this->travel(20)->days();
    expect($license->refresh()->status)->toBe(LicenseStatus::Provisioned)->and($license->daysRemaining())->toBeNull();

    // Delivery proven: the central app issues the activated license whose term starts at the delivery.
    $deliveredAt = CarbonImmutable::now('UTC');
    $activated = [
        'activation_id' => 'act-delivery-1', 'issued_at' => $deliveredAt->toIso8601ZuluString(),
        'starts_at' => $deliveredAt->toIso8601ZuluString(), 'ends_at' => $deliveredAt->addDays(365)->toIso8601ZuluString(),
    ];
    $file = accP16Install($this, $activated, 'delivered');
    expect($license->refresh()->status)->toBe(LicenseStatus::Active)
        ->and($license->daysRemaining())->toBe(365)
        ->and($license->snapshot()->phaseEndsAt()?->toIso8601ZuluString())->toBe('2027-10-21T09:00:00Z');

    // A day later the delivery is repeated: the same license again and the invitation sent again.
    $this->travel(1)->day();
    $this->artisan('license:install', ['path' => $file])->assertSuccessful();
    $this->artisan('org:invite-owner', ['email' => 'owner@example.com', '--name' => 'Fahad'])->assertSuccessful();

    expect($license->refresh()->status)->toBe(LicenseStatus::Active)
        // Same single period: never extended or restarted by the repetition.
        ->and($license->daysRemaining())->toBe(364)
        ->and($license->snapshot()->phaseEndsAt()?->toIso8601ZuluString())->toBe('2027-10-21T09:00:00Z')
        // Only the newest link works; still no account and no password anywhere.
        ->and(accP16OwnerInvites())->toBe(1)
        ->and(OwnerInvitation::query()->where('status', 'cancelled')->count())->toBe(1)
        ->and(User::query()->count())->toBe(0)
        ->and(DB::table('audit_events')->where('action', 'license.installed')->count())->toBe(3)
        ->and(DB::table('audit_events')->where('action', 'owner_invitation.created')->count())->toBe(2);

    // The owner opens the newest link and chooses their own password with the emailed code.
    $latest = OutboxMessage::query()->where('idempotency_key', 'like', 'invite:OwnerInvitation:%')->latest('id')->firstOrFail();
    preg_match('#/owner/invite/([A-Za-z0-9]+)#', (string) json_encode($latest->payload, JSON_UNESCAPED_SLASHES), $m);
    $token = $m[1];
    accPFreshBrowser();
    $this->get("/owner/invite/{$token}")->assertInertia(fn (Assert $p) => $p->component('auth/owner-invite')->where('state', 'valid'));
    $this->post("/owner/invite/{$token}/code")->assertRedirect();
    $codeMail = OutboxMessage::query()->where('recipient', 'owner@example.com')->latest('id')->firstOrFail();
    preg_match('/\b(\d{6})\b/', implode(' ', $codeMail->payload['lines'] ?? []), $found);
    $code = $found[1];
    $this->post("/owner/invite/{$token}", [
        'code' => $code, 'name' => 'Fahad', 'password' => 'own-chosen-pass-2026', 'password_confirmation' => 'own-chosen-pass-2026',
    ])->assertRedirect(route('org.setup'));

    $owner = User::query()->where('email', 'owner@example.com')->sole();
    expect(Facility::current()->owner_membership_id)->toBe(Membership::query()->where('user_id', $owner->id)->value('id'));

    // The owner sees the one running term; a further delivery cannot issue another invitation.
    $this->actingAs($owner)->get('/dashboard')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('license.status', 'active')->where('license.days_remaining', 364)
            ->where('license.phase_ends_at', '2027-10-21T09:00:00Z'));
    $this->artisan('org:invite-owner', ['email' => 'second@example.com'])->assertFailed();
    expect(OwnerInvitation::query()->count())->toBe(2)->and(accP16OwnerInvites())->toBe(0);
});

it('a license file for an activation the delivery has not started yet (term in the future) does not start the counter', function () {
    // The central app prepared the activation for a delivery planned later; the file arrives early.
    $start = CarbonImmutable::now('UTC')->addDays(5);
    accP16Install($this, [
        'activation_id' => 'act-early', 'issued_at' => now('UTC')->toIso8601ZuluString(),
        'starts_at' => $start->toIso8601ZuluString(), 'ends_at' => $start->addDays(365)->toIso8601ZuluString(),
    ], 'early');

    $license = app(CurrentLicense::class);
    expect($license->refresh()->status)->toBe(LicenseStatus::Provisioned)
        ->and($license->acceptsNewWork())->toBeFalse();

    // On the start day the term runs from that start: 365 days, none consumed before it.
    $this->travelTo($start);
    expect($license->refresh()->status)->toBe(LicenseStatus::Active)->and($license->daysRemaining())->toBe(365);
});
