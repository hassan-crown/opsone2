<?php

use App\Access\Access;
use App\Access\Enums\DenyReason;
use App\Access\Exceptions\LicenseLimitReached;
use App\Actions\Organization\CreateBranch;
use App\Actions\StateMachines\M01Invitation\T00CreateInvitation;
use App\Actions\StateMachines\M01Invitation\T03CancelInvitation;
use App\Actions\StateMachines\M22Membership\T01DisableMembership;
use App\Actions\StateMachines\M22Membership\T02ReactivateMembership;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationUnitStatus;
use App\Licensing\LicenseStatus;
use App\Models\Branch;
use App\Models\Invitation;
use App\Models\StateTransition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
});

/**
 * A license whose term ended the given number of days ago (grace 7, wind-down 30).
 *
 * @return array<string, string>
 */
function endedDaysAgo(int $days): array
{
    $now = CarbonImmutable::now('UTC');

    return [
        'starts_at' => $now->subDays($days + 365)->toIso8601ZuluString(),
        'ends_at' => $now->subDays($days)->toIso8601ZuluString(),
        // Newer than the license Org::make() installed, so it is not refused as superseded.
        'issued_at' => $now->toIso8601ZuluString(),
    ];
}

function invite(User $actor, string $email): Invitation
{
    return app(T00CreateInvitation::class)->handle($actor, [
        'email' => $email,
        'roles' => [['role_template_id' => Org::templateId('sales')]],
    ])[0];
}

