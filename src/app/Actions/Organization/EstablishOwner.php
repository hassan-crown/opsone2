<?php

namespace App\Actions\Organization;

use App\Access\TransitionRecorder;
use App\Actions\StateMachines\M22Membership\T00CreateMembership;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\RoleTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Provisioning step: make a user the facility's first owner. Refuses when an owner already exists;
 * afterwards ownership only moves through M-37.
 */
final class EstablishOwner
{
    public function __construct(private readonly T00CreateMembership $createMembership) {}

    public function handle(User $user, string $source = TransitionRecorder::SOURCE_PROVISIONING): Membership
    {
        return DB::transaction(function () use ($user, $source) {
            $facility = Facility::query()->lockForUpdate()->first()
                ?? throw new RuntimeException('Seed the facility profile before its owner.');

            if ($facility->owner_membership_id !== null) {
                throw new RuntimeException('The facility already has an owner; use an ownership transfer (M-37).');
            }

            $ownerTemplate = RoleTemplate::query()->where('is_owner', true)->value('id');

            $membership = Membership::query()->where('user_id', $user->id)->first()
                ?? $this->createMembership->handle(
                    $user, null, [], $ownerTemplate === null ? [] : [['role_template_id' => (int) $ownerTemplate]], null, null, $source,
                );

            $facility->forceFill(['owner_membership_id' => $membership->id])->save();

            return $membership;
        });
    }
}
