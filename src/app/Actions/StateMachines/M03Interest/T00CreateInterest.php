<?php

namespace App\Actions\StateMachines\M03Interest;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Customers\CustomerAccess;
use App\Customers\Phone;
use App\Enums\InterestStatus;
use App\Enums\StateMachine;
use App\Models\Customer;
use App\Models\Interest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * M-03-T00: record an interest in a branch inside the actor's P-09 grant. It starts as «new»; no account or
 * quotation is implied (COM-03).
 */
final class T00CreateInterest
{
    public function __construct(private readonly Authorizer $access, private readonly TransitionRecorder $transitions) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, string $source = TransitionRecorder::SOURCE_WEB): Interest
    {
        $data['mobile'] = isset($data['mobile']) && $data['mobile'] !== '' ? (Phone::normalize((string) $data['mobile']) ?? $data['mobile']) : null;
        $data['email'] = isset($data['email']) && $data['email'] !== '' ? mb_strtolower(trim((string) $data['email'])) : null;

        $interest = new Interest($data);
        $interest->created_by = $actor->id;
        $interest->status = InterestStatus::New;
        $this->access->authorize($actor, Permission::P09, $interest);

        if ($interest->customer_id !== null) {
            $customer = Customer::query()->find($interest->customer_id);
            if ($customer === null || ! CustomerAccess::canViewCustomer($actor, $customer)) {
                throw ValidationException::withMessages(['customer_id' => __('interests.validation.customer')]);
            }
        }

        return DB::transaction(function () use ($interest, $actor, $source) {
            $interest->save();
            $interest->forceFill(['reference' => sprintf('INT-%04d', $interest->id)])->save();

            $this->transitions->record(StateMachine::M03, 'T00', $interest, null, InterestStatus::New, $actor, $source);
            Audit::record(
                AuditAction::InterestCreated, $interest, $actor,
                after: $interest->only(['reference', 'name', 'channel', 'branch_id', 'customer_id', 'assignee_id']), source: $source,
            );

            return $interest;
        });
    }
}
