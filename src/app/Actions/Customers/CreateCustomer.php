<?php

namespace App\Actions\Customers;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Customers\CustomerData;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P-09: create a customer in a branch inside the actor's grant. The database's unique keys on the normalized
 * mobile and email decide concurrent duplicates; a lost race surfaces as the same validation error as the form.
 */
final class CreateCustomer
{
    public function __construct(private readonly Authorizer $access) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, string $source = TransitionRecorder::SOURCE_WEB): Customer
    {
        $data = CustomerData::normalize($data);
        $customer = new Customer($data);
        $customer->created_by = $actor->id;
        $this->access->authorize($actor, Permission::P09, $customer);

        try {
            return DB::transaction(function () use ($customer, $actor, $source) {
                $customer->save();
                Audit::record(
                    AuditAction::CustomerCreated, $customer, $actor,
                    after: CustomerData::forAudit($customer->only(['kind', 'name', 'mobile', 'email', 'branch_id', 'assignee_id', ...Customer::SENSITIVE])),
                    source: $source,
                );

                return $customer;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(self::duplicateMessages($customer));
        }
    }

    /**
     * @return array<string, string>
     */
    public static function duplicateMessages(Customer $customer): array
    {
        $messages = [];
        if (Customer::query()->where('mobile', $customer->mobile)->whereKeyNot($customer->getKey())->exists()) {
            $messages['mobile'] = __('customers.validation.mobile_taken');
        }
        if ($customer->email !== null && Customer::query()->where('email', $customer->email)->whereKeyNot($customer->getKey())->exists()) {
            $messages['email'] = __('customers.validation.email_taken');
        }

        return $messages === [] ? ['mobile' => __('customers.validation.mobile_taken')] : $messages;
    }
}
