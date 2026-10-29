<?php

/**
 * Integrated acceptance «جهة جديدة» (SRS «قبول النظام المتكامل»): subscription request, confirmation, provisioning,
 * invitation and onboarding without duplicate data or exposing another space.
 *
 * Requirements: SRS-SUB-04 (supervised provisioning, safe owner invitation), SRS-SUB-05 (isolated deployment per
 * facility), SRS-SUB-06 / SRS-SUB-20 (signed license; a provisioning license does not start the service),
 * SRS-SUB-21 (delivery and secure access invitation), SRS-ORG-04 (seven-day single-use staff invitation),
 * SRS-ORG-05 (ownership proof before access), SRS-ORG-08 (resumable setup wizard, completion once),
 * SRS-ORG-09 (license limits applied).
 *
 * Scope: the subscription purchase and provisioning order live in the central app (ADR-0003) and are out of scope.
 * This file starts at the deployment side of provisioning: seeding the facility profile, installing the signed
 * license (`license:install`), inviting the first owner (`org:invite-owner`), then every onboarding step over HTTP
 * as the right actor (owner accepts with the emailed code, runs the setup wizard, invites staff; staff accept).
 * Single-tenant code (ADR-0002): "another space" means a license for another domain/deployment is refused, and
 * unknown ids/tokens answer 404 / invalid without revealing anything.
 */

use App\Enums\SetupStatus;
use App\Licensing\CurrentLicense;
use App\Licensing\LicenseStatus;
use App\Mail\OrgMessage;
use App\Models\Branch;
use App\Models\Facility;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\OwnerInvitation;
use App\Models\RoleTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\RoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LicenseFactory;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27T09:00:00Z'));
    // A fresh deployment: no license file yet, this deployment's id is pinned (ADR-0004 §2).
    $this->licenseDir = storage_path('framework/testing/acc-i01-'.getmypid().'-'.bin2hex(random_bytes(4)));
    @mkdir($this->licenseDir, 0755, true);
    config(['license.path' => $this->licenseDir.'/license.json', 'license.deployment_id' => 'deployment-1']);
    app()->forgetScopedInstances();
    Mail::fake();
});

afterEach(function () {
    array_map(unlink(...), glob($this->licenseDir.'/*') ?: []);
    @rmdir($this->licenseDir);
});

