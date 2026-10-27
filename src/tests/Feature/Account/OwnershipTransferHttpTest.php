<?php

use App\Actions\StateMachines\M37OwnershipTransfer\T04ExpireOwnershipTransfer;
use App\Enums\OwnershipTransferStatus;
use App\Models\Facility;
use App\Models\OwnershipTransfer;
use App\Models\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->manager = $this->org->member('manager', [$this->org->a]);
    $this->managerMembership = Org::membershipOf($this->manager);
    $this->request = fn (array $overrides = []) => $this->actingAs($this->org->owner)
        ->from(route('ownership.show'))
        ->post(route('ownership.store'), [
            'recipient_id' => $this->managerMembership->id,
            'current_password' => 'password',
            'reason' => 'retiring',
            ...$overrides,
        ]);
});

it('lets the owner request a transfer after re-entering their password', function () {
    ($this->request)()->assertRedirect(route('ownership.show'))->assertSessionHasNoErrors();

    $transfer = OwnershipTransfer::query()->sole();
    expect($transfer->status)->toBe(OwnershipTransferStatus::Requested)
        ->and($transfer->to_membership_id)->toBe($this->managerMembership->id)
        ->and($transfer->reason)->toBe('retiring');

    $this->actingAs($this->org->owner)->get(route('ownership.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/ownership')
            ->where('isOwner', true)
            ->where('open.role', 'requester')
            ->where('open.to.name', $this->manager->name)
            ->where('candidates', []));
});

it('rejects a wrong password and creates nothing', function () {
    ($this->request)(['current_password' => 'wrong-password'])->assertSessionHasErrors('current_password');

    expect(OwnershipTransfer::query()->count())->toBe(0);
});

it('rejects an unknown, disabled or self recipient', function () {
    ($this->request)(['recipient_id' => 9999])->assertSessionHasErrors('recipient_id');

    ($this->request)(['recipient_id' => Org::membershipOf($this->org->owner)->id])->assertSessionHasErrors('transfer');

    $this->managerMembership->forceFill(['status' => 'disabled'])->save();
    ($this->request)()->assertSessionHasErrors('recipient_id');

    expect(OwnershipTransfer::query()->count())->toBe(0);
});

it('allows only one open request at a time', function () {
    ($this->request)()->assertSessionHasNoErrors();
    $other = $this->org->member('manager', [$this->org->b]);

    ($this->request)(['recipient_id' => Org::membershipOf($other)->id])->assertSessionHasErrors('transfer');

    expect(OwnershipTransfer::query()->count())->toBe(1);
});

it('denies the request to non-owners', function () {
    $this->actingAs($this->manager)->get(route('ownership.show'))->assertForbidden();
    $this->actingAs($this->manager)->post(route('ownership.store'), [
        'recipient_id' => Org::membershipOf($this->org->owner)->id,
        'current_password' => 'password',
    ])->assertForbidden();

    expect(OwnershipTransfer::query()->count())->toBe(0);
});

it('lets the recipient see and accept the request, moving ownership and ending both sessions', function () {
    ($this->request)();
    $transfer = OwnershipTransfer::query()->sole();

    $this->actingAs($this->manager)->get(route('ownership.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('open.role', 'recipient')->where('isOwner', false));

    $this->actingAs($this->manager)->post(route('ownership.accept', $transfer))->assertRedirect(route('ownership.show'));

    expect(Facility::currentOrFail()->owner_membership_id)->toBe($this->managerMembership->id)
        ->and($transfer->refresh()->status)->toBe(OwnershipTransferStatus::Accepted)
        ->and(StateTransition::query()->where('machine', 'M-37')->pluck('transition')->all())->toBe(['T00', 'T01']);

    // The new owner's old session is revoked: the next request goes to sign in.
    $this->get(route('ownership.show'))->assertRedirect(route('login'));
});

it('lets the recipient reject with a reason', function () {
    ($this->request)();
    $transfer = OwnershipTransfer::query()->sole();

    $this->actingAs($this->manager)->post(route('ownership.reject', $transfer), ['reason' => 'not now'])
        ->assertRedirect(route('ownership.show'));

    expect($transfer->refresh()->status)->toBe(OwnershipTransferStatus::Rejected)
        ->and(Facility::currentOrFail()->owner_membership_id)->toBe(Org::membershipOf($this->org->owner)->id);
});

it('lets the owner cancel and then request again', function () {
    ($this->request)();
    $transfer = OwnershipTransfer::query()->sole();

    $this->actingAs($this->org->owner)->delete(route('ownership.cancel', $transfer))->assertRedirect(route('ownership.show'));
    expect($transfer->refresh()->status)->toBe(OwnershipTransferStatus::Cancelled);

    ($this->request)()->assertSessionHasNoErrors();
    expect(OwnershipTransfer::query()->count())->toBe(2);
});

it('forbids the wrong party from deciding', function () {
    ($this->request)();
    $transfer = OwnershipTransfer::query()->sole();
    $bystander = $this->org->member('manager', [$this->org->b]);

    $this->actingAs($this->org->owner)->post(route('ownership.accept', $transfer))->assertForbidden();
    $this->actingAs($this->org->owner)->post(route('ownership.reject', $transfer))->assertForbidden();
    $this->actingAs($this->manager)->delete(route('ownership.cancel', $transfer))->assertForbidden();
    $this->actingAs($bystander)->post(route('ownership.accept', $transfer))->assertForbidden();
    $this->actingAs($bystander)->get(route('ownership.show'))->assertForbidden();

    expect($transfer->refresh()->status)->toBe(OwnershipTransferStatus::Requested);
});

it('refuses decisions on a request that was already decided', function () {
    ($this->request)();
    $transfer = OwnershipTransfer::query()->sole();
    $this->actingAs($this->org->owner)->delete(route('ownership.cancel', $transfer));

    $this->actingAs($this->manager)->post(route('ownership.accept', $transfer))
        ->assertRedirect(route('ownership.show'))
        ->assertSessionHasErrors('transfer');
    $this->actingAs($this->org->owner)->delete(route('ownership.cancel', $transfer))->assertSessionHasErrors('transfer');

    expect($transfer->refresh()->status)->toBe(OwnershipTransferStatus::Cancelled)
        ->and(Facility::currentOrFail()->owner_membership_id)->toBe(Org::membershipOf($this->org->owner)->id);
});

it('refuses acceptance once the request expired, and the sweep marks it expired', function () {
    ($this->request)();
    $transfer = OwnershipTransfer::query()->sole();

    $this->travel(8)->days();

    $this->actingAs($this->manager)->post(route('ownership.accept', $transfer))->assertSessionHasErrors('transfer');
    expect($transfer->refresh()->status)->toBe(OwnershipTransferStatus::Requested);

    $this->actingAs($this->org->owner)->get(route('ownership.show'))
        ->assertInertia(fn (Assert $page) => $page->where('open', null)->where('history.0.status', 'expired'));

    app(T04ExpireOwnershipTransfer::class)->handleDue();
    expect($transfer->refresh()->status)->toBe(OwnershipTransferStatus::Expired);
});

it('does not let a recipient disabled meanwhile accept', function () {
    ($this->request)();
    $transfer = OwnershipTransfer::query()->sole();
    $this->managerMembership->forceFill(['status' => 'disabled'])->save();

    // The disabled account is signed out by the access middleware before it can decide.
    $this->actingAs($this->manager)->post(route('ownership.accept', $transfer))->assertRedirect(route('login'));

    expect($transfer->refresh()->status)->toBe(OwnershipTransferStatus::Requested);
});
