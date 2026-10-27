<?php

namespace App\Actions\Customers;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P-10: contact persons of an organization (COM-02). Exactly one active primary whenever any active contact exists;
 * the database keeps that true too (`primary_of` is unique). Removal keeps history and never deletes.
 */
final class ManageContacts
{
    private const FIELDS = ['name', 'mobile', 'job_title', 'email', 'note'];

    public function __construct(private readonly Authorizer $access) {}

    /**
     * @param  array<string, mixed>  $data  contact fields plus optional `is_primary`
     */
    public function add(User $actor, Customer $customer, array $data): CustomerContact
    {
        $this->access->authorize($actor, Permission::P10, $customer);

        return DB::transaction(function () use ($actor, $customer, $data) {
            $locked = $this->lockOrganization($customer);

            $contact = new CustomerContact(array_intersect_key($data, array_flip(self::FIELDS)));
            $contact->customer_id = $locked->id;
            $contact->save();

            $hasPrimary = $this->active($locked)->where('is_primary', true)->exists();
            if (! $hasPrimary || ($data['is_primary'] ?? false)) {
                $this->makePrimary($locked, $contact);
            }

            Audit::record(AuditAction::CustomerContactAdded, $locked, $actor, after: ['contact_id' => $contact->id, 'name' => $contact->name, 'is_primary' => $contact->is_primary]);

            return $contact->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, CustomerContact $contact, array $data): CustomerContact
    {
        $customer = $contact->customer;
        $this->access->authorize($actor, Permission::P10, $customer);

        return DB::transaction(function () use ($actor, $contact, $data) {
            $locked = $this->lockOrganization($contact->customer);
            $fresh = $this->activeContact($locked, $contact);
            $fresh->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            $changed = array_keys($fresh->getDirty());
            $fresh->save();

            if ($changed !== []) {
                Audit::record(AuditAction::CustomerContactUpdated, $locked, $actor, after: ['contact_id' => $fresh->id, 'fields' => $changed]);
            }

            return $fresh;
        });
    }

    public function setPrimary(User $actor, CustomerContact $contact): CustomerContact
    {
        $this->access->authorize($actor, Permission::P10, $contact->customer);

        return DB::transaction(function () use ($actor, $contact) {
            $locked = $this->lockOrganization($contact->customer);
            $fresh = $this->activeContact($locked, $contact);
            if (! $fresh->is_primary) {
                $this->makePrimary($locked, $fresh);
                Audit::record(AuditAction::CustomerContactPrimaryChanged, $locked, $actor, after: ['contact_id' => $fresh->id]);
            }

            return $fresh->refresh();
        });
    }

    public function remove(User $actor, CustomerContact $contact): void
    {
        $this->access->authorize($actor, Permission::P10, $contact->customer);

        DB::transaction(function () use ($actor, $contact) {
            $locked = $this->lockOrganization($contact->customer);
            $fresh = $this->activeContact($locked, $contact);
            $wasPrimary = $fresh->is_primary;

            $fresh->forceFill(['removed_at' => now(), 'is_primary' => false, 'primary_of' => null])->save();

            $promoted = null;
            if ($wasPrimary && ($next = $this->active($locked)->orderBy('id')->first()) !== null) {
                $this->makePrimary($locked, $next);
                $promoted = $next->id;
            }

            Audit::record(AuditAction::CustomerContactRemoved, $locked, $actor, before: ['contact_id' => $fresh->id, 'was_primary' => $wasPrimary], after: ['new_primary_contact_id' => $promoted]);
        });
    }

    private function lockOrganization(Customer $customer): Customer
    {
        $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
        if (! $locked->isOrganization()) {
            throw ValidationException::withMessages(['contact' => __('customers.validation.contacts_organization_only')]);
        }

        return $locked;
    }

    private function activeContact(Customer $customer, CustomerContact $contact): CustomerContact
    {
        return $this->active($customer)->whereKey($contact->id)->firstOrFail();
    }

    /**
     * @return Builder<CustomerContact>
     */
    private function active(Customer $customer): Builder
    {
        return CustomerContact::query()->where('customer_id', $customer->id)->whereNull('removed_at');
    }

    private function makePrimary(Customer $customer, CustomerContact $contact): void
    {
        $this->active($customer)->where('is_primary', true)->whereKeyNot($contact->id)->update(['is_primary' => false, 'primary_of' => null]);
        $contact->forceFill(['is_primary' => true, 'primary_of' => $customer->id])->save();
    }
}