it('provisions, delivers and onboards a new facility once, without duplicates or a foreign license', function () {
    // 1. Provisioning seeds the facility profile and role templates; re-running provisioning duplicates nothing.
    $this->seed([FacilitySeeder::class, RoleTemplateSeeder::class]);
    $templates = RoleTemplate::query()->count();
    $this->seed([FacilitySeeder::class, RoleTemplateSeeder::class]);
    expect(Facility::query()->count())->toBe(1)
        ->and(RoleTemplate::query()->count())->toBe($templates)
        ->and(Facility::current()->setup_status)->toBe(SetupStatus::Incomplete);

    // 2. A license issued for another facility's domain or deployment is refused; nothing is installed.
    $foreignDomain = accI01LicenseFile($this->licenseDir, 'foreign-domain', ['domains' => ['other-facility.opsone.sa']]);
    $this->artisan('license:install', ['path' => $foreignDomain])->expectsOutputToContain('domain_mismatch')->assertFailed();
    $foreignDeployment = accI01LicenseFile($this->licenseDir, 'foreign-deployment', ['deployment_id' => 'deployment-2', 'facility_id' => 'facility-2']);
    $this->artisan('license:install', ['path' => $foreignDeployment])->expectsOutputToContain('deployment_mismatch')->assertFailed();
    expect(file_exists((string) config('license.path')))->toBeFalse()
        ->and(DB::table('audit_events')->where('action', 'license.installed')->count())->toBe(0);

    // 3. The provisioning license (not activated) installs but does not start the service (SUB-20).
    $provisioning = accI01LicenseFile($this->licenseDir, 'provisioning', [
        'activation_id' => null, 'starts_at' => null, 'ends_at' => null,
        'issued_at' => now()->subDays(2)->toIso8601ZuluString(),
    ]);
    $this->artisan('license:install', ['path' => $provisioning])->expectsOutputToContain('Status: provisioned')->assertSuccessful();
    expect(app(CurrentLicense::class)->refresh()->status)->toBe(LicenseStatus::Provisioned)
        ->and(app(CurrentLicense::class)->acceptsNewWork())->toBeFalse();

    // 4. Delivery: the activated license for this facility and deployment replaces it; an older one cannot come back.
    $activation = accI01LicenseFile($this->licenseDir, 'activation', ['issued_at' => now()->subDay()->toIso8601ZuluString()]);
    $this->artisan('license:install', ['path' => $activation])->expectsOutputToContain('Status: active')->assertSuccessful();
    $this->artisan('license:install', ['path' => $provisioning])->expectsOutputToContain('superseded')->assertFailed();
    $this->artisan('license:install', ['path' => $foreignDeployment])->expectsOutputToContain('deployment_mismatch')->assertFailed();
    app()->forgetScopedInstances();
    expect(file_get_contents((string) config('license.path')))->toBe(file_get_contents($activation))
        ->and(app(CurrentLicense::class)->refresh()->status)->toBe(LicenseStatus::Active)
        ->and(DB::table('audit_events')->where('action', 'license.installed')->count())->toBe(2);

    // 5. Safe owner invitation (SUB-04/21): one emailed single-use link; ownership is proven with the emailed code.
    $this->artisan('org:invite-owner', ['email' => 'owner@new-facility.test', '--name' => 'Fahad'])->assertSuccessful();
    $ownerToken = accI01InviteToken('owner@new-facility.test');
    $this->get(route('org.owner-invite.show', $ownerToken))
        ->assertInertia(fn (Assert $p) => $p->component('auth/owner-invite')->where('state', 'valid'));
    // Without the code nothing is created (ORG-05).
    $this->post(route('org.owner-invite.accept', $ownerToken), accI01AcceptBody('000000', 'Fahad'))->assertSessionHasErrors('code');
    expect(User::query()->count())->toBe(0);

    $this->post(route('org.owner-invite.code', $ownerToken))->assertRedirect();
    $ownerBody = accI01AcceptBody(accI01ProofCode('owner@new-facility.test'), 'Fahad');
    $this->post(route('org.owner-invite.accept', $ownerToken), $ownerBody)->assertRedirect(route('org.setup'));
    $owner = User::query()->where('email', 'owner@new-facility.test')->sole();
    $this->assertAuthenticatedAs($owner);
    expect(Facility::current()->owner_membership_id)->toBe(Membership::query()->where('user_id', $owner->id)->value('id'));

    // The link is single use, and provisioning cannot establish a second owner.
    Auth::logout();
    $this->get(route('org.owner-invite.show', $ownerToken))->assertInertia(fn (Assert $p) => $p->where('state', 'used'));
    $this->post(route('org.owner-invite.accept', $ownerToken), $ownerBody)->assertSessionHasErrors('token');
    $this->artisan('org:invite-owner', ['email' => 'second-owner@new-facility.test'])->assertFailed();
    expect(User::query()->count())->toBe(1)
        ->and(Membership::query()->count())->toBe(1)
        ->and(OwnerInvitation::query()->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'owner.established')->count())->toBe(1);

    // 6. Setup wizard (ORG-08): resumes at the first step not done; saving twice keeps one facility.
    $this->actingAs($owner)->get(route('org.setup'))->assertRedirect(route('org.setup.step', 'org'));
    $profile = ['name_ar' => 'مطابخ الواحة', 'name_en' => 'Oasis Kitchens', 'default_locale' => 'ar', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh'];
    $this->put(route('org.setup.org'), $profile)->assertRedirect(route('org.setup.step', 'structure'));
    $this->put(route('org.setup.org'), $profile)->assertRedirect(route('org.setup.step', 'structure'));
    expect(Facility::query()->count())->toBe(1)
        ->and(Facility::current()->name_en)->toBe('Oasis Kitchens');

    // First branch from the wizard; a repeated submit does not add a second one.
    $this->post(route('org.branches.store'), ['name_ar' => 'الفرع الرئيسي', 'code' => 'MAIN', 'from' => 'wizard'])->assertRedirect();
    $this->post(route('org.branches.store'), ['name_ar' => 'الفرع الرئيسي', 'code' => 'MAIN', 'from' => 'wizard'])->assertSessionHasErrors('code');
    $branch = Branch::query()->sole();
    $this->post(route('org.setup.next', 'structure'))->assertRedirect(route('org.setup.step', 'flow'));
    $this->post(route('org.setup.next', 'flow'));

    // Staff invitation from the wizard: a seat is held; a repeated invite of the same email is refused.
    $invite = [
        'name' => 'Sara', 'email' => 'sara@new-facility.test', 'branch_ids' => [$branch->id],
        'roles' => [['role_template_id' => (int) RoleTemplate::query()->where('key', 'sales')->value('id')]], 'from' => 'wizard',
    ];
    $this->post(route('org.team.store'), $invite)->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('org.team.store'), $invite)->assertSessionHasErrors('email');
    expect(Invitation::query()->count())->toBe(1);
    $this->get(route('org.setup.step', 'team'))
        ->assertInertia(fn (Assert $p) => $p->component('org/setup/team')->has('invitations', 1)->where('seats.used', 2));
    $this->post(route('org.setup.next', 'team'))->assertRedirect(route('org.setup.step', 'review'));

    // Completion happens once; a second attempt changes nothing and the wizard stays closed.
    $this->post(route('org.setup.complete'))->assertRedirect(route('dashboard'));
    $this->post(route('org.setup.complete'))->assertSessionHasErrors('setup');
    expect(Facility::current()->setup_status)->toBe(SetupStatus::Completed)
        ->and(DB::table('audit_events')->where('action', 'settings.setup_completed')->count())->toBe(1);
    $this->get(route('org.setup.step', 'org'))->assertRedirect(route('dashboard'));

    // 7. Staff accept with the emailed code (ORG-04/05): signed in once, the link is then used.
    Auth::logout();
    $staffToken = accI01InviteToken('sara@new-facility.test');
    $this->get(route('org.invite.show', $staffToken))
        ->assertInertia(fn (Assert $p) => $p->component('auth/invite-accept')->where('state', 'valid'));
    $this->post(route('org.invite.code', $staffToken))->assertRedirect();
    $staffBody = accI01AcceptBody(accI01ProofCode('sara@new-facility.test'), 'Sara');
    $this->post(route('org.invite.accept', $staffToken), $staffBody)->assertRedirect(route('dashboard'));
    $sara = User::query()->where('email', 'sara@new-facility.test')->sole();
    $this->assertAuthenticatedAs($sara);

    Auth::logout();
    $this->post(route('org.invite.accept', $staffToken), $staffBody)->assertSessionHasErrors('token');
    $this->get(route('org.invite.show', $staffToken))->assertInertia(fn (Assert $p) => $p->where('state', 'used'));
    expect(User::query()->count())->toBe(2)
        ->and(Membership::query()->count())->toBe(2)
        ->and(Invitation::query()->sole()->status->value)->toBe('accepted')
        ->and(DB::table('audit_events')->where('action', 'invitation.accepted')->count())->toBe(1)
        ->and(Facility::query()->count())->toBe(1)
        ->and(Branch::query()->count())->toBe(1);

    // 8. Nothing outside this space: unknown tokens are invalid, unknown ids 404, staff cannot reach owner setup.
    $this->get(route('org.invite.show', str_repeat('x', 64)))->assertInertia(fn (Assert $p) => $p->where('state', 'invalid'));
    $this->get(route('org.owner-invite.show', str_repeat('y', 64)))->assertInertia(fn (Assert $p) => $p->where('state', 'invalid'));
    $this->actingAs($owner)->get(route('org.team.member', 999999))->assertNotFound();
    $this->actingAs($owner)->post(route('org.team.renew', 999999))->assertNotFound();
    $this->actingAs($sara)->put(route('org.setup.org'), $profile)->assertForbidden();
    $this->actingAs($sara)->post(route('org.team.store'), [...$invite, 'email' => 'x@new-facility.test'])->assertForbidden();
    expect(Invitation::query()->count())->toBe(1);
});

