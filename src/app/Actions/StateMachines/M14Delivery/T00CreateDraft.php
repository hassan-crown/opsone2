<?php

namespace App\Actions\StateMachines\M14Delivery;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Delivery\DeliveryProgress;
use App\Delivery\DeliveryWrite;
use App\Enums\DeliveryStatus;
use App\Enums\StateMachine;
use App\Inventory\OrderStock;
use App\Inventory\Quantity;
use App\Models\Delivery\DeliveryNote;
use App\Models\Delivery\DeliveryNoteLine;
use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * M-14 draft (P-52): the lines and actual quantities about to be handed over. A draft reserves nothing and takes no
 * number; the quantities are checked again under the order lock when the note is confirmed. Once per idempotency key.
 */
final class T00CreateDraft
{
    public function __construct(private readonly DeliveryWrite $write, private readonly TransitionRecorder $transitions) {}

    /**
     * @param  list<array{order_line_id: int, quantity_milli: int}>  $lines
     */
    public function handle(User $actor, Order $order, array $lines, ?string $note, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): DeliveryNote
    {
        $payload = ['order' => $order->id, 'lines' => $lines, 'note' => $note];

        $result = $this->write->run('draft', $actor, $idempotencyKey, $payload, function () use ($actor, $order, $lines, $note, $source) {
            $locked = OrderStock::lockOrder($order->id);
            Access::authorize($actor, Permission::P52, $locked);
            if (! DeliveryProgress::accepts($locked)) {
                throw new InvalidTransition(StateMachine::M14, 'T00', 'order_'.$locked->status);
            }
            $rows = DeliveryProgress::lines($locked);
            $picked = self::pick($lines, $rows);

            $draft = DeliveryNote::query()->create(['order_id' => $locked->id, 'status' => DeliveryStatus::Draft, 'note' => $note, 'created_by' => $actor->id]);
            foreach ($picked as $lineId => $milli) {
                DeliveryNoteLine::query()->create(['delivery_note_id' => $draft->id, 'order_line_id' => $lineId, 'quantity_milli' => $milli]);
            }

            $this->transitions->record(StateMachine::M14, 'T00', $draft, null, DeliveryStatus::Draft, $actor, $source, null, ['order' => $locked->number]);
            Audit::record(AuditAction::DeliveryDrafted, $draft, $actor, after: ['order' => $locked->number, 'lines' => count($picked)], source: $source, branchIds: [$locked->branch_id], label: $locked->number);

            return ['note_id' => $draft->id];
        });

        return DeliveryNote::query()->findOrFail((int) $result['result']['note_id']);
    }

    /**
     * Validates the lines against what is deliverable now and returns line id → quantity.
     *
     * @param  list<array{order_line_id: int, quantity_milli: int}>  $input
     * @param  array<int, array{line: mixed, ordered: int, delivered: int, released: int, deliverable: int}>  $rows
     * @return array<int, int>
     */
    public static function pick(array $input, array $rows): array
    {
        $picked = [];
        foreach ($input as $i => $row) {
            $field = 'lines.'.$i;
            $lineId = (int) $row['order_line_id'];
            $milli = (int) $row['quantity_milli'];
            if (! isset($rows[$lineId]) || isset($picked[$lineId])) {
                throw ValidationException::withMessages([$field.'.order_line_id' => __('delivery.error.line_unknown')]);
            }
            if ($milli < 0) {
                throw ValidationException::withMessages([$field.'.quantity' => __('delivery.error.quantity')]);
            }
            if ($milli === 0) {
                continue;
            }
            if ($milli > $rows[$lineId]['deliverable']) {
                throw ValidationException::withMessages([$field.'.quantity' => __('delivery.error.over_deliverable', ['remaining' => Quantity::format($rows[$lineId]['deliverable'])])]);
            }
            $picked[$lineId] = $milli;
        }
        if ($picked === []) {
            throw ValidationException::withMessages(['lines' => __('delivery.error.nothing_delivered')]);
        }

        return $picked;
    }
}
