<?php

namespace App\Customers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Interest;
use App\Models\Membership;
use App\Models\User;
use App\Organization\OrgPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Shapes customers and interests for the Inertia pages. Sensitive customer fields are left out of the payload
 * (not just hidden in the UI) unless the actor holds P-10 over the record.
 */
final class CustomerPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(Customer $customer, User $actor): array
    {
        return [
            'id' => $customer->id,
            'kind' => $customer->kind->value,
            'name' => $customer->name,
            'mobile' => $customer->mobile,
            'email' => $customer->email,
            'branch' => OrgPresenter::name($customer->branch),
            'assignee' => $customer->assignee?->name,
            'created_at' => $customer->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Customer $customer, User $actor): array
    {
        $data = self::row($customer, $actor) + [
            'branch_id' => $customer->branch_id,
            'assignee_id' => $customer->assignee_id,
            'country' => $customer->country,
            'portal_login_enabled' => $customer->portal_login_enabled,
            'preferred_channel' => $customer->preferred_channel?->value,
        ];

        if (CustomerAccess::canSeeSensitive($actor, $customer)) {
            $data += $customer->only(Customer::SENSITIVE);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function contact(CustomerContact $contact): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'mobile' => $contact->mobile,
            'job_title' => $contact->job_title,
            'email' => $contact->email,
            'note' => $contact->note,
            'is_primary' => $contact->is_primary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function interest(Interest $interest): array
    {
        return [
            'id' => $interest->id,
            'reference' => $interest->reference,
            'status' => $interest->status->value,
            'name' => $interest->name,
            'mobile' => $interest->mobile,
            'email' => $interest->email,
            'need' => $interest->need,
            'channel' => $interest->channel->value,
            'branch' => OrgPresenter::name($interest->branch),
            'branch_id' => $interest->branch_id,
            'assignee' => $interest->assignee?->name,
            'assignee_id' => $interest->assignee_id,
            'customer_id' => $interest->customer_id,
            'converted_customer_id' => $interest->converted_customer_id,
            'close_reason' => $interest->close_reason?->value,
            'close_note' => $interest->close_note,
            'created_at' => $interest->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function branchOptions(User $actor): array
    {
        return array_map(fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)], CustomerAccess::creatableBranches($actor));
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function memberOptions(): array
    {
        return array_values(Membership::query()->with('user:id,name')->where('status', 'active')->orderBy('id')->get()
            ->map(fn (Membership $m) => ['id' => (int) $m->user_id, 'name' => (string) $m->user->name])->all());
    }

    /**
     * @param  LengthAwarePaginator<int, *>  $page
     * @return array{page: int, last_page: int, total: int, per_page: int, from: int|null, to: int|null}
     */
    public static function meta(LengthAwarePaginator $page): array
    {
        return [
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
            'per_page' => $page->perPage(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
        ];
    }
}