it('does not start the service on a provisioning license: no staff invitation before activation', function () {
    $this->seed([FacilitySeeder::class, RoleTemplateSeeder::class]);
    $provisioning = accI01LicenseFile($this->licenseDir, 'provisioning', ['activation_id' => null, 'starts_at' => null, 'ends_at' => null]);
    $this->artisan('license:install', ['path' => $provisioning])->assertSuccessful();

    $this->artisan('org:invite-owner', ['email' => 'owner@new-facility.test'])->assertSuccessful();
    $token = accI01InviteToken('owner@new-facility.test');
    $this->post(route('org.owner-invite.code', $token))->assertRedirect();
    $this->post(route('org.owner-invite.accept', $token), accI01AcceptBody(accI01ProofCode('owner@new-facility.test'), 'Owner'));
    $owner = User::query()->where('email', 'owner@new-facility.test')->first();
    expect($owner)->not->toBeNull();

    $this->actingAs($owner)->post(route('org.team.store'), [
        'name' => 'Early', 'email' => 'early@new-facility.test',
        'roles' => [['role_template_id' => (int) RoleTemplate::query()->where('key', 'sales')->value('id')]],
    ])->assertForbidden();
    $this->actingAs($owner)->post(route('org.branches.store'), ['name_ar' => 'فرع مبكر'])->assertForbidden();
    expect(Invitation::query()->count())->toBe(0)
        ->and(Branch::query()->count())->toBe(0);
});

