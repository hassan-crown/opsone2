<?php

namespace App\Inbox;

use App\Access\Access;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Customers\CustomerAccess;
use App\Models\Customer;
use App\Models\Inbox\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Ties a conversation to a customer (AI-3): automatically by the WhatsApp number on the first message, or by staff for
 * an «unknown contact». The conversation then takes the customer's branch (its scope) and, when it has none yet, the
 * customer's responsible person as assignee.
 */
final class ConversationCustomers
{
    public function __construct(private readonly InboxSettings $settings) {}

    /**
     * Facts of a brand-new conversation for this number.
     *
     * @return array{customer_id: int|null, branch_id: int|null, assignee_id: int|null}
     */
    public function initialFacts(string $waId): array
    {
        $customer = Customer::query()->where('mobile', $waId)->orderBy('id')->first();
        if ($customer !== null) {
            return ['customer_id' => $customer->id, 'branch_id' => $customer->branch_id, 'assignee_id' => $customer->assignee_id];
        }

        return ['customer_id' => null, 'branch_id' => $this->settings->defaultBranchId(), 'assignee_id' => null];
    }

    /**
     * @throws AuthorizationException
     */
    public function link(User $actor, Conversation $conversation, Customer $customer): Conversation
    {
        Access::authorize($actor, Permission::P108, $conversation);
        if (! CustomerAccess::canViewCustomer($actor, $customer)) {
            throw new AuthorizationException(__('access.denied.no_grant'));
        }

        return DB::transaction(function () use ($actor, $conversation, $customer) {
            /** @var Conversation $locked */
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $before = ['customer_id' => $locked->customer_id, 'branch_id' => $locked->branch_id];
            $locked->forceFill([
                'customer_id' => $customer->id,
                'branch_id' => $customer->branch_id,
                'assignee_id' => $locked->assignee_id ?? $customer->assignee_id,
            ])->save();
            Audit::record(AuditAction::ConversationLinked, $locked, $actor, before: $before, after: ['customer_id' => $customer->id, 'branch_id' => $customer->branch_id], label: $customer->name);

            return $locked;
        });
    }

    /**
     * Model hook: a customer's branch transfer moves their conversations' scope with them.
     */
    public static function customerUpdated(Customer $customer): void
    {
        if ($customer->wasChanged('branch_id')) {
            Conversation::query()->where('customer_id', $customer->id)->update(['branch_id' => $customer->branch_id]);
        }
    }
}
