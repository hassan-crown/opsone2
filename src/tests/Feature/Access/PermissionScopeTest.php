<?php

/**
 * Matrix rule «اختبار كل انتقال ثلاث حالات»: authorized in scope, authorized out of scope, unauthorized —
 * the last two are rejected without any partial effect. Covers P-01..P-08 and P-84.
 */

use App\Access\Access;
use App\Access\Enums\DenyReason;
use App\Access\Exceptions\InvalidTransition;
use App\Actions\Organization\UpdateBranch;
use App\Actions\Organization\UpdateFacilityProfile;
use App\Actions\StateMachines\M01Invitation\T00CreateInvitation;
use App\Actions\StateMachines\M22Membership\T01DisableMembership;
use App\Actions\StateMachines\M22Membership\T03UpdateMemberAccess;
use App\Actions\StateMachines\M37OwnershipTransfer\T00RequestOwnershipTransfer;
use App\Enums\MembershipStatus;
use App\Models\Facility;
use App\Models\Invitation;
use App\Models\MemberGrant;
use App\Models\Membership;
use App\Models\OwnershipTransfer;
use App\Models\StateTransition;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    // Manager over branch A only; sales in branch A (no organization permissions).
    $this->manager = $this->org->member('manager', [$this->org->a]);
    $this->sales = $this->org->member('sales', [$this->org->a]);
});

/**
 * Grants a raw override directly (bypassing the delegation rules) to build an "authorized, wrong scope" member.
 */
function grantOverride(User $user, string $permission, string $scope, ?array $targets = null): void
{
    MemberGrant::query()->create([
        'membership_id' => Org::membershipOf($user)->id,
        'permission' => $permission,
        'effect' => 'allow',
        'scope' => $scope,
        'targets' => $targets,
    ]);
}

describe('P-01 view facility data', function () {
    it('allows a manager', fn () => expect(Access::can($this->manager, 'P-01', $this->org->facility))->toBeTrue());

    it('ignores a grant carrying a scope the action does not allow', function () {
        $member = $this->org->member(null, [$this->org->a]);
        grantOverride($member, 'P-01', 'branches', [$this->org->a->id]);

        expect(Access::inspect($member, 'P-01', $this->org->facility)->reason)->toBe(DenyReason::NoGrant);
    });

    it('denies sales', fn () => expect(Access::inspect($this->sales, 'P-01', $this->org->facility)->reason)->toBe(DenyReason::NoGrant));
});

describe('P-02 edit facility profile', function () {
    it('lets a manager edit the profile', function () {
        app(UpdateFacilityProfile::class)->handle($this->manager, ['name_ar' => 'اسم جديد']);

        expect(Facility::currentOrFail()->name_ar)->toBe('اسم جديد');
    });

    it('rejects an out-of-scope grant without changes', function () {
        $member = $this->org->member(null, [$this->org->a]);
        grantOverride($member, 'P-02', 'branches', [$this->org->a->id]);

        expect(fn () => app(UpdateFacilityProfile::class)->handle($member, ['name_ar' => 'x']))->toThrow(AuthorizationException::class);
        expect(Facility::currentOrFail()->name_ar)->toBe('منشأة الاختبار');
    });

    it('rejects sales without changes', function () {
        expect(fn () => app(UpdateFacilityProfile::class)->handle($this->sales, ['name_ar' => 'x']))->toThrow(AuthorizationException::class);
        expect(Facility::currentOrFail()->name_ar)->toBe('منشأة الاختبار');
    });

    it('keeps the currency locked after the first financial movement', function () {
        Facility::query()->update(['currency_locked_at' => now()]);

        expect(fn () => app(UpdateFacilityProfile::class)->handle($this->org->owner, ['currency' => 'USD']))
            ->toThrow(ValidationException::class);
        expect(Facility::currentOrFail()->currency)->toBe('SAR');
    });
});

describe('P-03 manage branches', function () {
    it('allows editing a branch in scope', function () {
        app(UpdateBranch::class)->handle($this->manager, $this->org->a, ['name_ar' => 'فرع أ']);

        expect($this->org->a->refresh()->name_ar)->toBe('فرع أ');
    });

    it('rejects a branch outside the manager scope without changes', function () {
        $before = $this->org->c->name_ar;

        expect(fn () => app(UpdateBranch::class)->handle($this->manager, $this->org->c, ['name_ar' => 'x']))->toThrow(AuthorizationException::class);
        expect($this->org->c->refresh()->name_ar)->toBe($before);
        expect(Access::inspect($this->manager, 'P-03', $this->org->c)->reason)->toBe(DenyReason::OutOfScope);
    });

    it('rejects sales without changes', function () {
        $before = $this->org->a->name_ar;

        expect(fn () => app(UpdateBranch::class)->handle($this->sales, $this->org->a, ['name_ar' => 'x']))->toThrow(AuthorizationException::class);
        expect($this->org->a->refresh()->name_ar)->toBe($before);
    });

    it('resolves region scope through the branch region', function () {
        $regional = $this->org->member('manager', [$this->org->a], scope: 'region');

        expect(Access::can($regional, 'P-03', $this->org->b))->toBeTrue()
            ->and(Access::can($regional, 'P-03', $this->org->c))->toBeFalse();
    });
});

