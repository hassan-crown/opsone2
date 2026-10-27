<?php

namespace Database\Seeders;

use App\Access\TransitionRecorder;
use App\Actions\Organization\EstablishOwner;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Establishes the owner from config opsone.owner (OPSONE_OWNER_EMAIL / OPSONE_OWNER_NAME) when the facility has none.
 * The account gets an unknown random password; the owner sets theirs through «forgot password».
 * TODO(P2 wave 2): replace with the owner invitation from provisioning (ORG «أول دخول»).
 */
class OwnerSeeder extends Seeder
{
    public function run(EstablishOwner $establishOwner): void
    {
        $email = config('opsone.owner.email');

        if (! is_string($email) || $email === '' || Facility::current()?->owner_membership_id !== null) {
            return;
        }

        $user = User::query()->firstOrCreate(
            ['email' => Str::lower($email)],
            ['name' => (string) config('opsone.owner.name'), 'password' => Str::password(40)],
        );

        $establishOwner->handle($user, TransitionRecorder::SOURCE_SEEDER);
    }
}