/**
 * Writes a license file signed with the development key for this test and returns its path.
 *
 * @param  array<string, mixed>  $overrides
 */
function accI01LicenseFile(string $dir, string $name, array $overrides = []): string
{
    $path = $dir.'/'.$name.'.json';
    file_put_contents($path, LicenseFactory::signed($overrides));

    return $path;
}

/**
 * The single-use link token from the latest invitation email to this address (mail faked; the outbox clears its
 * payload once sent).
 */
function accI01InviteToken(string $email): string
{
    $url = (string) (accI01LastMail($email, fn (OrgMessage $m) => isset($m->action['url']) && str_contains($m->action['url'], 'invit'))->action['url'] ?? '');

    return basename((string) parse_url($url, PHP_URL_PATH));
}

/**
 * The six-digit ownership code from the latest proof email to this address.
 */
function accI01ProofCode(string $email): string
{
    $mail = accI01LastMail($email, fn (OrgMessage $m) => preg_match('/\b\d{6}\b/', implode(' ', $m->lines)) === 1);
    preg_match('/\b(\d{6})\b/', implode(' ', $mail->lines), $m);

    return (string) ($m[1] ?? '');
}

function accI01LastMail(string $email, Closure $filter): OrgMessage
{
    $mail = Mail::sent(OrgMessage::class, fn (OrgMessage $m) => $m->hasTo($email) && $filter($m))->last();
    expect($mail)->toBeInstanceOf(OrgMessage::class);

    return $mail;
}

/**
 * @return array<string, string>
 */
function accI01AcceptBody(string $code, string $name): array
{
    return ['code' => $code, 'name' => $name, 'password' => 'a-long-secret-pass-1', 'password_confirmation' => 'a-long-secret-pass-1'];
}
