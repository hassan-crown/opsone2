<?php

namespace App\Actions\Customers;

use App\Access\Authorizer;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Customers\Phone;
use App\Enums\StateMachine;
use App\Models\Interest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P-09: edit the details of an open interest (name, contact, need, channel, owner). The branch stays as recorded.
 */
final class UpdateInterest
{
    private const FIELDS = ['name', 'mobile', 'email', 'need', 'channel', 'assignee_id'];

    public function __construct(private readonly Authorizer $access) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Interest $interest, array $data): Interest
    {
        $this->access->authorize($actor, Permission::P09, $interest);
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        if (array_key_exists('mobile', $data)) {
            $data['mobile'] = is_string($data['mobile']) && $data['mobile'] !== '' ? (Phone::normalize($data['mobile']) ?? $data['mobile']) : null;
        }
        if (array_key_exists('email', $data)) {
            $data['email'] = is_string($data['email']) && $data['email'] !== '' ? mb_strtolower(trim($data['email'])) : null;
        }

        return DB::transaction(function () use ($actor, $interest, $data) {
            $locked = Interest::query()->lockForUpdate()->findOrFail($interest->id);
            if (! $locked->isOpen()) {
                throw new InvalidTransition(StateMachine::M03, 'T00', 'not_open');
            }

            $locked->fill($data);
            $changed = array_keys($locked->getDirty());
            $before = array_intersect_key($locked->getOriginal(), array_flip($changed));
            $locked->save();
            if ($changed !== []) {
                Audit::record(AuditAction::InterestUpdated, $locked, $actor, before: $before, after: $locked->only($changed));
            }

            return $locked;
        });
    }
}
