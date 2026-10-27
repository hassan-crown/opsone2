<?php

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M38SupportAccess\T00RequestSupportAccess;
use App\Actions\StateMachines\M38SupportAccess\T01GrantSupportAccess;
use App\Actions\StateMachines\M38SupportAccess\T02RejectSupportAccess;
use App\Actions\StateMachines\M38SupportAccess\T03RevokeSupportAccess;
use App\Actions\StateMachines\M38SupportAccess\T04ExpireSupportAccess;
use App\Enums\SupportGrantStatus;
use App\Models\AuditEvent;
use App\Models\MemberGrant;
use App\Models\StateTransition;
use App\Models\SupportGrant;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->support = User::factory()->create(['email' => 'agent@opsone.test']);
    $this->support->forceFill(['is_support' => true])->save();
});

function requestAccess(User $support, array $areas = ['settings', 'organization'], int $hours = 24): SupportGrant
{
    return app(T00RequestSupportAccess::class)->handle($support, $areas, $hours, 'SMS not reaching customers', ticket: 'SR-0088');
}

function grantAccess(User $owner, SupportGrant $grant, array $areas = ['settings'], int $hours = 24): SupportGrant
{
    return app(T01GrantSupportAccess::class)->handle($owner, $grant, $areas, $hours);
}

function signInAsSupport($test): void
{
    $test->post(route('login.store'), ['email' => 'agent@opsone.test', 'password' => 'password']);
}

describe('M-38 transitions', function () {
    it('requests access, notifies the owner and logs T00', function () {
        $grant = requestAccess($this->support);

        expect($grant->status)->toBe(SupportGrantStatus::Requested)
            ->and(UserNotification::query()->where('user_id', $this->org->owner->id)->where('type', 'support.access_requested')->exists())->toBeTrue()
            ->and(StateTransition::query()->where('machine', 'M-38')->where('transition', 'T00')->exists())->toBeTrue()
            ->and(AuditEvent::query()->where('action', 'support_access.requested')->exists())->toBeTrue();
    });

    it('refuses a request for a non-support account, unknown area or too long', function (string $case) {
        $member = $this->org->member('sales', [$this->org->a]);
        match ($case) {
            'member' => requestAccess($member),
            'area' => requestAccess($this->support, ['payroll']),
            'hours' => requestAccess($this->support, ['settings'], 500),
        };
    })->with(['member', 'area', 'hours'])->throws(InvalidArgumentException::class);

    it('grants requested → active with a window starting now', function () {
        $grant = grantAccess($this->org->owner, requestAccess($this->support));

        expect($grant->status)->toBe(SupportGrantStatus::Active)
            ->and($grant->areas)->toBe(['settings'])
            ->and($grant->expires_at->diffInHours($grant->starts_at, true))->toEqual(24.0)
            ->and(StateTransition::query()->where('transition', 'T01')->where('machine', 'M-38')->sole()->to_state)->toBe('active');
    });

    it('rejects requested → rejected', function () {
        $grant = app(T02RejectSupportAccess::class)->handle($this->org->owner, requestAccess($this->support));

        expect($grant->status)->toBe(SupportGrantStatus::Rejected);
        signInAsSupport($this);
        $this->assertGuest();
    });

    it('refuses invalid transitions', function (string $case) {
        $grant = requestAccess($this->support);
        $owner = $this->org->owner;
        match ($case) {
            'revoke requested' => app(T03RevokeSupportAccess::class)->handle($owner, $grant),
            'grant twice' => grantAccess($owner, grantAccess($owner, $grant)),
            'reject active' => app(T02RejectSupportAccess::class)->handle($owner, grantAccess($owner, $grant)),
            'grant rejected' => grantAccess($owner, app(T02RejectSupportAccess::class)->handle($owner, $grant)),
            'revoke twice' => app(T03RevokeSupportAccess::class)->handle($owner, app(T03RevokeSupportAccess::class)->handle($owner, grantAccess($owner, $grant))),
            'expire not due' => app(T04ExpireSupportAccess::class)->handle(grantAccess($owner, $grant)),
            'wider than requested' => grantAccess($owner, $grant, ['audit']),
            'longer than requested' => grantAccess($owner, $grant, ['settings'], 48),
        };
    })->with(['revoke requested', 'grant twice', 'reject active', 'grant rejected', 'revoke twice', 'expire not due', 'wider than requested', 'longer than requested'])
        ->throws(InvalidTransition::class);

    it('lets nobody without P-79 decide', function () {
        $manager = $this->org->member('manager', [$this->org->a]);

        expect(fn () => grantAccess($manager, requestAccess($this->support)))
            ->toThrow(fn (InvalidTransition $e) => expect($e->condition)->toBe('not_authorized'));
    });

    it('keeps a member holding P-79 within what they can see facility-wide', function () {
        $sales = $this->org->member('sales', [$this->org->a]);
        MemberGrant::query()->create(['membership_id' => Org::membershipOf($sales)->id, 'permission' => 'P-79', 'effect' => 'allow', 'scope' => 'tenant']);

        expect(fn () => grantAccess($sales, requestAccess($this->support)))
            ->toThrow(fn (InvalidTransition $e) => expect($e->condition)->toBe('area_beyond_decider'));
    });

    it('lapses an undecided request and cannot grant it after', function () {
        $grant = requestAccess($this->support);
        $this->travel(73)->hours();

        expect(fn () => grantAccess($this->org->owner, $grant))
            ->toThrow(fn (InvalidTransition $e) => expect($e->condition)->toBe('request_lapsed'));
        expect(app(T04ExpireSupportAccess::class)->handleDue())->toBe(1)
            ->and($grant->refresh()->status)->toBe(SupportGrantStatus::Expired);
    });
});

