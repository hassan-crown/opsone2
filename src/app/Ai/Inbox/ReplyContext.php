<?php

namespace App\Ai\Inbox;

use App\Access\Access;
use App\Access\Permission;
use App\Customers\CustomerAccess;
use App\Enums\CustomOrderStatus;
use App\Enums\ReadyOrderStatus;
use App\Models\Catalog\CatalogProduct;
use App\Models\Facility;
use App\Models\Inbox\Conversation;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderAccess;
use App\Portal\PortalPresenter;

/**
 * The trusted facts of a reply suggestion, built with the grants of the staff member it is for (plan §3.2, ADR-0050
 * §2): never more than that person could open themselves.
 *
 * - The customer's first name, only when they may open the customer (P-09/P-10 over it).
 * - The customer's open orders they may open (P-16, scoped by branch/assignee), with the stage the customer sees.
 * - The money of an order (agreed, remaining, due now, next due) only with P-37 over that order.
 * - Catalog reference prices only with P-11.
 *
 * Facts are a flat map of named fields (`order.1.remaining_halalas`) so the validators can tie every number in the
 * draft to one of them, and the screen can show which ones the draft used. The customer's own messages are NOT facts.
 */
final class ReplyContext
{
    private const MAX_ORDERS = 5;

    private const MAX_PRODUCTS = 30;

    /**
     * @param  array<string, string|int|bool|null>  $facts
     * @param  array<string, list<string>>  $known
     */
    private function __construct(public readonly array $facts, public readonly array $known) {}

    public static function for(User $user, Conversation $conversation): self
    {
        $facts = [];
        $known = [];

        $facility = Facility::current();
        if ($facility !== null) {
            $facts['facility.name'] = $facility->name_ar;
        }

        $customer = $conversation->customer;
        if ($customer !== null && CustomerAccess::canViewCustomer($user, $customer)) {
            $name = trim((string) $customer->name);
            $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($parts !== []) {
                $facts['customer.first_name'] = $parts[0];
            }
            if (count($parts) > 1) {
                // The full name never leaves; the first name stays for the greeting (plan §3.5).
                $known['NAME'] = [$name];
            }

            $closed = [CustomOrderStatus::Cancelled->value, CustomOrderStatus::Closed->value, ReadyOrderStatus::Delivered->value];
            $orders = OrderAccess::visible($user)->where('orders.customer_id', $customer->id)
                ->whereNotIn('orders.status', $closed)
                ->orderByDesc('orders.id')->limit(self::MAX_ORDERS)->get();

            foreach (PortalPresenter::orders($orders) as $row) {
                $prefix = 'order.'.$row['id'];
                $facts[$prefix.'.number'] = (string) $row['number'];
                $facts[$prefix.'.stage_public'] = $row['stage'];
                $facts[$prefix.'.created_on'] = (string) $row['created_on'];

                /** @var Order $order */
                $order = $orders->firstWhere('id', $row['id']);
                if (OrderAccess::canSeeFinancials($user, $order)) {
                    $money = PortalPresenter::finance($order);
                    $facts[$prefix.'.agreed_halalas'] = $money['agreed_minor'];
                    $facts[$prefix.'.remaining_halalas'] = $money['remaining_minor'];
                    $facts[$prefix.'.due_now_halalas'] = $money['due_now_minor'];
                    foreach ($money['dues'] as $due) {
                        if ($due['remaining_minor'] > 0 && $due['due_on'] !== null) {
                            $facts[$prefix.'.next_due_on'] = (string) $due['due_on'];
                            $facts[$prefix.'.next_due_halalas'] = $due['remaining_minor'];
                            break;
                        }
                    }
                }
            }
        }

        if (Access::canRead($user, Permission::P11)) {
            $products = CatalogProduct::query()->where('status', 'active')->whereNull('disabled_at')
                ->whereNotNull('reference_price_minor')->orderBy('id')->limit(self::MAX_PRODUCTS)->get();
            foreach ($products as $product) {
                $facts['product.'.$product->id.'.name'] = (string) $product->name->get('ar');
                $facts['product.'.$product->id.'.reference_price_halalas'] = $product->reference_price_minor;
            }
        }

        return new self(array_filter($facts, fn ($v) => $v !== null && $v !== ''), $known);
    }
}