describe('P-04 manage roles and grants', function () {
    it('lets a manager change the access of a member in scope', function () {
        $target = $this->org->member('sales', [$this->org->a]);

        app(T03UpdateMemberAccess::class)->handle($this->manager, Org::membershipOf($target), [
            ['role_template_id' => Org::templateId('executor')],
        ]);

        expect(Org::membershipOf($target)->roles()->pluck('role_template_id')->all())->toBe([Org::templateId('executor')]);
    });

    it('rejects a member outside scope without changes', function () {
        $target = $this->org->member('sales', [$this->org->c]);
        $log = StateTransition::query()->count();

        expect(fn () => app(T03UpdateMemberAccess::class)->handle($this->manager, Org::membershipOf($target), [
            ['role_template_id' => Org::templateId('executor')],
        ]))->toThrow(AuthorizationException::class);

        expect(Org::membershipOf($target)->roles()->pluck('role_template_id')->all())->toBe([Org::templateId('sales')])
            ->and(StateTransition::query()->count())->toBe($log);
    });

    it('rejects granting a wider scope than the granter holds, without changes', function () {
        $target = $this->org->member('sales', [$this->org->a]);

        expect(fn () => app(T03UpdateMemberAccess::class)->handle($this->manager, Org::membershipOf($target), [
            ['role_template_id' => Org::templateId('sales'), 'scope' => 'tenant'],
        ]))->toThrow(AuthorizationException::class);

        expect(Org::membershipOf($target)->roles()->first()?->scope)->toBeNull();
    });

    it('rejects sales without changes', function () {
        $target = $this->org->member('executor', [$this->org->a]);

        expect(fn () => app(T03UpdateMemberAccess::class)->handle($this->sales, Org::membershipOf($target), [
            ['role_template_id' => Org::templateId('manager')],
        ]))->toThrow(AuthorizationException::class);

        expect(Org::membershipOf($target)->roles()->pluck('role_template_id')->all())->toBe([Org::templateId('executor')]);
    });

    it('never edits the owner access', function () {
        expect(fn () => app(T03UpdateMemberAccess::class)->handle($this->org->owner, Org::membershipOf($this->org->owner), []))
            ->toThrow(InvalidTransition::class);
    });
});

describe('P-05 invite staff', function () {
    it('lets a manager invite into a branch in scope', function () {
        [$invitation, $token] = app(T00CreateInvitation::class)->handle($this->manager, [
            'email' => 'New@Example.com',
            'branch_ids' => [$this->org->a->id],
            'roles' => [['role_template_id' => Org::templateId('sales')]],
        ]);

        expect($invitation->email)->toBe('new@example.com')
            ->and($invitation->token_hash)->toBe(hash('sha256', $token))
            ->and($invitation->token_hash)->not->toBe($token)
            ->and(StateTransition::query()->where('machine', 'M-01')->where('transition', 'T00')->count())->toBe(1);
    });

    it('rejects inviting into a branch outside scope without a record', function () {
        expect(fn () => app(T00CreateInvitation::class)->handle($this->manager, [
            'email' => 'x@example.com',
            'branch_ids' => [$this->org->c->id],
            'roles' => [['role_template_id' => Org::templateId('sales')]],
        ]))->toThrow(AuthorizationException::class);

        expect(Invitation::query()->count())->toBe(0);
    });

    it('rejects a role the inviter cannot grant without a record', function () {
        expect(fn () => app(T00CreateInvitation::class)->handle($this->manager, [
            'email' => 'x@example.com',
            'branch_ids' => [$this->org->a->id],
            'roles' => [['role_template_id' => Org::templateId('finance')]],
        ]))->toThrow(AuthorizationException::class);

        expect(Invitation::query()->count())->toBe(0);
    });

    it('rejects sales without a record', function () {
        expect(fn () => app(T00CreateInvitation::class)->handle($this->sales, [
            'email' => 'x@example.com',
            'branch_ids' => [$this->org->a->id],
            'roles' => [['role_template_id' => Org::templateId('sales')]],
        ]))->toThrow(AuthorizationException::class);

        expect(Invitation::query()->count())->toBe(0);
    });
});

