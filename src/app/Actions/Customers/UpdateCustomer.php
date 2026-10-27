<?php

namespace App\Actions\Customers;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Customers\CustomerData;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P-10: edit customer data and business details. The branch is fixed at creation (branch transfer belongs to orders).
 * Sensitive fields can only be changed here because P-10 is also what reveals them.
 */
final class UpdateCustomer
{
    private const FIELDS = [
        'kind', 'name', 'mobile', 'email', 'portal_login_enabled', 'country', 'assignee_id', 'preferred_channel',
        'internal_notes', 'commercial_registration', 'vat_number', 'office_phone',
    ];

    public function __construct(private readonly Authorizer $access) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Customer $customer, array $data): Customer
    {
        $this->access->authorize($actor, Permission::P10, $customer);
        $data = CustomerData::normalize(array_intersect_key($data, array_flip(self::FIELDS)));

        try {
            return DB::transaction(function () use ($actor, $customer, $data) {
                $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
                $locked->fill($data);
                $changed = array_keys($locked->getDirty());
                $before = array_intersect_key($locked->getOriginal(), array_flip($changed));
                $locked->save();

                if ($changed !== []) {
                    Audit::record(
                        AuditAction::CustomerUpdated, $locked, $actor,
                        before: CustomerData::forAudit($before), after: CustomerData::forAudit($locked->only($changed)),
                    );
                }

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(CreateCustomer::duplicateMessages($customer->fill($data)));
        }
    }
}
