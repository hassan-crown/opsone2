<?php

namespace App\Actions\Orders;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Orders\OrderLineBuilder;
use App\Orders\OrderLock;
use Illuminate\Support\Facades\DB;

/**
 * P-17: add a line (its specification frozen now) or remove one (kept in history) while the order is editable.
 * Ready lines also need `ready_goods` (the Gate asks the order). Each change bumps the order's lock version.
 */
final class ManageOrderLines
{
    public function __construct(
        private readonly Authorizer $access,
        private readonly OrderLineBuilder $builder,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function add(User $actor, Order $order, int $lockVersion, array $input, string $source = TransitionRecorder::SOURCE_WEB): OrderLine
    {
        $this->access->authorize($actor, Permission::P17, $order);

        return DB::transaction(function () use ($actor, $order, $lockVersion, $input, $source) {
            $locked = OrderLock::fresh($order, $lockVersion);
            $this->access->authorize($actor, Permission::P17, $locked);

            $position = (int) $locked->lines()->max('position') + 1;
            $line = $this->builder->build($locked, $input, $actor, $position);
            $line->save();

            $locked->total_minor = OrderLineBuilder::total($locked);
            $locked->lock_version++;
            $locked->save();

            Audit::record(AuditAction::OrderLineAdded, $locked, $actor, after: [
                'line_id' => $line->id, 'type' => $line->line_type, 'catalog_version_id' => $line->catalog_version_id, 'quantity' => $line->quantity,
            ], source: $source, label: $locked->number);

            return $line;
        });
    }

    public function remove(User $actor, Order $order, OrderLine $line, int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): void
    {
        abort_unless($line->order_id === $order->id, 404);
        $this->access->authorize($actor, Permission::P17, $order);

        DB::transaction(function () use ($actor, $order, $line, $lockVersion, $source) {
            $locked = OrderLock::fresh($order, $lockVersion);
            $this->access->authorize($actor, Permission::P17, $locked);

            $fresh = OrderLine::query()->lockForUpdate()->findOrFail($line->id);
            if ($fresh->isRemoved()) {
                return;
            }
            $fresh->forceFill(['removed_at' => now(), 'removed_by' => $actor->id])->save();

            $locked->total_minor = OrderLineBuilder::total($locked);
            $locked->lock_version++;
            $locked->save();

            Audit::record(AuditAction::OrderLineRemoved, $locked, $actor, before: ['line_id' => $fresh->id, 'quantity' => $fresh->quantity], source: $source, label: $locked->number);
        });
    }
}