describe('P-06 disable a member', function () {
    it('lets a manager disable a member in scope', function () {
        $target = $this->org->member('sales', [$this->org->a]);

        app(T01DisableMembership::class)->handle($this->manager, Org::membershipOf($target), 'left the company');

        expect(Org::membershipOf($target)->status)->toBe(MembershipStatus::Disabled);
    });

    it('rejects a member outside scope without changes', function () {
        $target = $this->org->member('sales', [$this->org->c]);
        $version = $target->refresh()->access_version;

        expect(fn () => app(T01DisableMembership::class)->handle($this->manager, Org::membershipOf($target), 'x'))
            ->toThrow(AuthorizationException::class);

        expect(Org::membershipOf($target)->status)->toBe(MembershipStatus::Active)
            ->and($target->refresh()->access_version)->toBe($version);
    });

    it('rejects sales without changes', function () {
        $target = $this->org->member('executor', [$this->org->a]);

        expect(fn () => app(T01DisableMembership::class)->handle($this->sales, Org::membershipOf($target), 'x'))
            ->toThrow(AuthorizationException::class);

        expect(Org::membershipOf($target)->status)->toBe(MembershipStatus::Active);
    });

    it('never disables the owner', function () {
        expect(fn () => app(T01DisableMembership::class)->handle($this->org->owner, Org::membershipOf($this->org->owner), 'x'))
            ->toThrow(InvalidTransition::class);

        expect(Org::membershipOf($this->org->owner)->status)->toBe(MembershipStatus::Active);
    });
});

describe('P-07 transfer ownership', function () {
    it('lets the owner request a transfer after re-verifying the password', function () {
        app(T00RequestOwnershipTransfer::class)->handle($this->org->owner, 'password', Org::membershipOf($this->manager));

        expect(OwnershipTransfer::query()->count())->toBe(1);
    });

    it('ignores P-07 granted to anyone but the owner, without a record', function () {
        grantOverride($this->manager, 'P-07', 'tenant');

        expect(fn () => app(T00RequestOwnershipTransfer::class)->handle($this->manager, 'password', Org::membershipOf($this->sales)))
            ->toThrow(AuthorizationException::class);
        expect(OwnershipTransfer::query()->count())->toBe(0);
    });

    it('rejects a wrong password without a record', function () {
        expect(fn () => app(T00RequestOwnershipTransfer::class)->handle($this->org->owner, 'wrong', Org::membershipOf($this->manager)))
            ->toThrow(AuthorizationException::class);
        expect(OwnershipTransfer::query()->count())->toBe(0);
    });

    it('denies a manager', function () {
        expect(fn () => app(T00RequestOwnershipTransfer::class)->handle($this->manager, 'password', Org::membershipOf($this->sales)))
            ->toThrow(AuthorizationException::class);
        expect(OwnershipTransfer::query()->count())->toBe(0);
    });
});

describe('P-08 view eligible team members', function () {
    it('lets a supervisor see members of their department', function () {
        $supervisor = $this->org->member('supervisor', [$this->org->a], $this->org->d1);
        $peer = $this->org->member('executor', [$this->org->a], $this->org->d1);

        expect(Access::can($supervisor, 'P-08', Org::membershipOf($peer)))->toBeTrue();
    });

    it('denies a member of another department', function () {
        $supervisor = $this->org->member('supervisor', [$this->org->a], $this->org->d1);
        $other = $this->org->member('executor', [$this->org->c], $this->org->d2);

        expect(Access::inspect($supervisor, 'P-08', Org::membershipOf($other))->reason)->toBe(DenyReason::OutOfScope);
    });

    it('denies sales', function () {
        $peer = $this->org->member('executor', [$this->org->a]);

        expect(Access::inspect($this->sales, 'P-08', Org::membershipOf($peer))->reason)->toBe(DenyReason::NoGrant);
    });
});

describe('P-84 my own account', function () {
    it('allows any signed-in account on itself', function () {
        expect(Access::can($this->sales, 'P-84', $this->sales))->toBeTrue()
            ->and(Access::can(User::factory()->create(), 'P-84'))->toBeTrue();
    });

    it('denies acting on another account', function () {
        expect(Access::inspect($this->sales, 'P-84', $this->manager)->reason)->toBe(DenyReason::OutOfScope);
    });

    it('denies a disabled member and a guest', function () {
        Membership::query()->where('user_id', $this->sales->id)->update(['status' => 'disabled']);

        expect(Access::inspect($this->sales, 'P-84', $this->sales)->reason)->toBe(DenyReason::InactiveAccount)
            ->and(Access::inspect(null, 'P-84')->reason)->toBe(DenyReason::Audience);
    });
});
