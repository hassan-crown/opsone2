<?php

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M37OwnershipTransfer\T00RequestOwnershipTransfer;
use App\Actions\StateMachines\M37OwnershipTransfer\T01AcceptOwnershipTransfer;
use App\Actions\StateMachines\M37OwnershipTransfer\T02RejectOwnershipTransfer;
use App\Actions\StateMachines\M37OwnershipTransfer\T03CancelOwnershipTransfer;
use App\Actions\StateMachines\M37OwnershipTransfer\T04ExpireOwnershipTransfer;
use App\Enums\OwnershipTransferStatus;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->recipient = $this->org->member('manager', [$this->org->a]);
    $this->transfer = app(T00RequestOwnershipTransfer::class)
        ->handle($this->org->owner, 'password', Org::membershipOf($this->recipient), 'retiring');
});

it('moves ownership when the active recipient accepts', function () {
    $previousOwnerVersion = $this->org->owner->refresh()->access_version;

    app(T01AcceptOwnershipTransfer::class)->handle($this->recipient, $this->transfer);

    expect(Facility::currentOrFail()->owner_membership_id)->toBe(Org::membershipOf($this->recipient)->id)
        ->and($this->transfer->refresh()->status)->toBe(OwnershipTransferStatus::Accepted)
        ->and(Access::can($this->recipient, 'P-07'))->toBeTrue()
        ->and(Access::can($this->org->owner, 'P-07'))->toBeFalse()
        ->and($this->org->owner->refresh()->access_version)->toBeGreaterThan($previousOwnerVersion);

    expect(StateTransition::query()->where('machine', 'M-37')->pluck('transition')->all())->toBe(['T00', 'T01']);
});

it('lets the recipient reject and the owner cancel', function () {
    app(T02RejectOwnershipTransfer::class)->handle($this->recipient, $this->transfer, 'not now');
    expect($this->transfer->refresh()->status)->toBe(OwnershipTransferStatus::Rejected);

    $second = app(T00RequestOwnershipTransfer::class)->handle($this->org->owner, 'password', Org::membershipOf($this->recipient));
    app(T03CancelOwnershipTransfer::class)->handle($this->org->owner, $second);
    expect($second->refresh()->status)->toBe(OwnershipTransferStatus::Cancelled)
        ->and(Facility::currentOrFail()->owner_membership_id)->toBe(Org::membershipOf($this->org->owner)->id);
});

it('expires after seven days and can no longer be accepted', function () {
    $this->travel(7)->days();

    expect(fn () => app(T01AcceptOwnershipTransfer::class)->handle($this->recipient, $this->transfer))
        ->toThrow(InvalidTransition::class);

    $this->artisan('access:expire')->assertSuccessful();
    expect($this->transfer->refresh()->status)->toBe(OwnershipTransferStatus::Expired)
        ->and(Facility::currentOrFail()->owner_membership_id)->toBe(Org::membershipOf($this->org->owner)->id);
});

it('rejects invalid transitions without effect', function (Closure $attempt) {
    $owner = Facility::currentOrFail()->owner_membership_id;
    $logCount = StateTransition::query()->count();

    expect(fn () => $attempt($this))->toThrow(InvalidTransition::class);

    expect(Facility::currentOrFail()->owner_membership_id)->toBe($owner)
        ->and($this->transfer->refresh()->status)->toBe(OwnershipTransferStatus::Requested)
        ->and(StateTransition::query()->count())->toBe($logCount);
})->with([
    'accept by someone else' => [fn ($t) => app(T01AcceptOwnershipTransfer::class)->handle($t->org->owner, $t->transfer)],
    'reject by someone else' => [fn ($t) => app(T02RejectOwnershipTransfer::class)->handle($t->org->owner, $t->transfer)],
    'cancel by the recipient' => [fn ($t) => app(T03CancelOwnershipTransfer::class)->handle($t->recipient, $t->transfer)],
    'expire before due' => [fn ($t) => app(T04ExpireOwnershipTransfer::class)->handle($t->transfer)],
    'a second open request' => [fn ($t) => app(T00RequestOwnershipTransfer::class)->handle($t->org->owner, 'password', Org::membershipOf($t->recipient))],
    'accept by a disabled recipient' => [function ($t) {
        Membership::query()->where('user_id', $t->recipient->id)->update(['status' => 'disabled']);
        app(T01AcceptOwnershipTransfer::class)->handle($t->recipient, $t->transfer);
    }],
]);

it('refuses decisions on an already decided transfer', function () {
    app(T02RejectOwnershipTransfer::class)->handle($this->recipient, $this->transfer);

    expect(fn () => app(T01AcceptOwnershipTransfer::class)->handle($this->recipient, $this->transfer))
        ->toThrow(InvalidTransition::class);
    expect($this->transfer->refresh()->status)->toBe(OwnershipTransferStatus::Rejected);
});

it('refuses a transfer to oneself or to a disabled member', function () {
    app(T03CancelOwnershipTransfer::class)->handle($this->org->owner, $this->transfer);
    $disabled = $this->org->member('sales', [$this->org->a]);
    Membership::query()->where('user_id', $disabled->id)->update(['status' => 'disabled']);

    expect(fn () => app(T00RequestOwnershipTransfer::class)->handle($this->org->owner, 'password', Org::membershipOf($this->org->owner)))
        ->toThrow(InvalidTransition::class)
        ->and(fn () => app(T00RequestOwnershipTransfer::class)->handle($this->org->owner, 'password', Org::membershipOf($disabled)))
        ->toThrow(InvalidTransition::class);
});
