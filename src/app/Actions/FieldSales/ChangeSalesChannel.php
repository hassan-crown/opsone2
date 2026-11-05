<?php

namespace App\Actions\FieldSales;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\SalesChannel;
use App\FieldSales\FieldReps;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderLock;
use App\Support\LockingTransaction;
use Illuminate\Validation\ValidationException;

/**
 * Corrects where an order was sold and its field seller (P-106 over the order, ADR-0041), audited before/after. A
 * `field_sales` order names a rep who covers its branch; any other channel has no seller. A commission sale already
 * pinned at the first agreement keeps its owner — moving it is a sale correction (P-98, ADR-0016).
 */
final class ChangeSalesChannel
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer'],
            'sales_channel' => ['required', 'in:'.implode(',', SalesChannel::values())],
            'seller_id' => ['nullable', 'integer'],
        ];
    }

    public function handle(User $actor, Order $order, int $lockVersion, SalesChannel $channel, ?int $sellerId, string $source = TransitionRecorder::SOURCE_WEB): Order
    {
        Access::authorize($actor, Permission::P106, $order);

        return LockingTransaction::run(function () use ($actor, $order, $lockVersion, $channel, $sellerId, $source) {
            $locked = OrderLock::fresh($order, $lockVersion);
            Access::authorize($actor, Permission::P106, $locked);
            if ($channel === SalesChannel::FieldSales) {
                $seller = $sellerId === null ? null : User::query()->find($sellerId);
                if ($seller === null || ! FieldReps::covers($seller, $locked->branch_id)) {
                    throw ValidationException::withMessages(['seller_id' => __('field_sales.validation.rep')]);
                }
            } else {
                $sellerId = null;
            }
            $before = ['sales_channel' => $locked->sales_channel->value, 'seller_id' => $locked->seller_id];
            if ($before === ['sales_channel' => $channel->value, 'seller_id' => $sellerId]) {
                return $locked;
            }
            $locked->forceFill(['sales_channel' => $channel, 'seller_id' => $sellerId, 'lock_version' => $locked->lock_version + 1])->save();
            Audit::record(AuditAction::OrderSalesChannelChanged, $locked, $actor, before: $before,
                after: ['sales_channel' => $channel->value, 'seller_id' => $sellerId], source: $source, label: $locked->number);

            return $locked;
        });
    }
}
