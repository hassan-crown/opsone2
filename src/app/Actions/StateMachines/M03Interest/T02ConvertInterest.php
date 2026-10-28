<?php

namespace App\Actions\StateMachines\M03Interest;

use App\Access\Authorizer;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Actions\Customers\CreateCustomer;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Customers\CustomerAccess;
use App\Enums\CustomerKind;
use App\Enums\InterestStatus;
use App\Enums\StateMachine;
use App\Models\Customer;
use App\Models\Interest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * M-03-T02: convert an open interest (P-15), once. The interest is linked to the customer it came from, an existing
 * one (by link, then by mobile or email) or a new one created from its data (which also needs P-09).
 * A second conversion is refused before any write, so no second customer or transition appears (COM-03).
 *
 * TODO(P4 orders): create the minimal made-to-order order and store its reference on the interest.
 */
final class T02ConvertInterest
{
    public function __construct(
        private readonly Authorizer $access,
        private readonly TransitionRecorder $transitions,
        private readonly CreateCustomer $createCustomer,
    ) {}

    public function handle(User $actor, Interest $interest, string $source = TransitionRecorder::SOURCE_WEB): Interest
    {
        $this->access->authorize($actor, Permission::P15, $interest);

        return DB::transaction(function () use ($actor, $interest, $source) {
            $locked = Interest::query()->lockForUpdate()->findOrFail($interest->id);

            if ($locked->status === InterestStatus::Converted) {
                throw new InvalidTransition(StateMachine::M03, 'T02', 'already_converted');
            }
            if (! $locked->isOpen()) {
                throw new InvalidTransition(StateMachine::M03, 'T02', 'not_open');
            }

            $from = $locked->status;
            [$customer, $created] = $this->resolveCustomer($actor, $locked, $source);

            $locked->forceFill([
                'status' => InterestStatus::Converted,
                'customer_id' => $customer->id,
                'converted_customer_id' => $customer->id,
                'converted_at' => now(),
                'converted_by' => $actor->id,
            ])->save();

            $this->transitions->record(
                StateMachine::M03, 'T02', $locked, $from, InterestStatus::Converted, $actor, $source,
                context: ['customer_id' => $customer->id, 'customer_created' => $created],
            );
            Audit::record(
                AuditAction::InterestConverted, $locked, $actor,
                before: ['status' => $from], after: ['status' => InterestStatus::Converted, 'customer_id' => $customer->id, 'customer_created' => $created],
                source: $source,
            );

            return $locked;
        });
    }

    /**
     * @return array{0: Customer, 1: bool}
     */
    private function resolveCustomer(User $actor, Interest $interest, string $source): array
    {
        $existing = $interest->customer_id !== null ? Customer::query()->find($interest->customer_id) : null;
        $existing ??= $interest->mobile === null ? null : Customer::query()->where('mobile', $interest->mobile)->first();
        $existing ??= $interest->email === null ? null : Customer::query()->where('email', $interest->email)->first();

        if ($existing !== null) {
            // Never reveal (or link) a customer the actor cannot see.
            if (! CustomerAccess::canViewCustomer($actor, $existing)) {
                throw ValidationException::withMessages(['interest' => __('interests.validation.customer_hidden')]);
            }

            return [$existing, false];
        }

        if ($interest->mobile === null) {
            throw ValidationException::withMessages(['interest' => __('interests.validation.mobile_required')]);
        }

        $customer = $this->createCustomer->handle($actor, [
            'kind' => CustomerKind::Person->value,
            'name' => $interest->name,
            'mobile' => $interest->mobile,
            'email' => $interest->email,
            'branch_id' => $interest->branch_id,
            'assignee_id' => $interest->assignee_id ?? $actor->id,
        ], $source);

        return [$customer, true];
    }
}
