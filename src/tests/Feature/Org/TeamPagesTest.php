<?php

use App\Actions\StateMachines\M01Invitation\T00CreateInvitation;
use App\Mail\OrgMessage;
use App\Models\Invitation;
use App\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->manager = $this->org->member('manager', [$this->org->a]);
    $this->inA = $this->org->member('sales', [$this->org->a]);
    $this->inC = $this->org->member('sales', [$this->org->c]);
});

it('lists every member for the owner and only in-scope members for a branch manager', function () {
    $this->actingAs($this->org->owner)->get(route('org.team.index'))
        ->assertInertia(fn (Assert $p) => $p->component('org/team/index')->has('members', 4));

    $this->actingAs($this->manager)->get(route('org.team.index'))
        ->assertInertia(fn (Assert $p) => $p->has('members', 2)->where('canInvite', true));
});

it('filters by status and search', function () {
    $this->actingAs($this->org->owner);
    $this->post(route('org.team.member.disable', Org::membershipOf($this->inC)), ['reason' => 'left']);
    $this->get(route('org.team.index', ['status' => 'disabled']))->assertInertia(fn (Assert $p) => $p->has('members', 1));
    $this->get(route('org.team.index', ['q' => $this->inA->email]))->assertInertia(fn (Assert $p) => $p->has('members', 1));
});

it('denies team pages to users without team permissions', function () {
    $this->actingAs($this->inA);
    $this->get(route('org.team.index'))->assertForbidden();
    $this->get(route('org.team.invitations'))->assertForbidden();
    $this->get(route('org.team.create'))->assertForbidden();
    $this->post(route('org.team.store'), [])->assertForbidden();
    $this->get(route('org.team.member', Org::membershipOf($this->org->owner)))->assertForbidden();
});

it('denies an out-of-scope member to a branch manager', function () {
    $this->actingAs($this->manager);
    $c = Org::membershipOf($this->inC);
    $this->get(route('org.team.member', $c))->assertForbidden();
    $this->get(route('org.team.member.confirm-disable', $c))->assertForbidden();
    $this->post(route('org.team.member.disable', $c), ['reason' => 'x'])->assertForbidden();
    $this->put(route('org.team.member.access', $c), ['roles' => [['role_template_id' => Org::templateId('sales')]]])->assertForbidden();
});

it('invites a colleague by email and surfaces the seat limit', function () {
    Mail::fake();
    $payload = fn (string $email) => [
        'name' => 'Sara', 'email' => $email, 'branch_ids' => [$this->org->a->id],
        'roles' => [['role_template_id' => Org::templateId('sales'), 'scope' => null]],
    ];

    $this->actingAs($this->org->owner)->post(route('org.team.store'), $payload('sara@example.com'))
        ->assertRedirect(route('org.team.invitations'));
    Mail::assertSent(OrgMessage::class, fn (OrgMessage $m) => $m->action !== null && str_contains($m->action['url'], '/invitations/'));
    expect(Invitation::query()->where('email', 'sara@example.com')->exists())->toBeTrue();

    LicenseFactory::install(['limits' => ['seats' => 5, 'branches' => 3, 'storage_mb' => 1]]);
    $this->post(route('org.team.store'), $payload('late@example.com'))->assertSessionHasErrors('license');
    expect(Invitation::query()->where('email', 'late@example.com')->exists())->toBeFalse();
});

it('refuses to invite beyond the inviter scope', function () {
    Mail::fake();
    $this->actingAs($this->manager)->post(route('org.team.store'), [
        'name' => 'X', 'email' => 'x@example.com', 'branch_ids' => [$this->org->c->id],
        'roles' => [['role_template_id' => Org::templateId('sales')]],
    ])->assertSessionHasErrors('roles');
    Mail::assertNothingSent();
});

it('renews and cancels invitations', function () {
    Mail::fake();
    [$invitation] = app(T00CreateInvitation::class)->handle($this->org->owner, [
        'email' => 'r@example.com', 'branch_ids' => [$this->org->a->id], 'roles' => [['role_template_id' => Org::templateId('sales')]],
    ]);
    $this->actingAs($this->org->owner)->post(route('org.team.renew', $invitation))->assertRedirect(route('org.team.invitations'));
    $new = Invitation::query()->where('email', 'r@example.com')->where('status', 'pending')->firstOrFail();
    $this->post(route('org.team.cancel', $new))->assertRedirect();
    expect($new->refresh()->status->value)->toBe('cancelled');
    $this->get(route('org.team.invitations'))->assertInertia(fn (Assert $p) => $p->component('org/team/index')->has('invitations', 2));
});

it('disables a member with a reason, revokes sessions at once and can reactivate', function () {
    $this->actingAs($this->org->owner);
    $m = Org::membershipOf($this->inA);
    $before = $this->inA->refresh()->access_version;

    $this->post(route('org.team.member.disable', $m), [])->assertSessionHasErrors('reason');
    $this->post(route('org.team.member.disable', $m), ['reason' => 'contract ended'])->assertRedirect(route('org.team.index'));
    expect($m->refresh()->status->value)->toBe('disabled')->and($this->inA->refresh()->access_version)->toBeGreaterThan($before);

    $this->post(route('org.team.member.reactivate', $m))->assertRedirect();
    expect($m->refresh()->status->value)->toBe('active');

    $this->post(route('org.team.member.disable', Org::membershipOf($this->org->owner)), ['reason' => 'x'])->assertSessionHasErrors('transition');
});

it('shows member grants and updates roles, ending their sessions', function () {
    $this->actingAs($this->org->owner);
    $m = Org::membershipOf($this->inA);
    $this->get(route('org.team.member', $m))->assertInertia(fn (Assert $p) => $p->component('org/team/member')->has('grantGroups')->where('can.access', true));

    $before = $this->inA->refresh()->access_version;
    $this->put(route('org.team.member.access', $m), ['roles' => [['role_template_id' => Org::templateId('finance'), 'scope' => '']]])
        ->assertRedirect(route('org.team.member', $m));
    expect($m->refresh()->roles()->first()->role_template_id)->toBe(Org::templateId('finance'))
        ->and($this->inA->refresh()->access_version)->toBeGreaterThan($before);
    expect(Membership::query()->count())->toBe(4);
});

it('refuses reactivation when no seat is left', function () {
    $this->actingAs($this->org->owner);
    $m = Org::membershipOf($this->inA);
    $this->post(route('org.team.member.disable', $m), ['reason' => 'x']);
    LicenseFactory::install(['limits' => ['seats' => 3, 'branches' => 3, 'storage_mb' => 1]]);
    $this->post(route('org.team.member.reactivate', $m))->assertSessionHasErrors('license');
    expect($m->refresh()->status->value)->toBe('disabled');
});