describe('support sessions', function () {
    it('cannot sign in without an active grant', function () {
        signInAsSupport($this);
        $this->assertGuest();

        requestAccess($this->support);
        signInAsSupport($this);
        $this->assertGuest();
    });

    it('signs in while active and sees only the granted areas, read-only', function () {
        grantAccess($this->org->owner, requestAccess($this->support), ['settings']);

        signInAsSupport($this);
        $this->assertAuthenticatedAs($this->support);

        expect(Access::can($this->support, 'P-01'))->toBeTrue()
            ->and(Access::can($this->support, 'P-03', $this->org->a))->toBeFalse()
            ->and(Access::can($this->support, 'P-79'))->toBeFalse()
            ->and(Access::can($this->support, 'P-63'))->toBeFalse();

        $this->get(route('integrations.edit'))->assertOk();
        $this->get(route('org.branches.index'))->assertForbidden();
        $this->get(route('audit.index'))->assertForbidden();
        $this->get(route('support-access.index'))->assertForbidden();
        $this->put(route('integrations.smtp.update'), ['host' => 'smtp.example.com'])->assertForbidden();
    });

    it('audits the sign-in and every page support opens, visible to the owner', function () {
        grantAccess($this->org->owner, requestAccess($this->support), ['settings']);
        signInAsSupport($this);
        $this->get(route('integrations.edit').'?q=secret-term')->assertOk();

        expect(AuditEvent::query()->where('action', 'support_access.signed_in')->count())->toBe(1);
        $viewed = AuditEvent::query()->where('action', 'support_access.viewed')->where('actor_user_id', $this->support->id)->get();
        expect($viewed->pluck('after.path')->all())->toContain('/settings/integrations')
            ->and(json_encode($viewed->pluck('after')))->not->toContain('secret-term');

        $this->post(route('logout'));
        $this->actingAs($this->org->owner)->get(route('support-access.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('account/support-access')
                ->has('active', 1)
                ->where('activity', fn ($rows) => collect($rows)->contains('action', 'support_access.viewed')));
    });

    it('ends the session immediately on revoke', function () {
        $grant = grantAccess($this->org->owner, requestAccess($this->support), ['settings']);
        signInAsSupport($this);
        $this->get(route('integrations.edit'))->assertOk();

        app(T03RevokeSupportAccess::class)->handle($this->org->owner, $grant);

        $this->get(route('integrations.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
        expect(Access::can($this->support->refresh(), 'P-01'))->toBeFalse();
    });

    it('ends the session at expiry even before the scheduler runs, and records T04', function () {
        $grant = grantAccess($this->org->owner, requestAccess($this->support), ['settings'], 2);
        signInAsSupport($this);
        $this->get(route('integrations.edit'))->assertOk();

        $this->travel(2)->hours();
        $this->travel(1)->seconds();

        $this->get(route('integrations.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
        expect($grant->refresh()->status)->toBe(SupportGrantStatus::Expired)
            ->and(StateTransition::query()->where('machine', 'M-38')->where('transition', 'T04')->exists())->toBeTrue();
    });

    it('ignores a membership row on a support identity', function () {
        $this->support->forceFill(['is_support' => false])->save();
        $member = $this->org->member('manager', [$this->org->a]);
        $member->forceFill(['is_support' => true])->save();

        expect(Access::can($member->refresh(), 'P-03', $this->org->a))->toBeFalse();
    });
});

describe('SupportAccess screen', function () {
    it('is the owner\'s only', function () {
        $manager = $this->org->member('manager', [$this->org->a]);

        $this->actingAs($manager)->get(route('support-access.index'))->assertForbidden();
        $this->actingAs($this->org->owner)->get(route('support-access.index'))->assertOk();
    });

    it('grants with the password, rejects and revokes over HTTP', function () {
        $first = requestAccess($this->support);
        $owner = $this->org->owner;

        $this->actingAs($owner)->post(route('support-access.grant', $first), ['areas' => ['settings'], 'hours' => 4, 'current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        expect($first->refresh()->status)->toBe(SupportGrantStatus::Requested);

        $this->actingAs($owner)->post(route('support-access.grant', $first), ['areas' => ['settings'], 'hours' => 4, 'current_password' => 'password'])
            ->assertRedirect(route('support-access.index'));
        expect($first->refresh()->status)->toBe(SupportGrantStatus::Active)->and($first->hours)->toBe(4);

        $this->actingAs($owner)->post(route('support-access.revoke', $first))->assertRedirect();
        expect($first->refresh()->status)->toBe(SupportGrantStatus::Revoked);

        $second = requestAccess($this->support);
        $this->actingAs($owner)->post(route('support-access.reject', $second))->assertRedirect();
        expect($second->refresh()->status)->toBe(SupportGrantStatus::Rejected);

        $this->actingAs($owner)->post(route('support-access.revoke', $second))->assertSessionHasErrors('transition');
    });

    it('forbids decisions for anyone without P-79', function () {
        $grant = requestAccess($this->support);
        $manager = $this->org->member('manager', [$this->org->a]);

        $this->actingAs($manager)->post(route('support-access.grant', $grant), ['areas' => ['settings'], 'hours' => 4, 'current_password' => 'password'])->assertForbidden();
        $this->actingAs($manager)->post(route('support-access.reject', $grant))->assertForbidden();
        expect($grant->refresh()->status)->toBe(SupportGrantStatus::Requested);
    });
});