describe('license step of the Gate', function () {
    it('denies a permission whose capability the license lacks, even to the owner', function () {
        LicenseFactory::install(['capabilities' => ['ready_goods']]);

        expect(Access::inspect($this->org->owner, 'P-61')->reason)->toBe(DenyReason::License)
            ->and(Access::can($this->org->owner, 'P-12'))->toBeTrue()
            ->and(Access::can($this->org->owner, 'P-60'))->toBeTrue();
    });

    it('maps aggregated comparisons to the branch_comparisons capability', function () {
        LicenseFactory::install(['capabilities' => ['branch_comparisons']]);

        expect(Access::can($this->org->owner, 'P-61'))->toBeTrue()
            ->and(Access::inspect($this->org->owner, 'P-12')->reason)->toBe(DenyReason::License);
    });

    it('leaves only reading, export and protective actions once the license expired', function () {
        $license = LicenseFactory::install(endedDaysAgo(60));
        expect($license->status())->toBe(LicenseStatus::Expired);

        $manager = $this->org->member('manager', [$this->org->a]);

        expect(Access::inspect($this->org->owner, 'P-11')->reason)->toBe(DenyReason::LicenseReadOnly)
            ->and(Access::inspect($this->org->owner, 'P-05')->reason)->toBe(DenyReason::LicenseReadOnly)
            ->and(Access::inspect($this->org->owner, 'P-12')->reason)->toBe(DenyReason::License)
            ->and(Access::inspect($this->org->owner, 'P-61')->reason)->toBe(DenyReason::License)
            ->and(Access::can($this->org->owner, 'P-60'))->toBeTrue()
            ->and(Access::can($this->org->owner, 'P-62'))->toBeTrue()
            ->and(Access::can($this->org->owner, 'P-06', Org::membershipOf($manager)))->toBeTrue()
            ->and(Access::can($this->org->owner, 'P-84'))->toBeTrue();
    });

    it('stops new users and new orders during wind-down but lets existing work continue', function () {
        $license = LicenseFactory::install(endedDaysAgo(10));
        expect($license->status())->toBe(LicenseStatus::WindDown);

        expect(Access::inspect($this->org->owner, 'P-05')->reason)->toBe(DenyReason::LicenseNoNewWork)
            ->and(Access::inspect($this->org->owner, 'P-15')->reason)->toBe(DenyReason::LicenseNoNewWork)
            ->and(Access::inspect($this->org->owner, 'P-92')->reason)->toBe(DenyReason::LicenseNoNewWork)
            ->and(Access::can($this->org->owner, 'P-25'))->toBeTrue()
            ->and(Access::can($this->org->owner, 'P-12'))->toBeTrue();
    });

    it('keeps everything open during grace', function () {
        expect(LicenseFactory::install(endedDaysAgo(3))->status())->toBe(LicenseStatus::Grace)
            ->and(Access::can($this->org->owner, 'P-05'))->toBeTrue();
    });

    it('denies everything unproven when the license needs attention with no trusted license, but login works', function () {
        DB::table('license_states')->delete();
        Cache::flush();
        $license = LicenseFactory::placeRaw('{"format": "not-a-license"}');
        app()->forgetScopedInstances();

        expect($license->status())->toBe(LicenseStatus::NeedsAttention)
            ->and(Access::inspect($this->org->owner, 'P-12')->reason)->toBe(DenyReason::License)
            ->and(Access::inspect($this->org->owner, 'P-11')->reason)->toBe(DenyReason::LicenseReadOnly)
            ->and(Access::can($this->org->owner, 'P-60'))->toBeTrue()
            ->and(Access::can($this->org->owner, 'P-84'))->toBeTrue();

        $this->post(route('login.store'), ['email' => $this->org->owner->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($this->org->owner);
    });

    it('applies only the last trusted license when the installed one needs attention', function () {
        LicenseFactory::install(['capabilities' => []]);
        $license = LicenseFactory::placeRaw('{"format": "tampered"}');
        app()->forgetScopedInstances();

        expect($license->status())->toBe(LicenseStatus::NeedsAttention)
            ->and(Access::inspect($this->org->owner, 'P-12')->reason)->toBe(DenyReason::License)
            ->and(Access::can($this->org->owner, 'P-11'))->toBeTrue();
    });
});

describe('seat limit', function () {
    beforeEach(function () {
        LicenseFactory::install(['limits' => ['seats' => 3, 'branches' => 3, 'storage_mb' => 1024]]);
        // Seats: the owner and this member; one left.
        $this->member = $this->org->member('sales', [$this->org->a]);
    });

    it('counts a pending invitation and refuses one beyond the limit without side effects', function () {
        invite($this->org->owner, 'first@example.com');
        $transitions = StateTransition::query()->count();

        expect(fn () => invite($this->org->owner, 'second@example.com'))
            ->toThrow(fn (LicenseLimitReached $e) => expect([$e->resource, $e->used, $e->limit])->toBe(['seats', 3, 3]));

        expect(Invitation::query()->where('email', 'second@example.com')->exists())->toBeFalse()
            ->and(StateTransition::query()->count())->toBe($transitions);
    });

    it('frees the seat when an invitation is cancelled or has expired', function () {
        $first = invite($this->org->owner, 'first@example.com');
        app(T03CancelInvitation::class)->handle($this->org->owner, $first);

        $second = invite($this->org->owner, 'second@example.com');
        $this->travel(Invitation::VALID_DAYS)->days();
        $this->travel(1)->minutes();

        expect(invite($this->org->owner, 'third@example.com')->id)->toBeGreaterThan($second->id);
    });

    it('frees the seat of a disabled member and refuses to reactivate them when it was taken', function () {
        $membership = Org::membershipOf($this->member);
        app(T01DisableMembership::class)->handle($this->org->owner, $membership, 'left');
        invite($this->org->owner, 'first@example.com');
        invite($this->org->owner, 'second@example.com');

        expect(fn () => app(T02ReactivateMembership::class)->handle($this->org->owner, $membership))
            ->toThrow(LicenseLimitReached::class);
        expect($membership->refresh()->status)->toBe(MembershipStatus::Disabled);
    });

    it('refuses any new seat when no limit is proven during wind-down', function () {
        $membership = Org::membershipOf($this->member);
        app(T01DisableMembership::class)->handle($this->org->owner, $membership, 'left');
        LicenseFactory::install([...endedDaysAgo(10), 'limits' => ['seats' => 10, 'branches' => 3, 'storage_mb' => 1024]]);

        expect(fn () => app(T02ReactivateMembership::class)->handle($this->org->owner, $membership))
            ->toThrow(fn (LicenseLimitReached $e) => expect($e->limit)->toBeNull());
    });

    it('explains the limit in Arabic and English', function () {
        $error = new LicenseLimitReached('seats', 3, 3);

        app()->setLocale('ar');
        expect($error->userMessage())->toContain('مقاعد')->toContain('3 من 3');
        app()->setLocale('en');
        expect($error->userMessage())->toContain('seats')->toContain('3 of 3');
        expect((new LicenseLimitReached('branches', 1, null))->userMessage())->toContain('does not allow');
    });
});

describe('branch limit', function () {
    it('refuses an active branch beyond the limit and allows one after disabling a branch', function () {
        // The test organization already has three active branches (growth plan: 3).
        expect(fn () => app(CreateBranch::class)->handle($this->org->owner, ['name_ar' => 'فرع رابع']))
            ->toThrow(fn (LicenseLimitReached $e) => expect([$e->resource, $e->used, $e->limit])->toBe(['branches', 3, 3]));
        expect(Branch::query()->count())->toBe(3);

        $this->org->c->forceFill(['status' => OrganizationUnitStatus::Disabled])->save();

        $branch = app(CreateBranch::class)->handle($this->org->owner, ['name_ar' => 'فرع رابع', 'region_id' => $this->org->south->id]);
        expect($branch->status)->toBe(OrganizationUnitStatus::Active)
            ->and(Branch::query()->where('status', 'active')->count())->toBe(3);
    });

    it('lets only a facility-wide grant create a branch', function () {
        LicenseFactory::install(['limits' => ['seats' => 15, 'branches' => 10, 'storage_mb' => 1024]]);
        $manager = $this->org->member('manager', [$this->org->a]);

        expect(fn () => app(CreateBranch::class)->handle($manager, ['name_ar' => 'فرع', 'region_id' => $this->org->north->id]))
            ->toThrow(AuthorizationException::class);
        expect(app(CreateBranch::class)->handle($this->org->owner, ['name_ar' => 'فرع'])->exists)->toBeTrue();
    });
});
