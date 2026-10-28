<?php

namespace App\Actions\Orders;

use App\Access\TransitionRecorder;
use App\Actions\StateMachines\M04CustomOrder\T00OpenCustomOrder;
use App\Actions\StateMachines\M05ReadyOrder\T00CreateReadyDraft;
use App\Models\Order;
use App\Models\PurchaseReference;
use App\Models\User;
use App\Orders\OrderAccess;
use App\Support\GaplessSequence;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens a custom order, a ready draft, or both for one sale (COM-10). Both together share a new purchase reference;
 * a single order may join an existing reference of the same customer. Everything is one transaction behind an
 * idempotency key, so a double submit returns the first result and never opens a second order or burns a number.
 */
final class CreateOrders
{
    public const IDEMPOTENCY_SCOPE = 'orders.create';

    public function __construct(
        private readonly Idempotency $idempotency,
        private readonly GaplessSequence $sequence,
        private readonly T00OpenCustomOrder $openCustom,
        private readonly T00CreateReadyDraft $createReady,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated by CreateOrderRequest
     * @return array{result: array{order_ids: list<int>, purchase_reference_id: int|null}, replayed: bool}
     */
    public function handle(User $actor, string $idempotencyKey, array $data, string $source = TransitionRecorder::SOURCE_WEB): array
    {
        /** @var array{result: array{order_ids: list<int>, purchase_reference_id: int|null}, replayed: bool} */
        return $this->idempotency->run(self::IDEMPOTENCY_SCOPE, $actor, $idempotencyKey, $data, fn () => DB::transaction(function () use ($actor, $data, $source) {
            $common = [
                'customer_id' => $data['customer_id'],
                'branch_id' => $data['branch_id'],
                'assignee_id' => $data['assignee_id'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
            ];
            $custom = $data['custom'] ?? null;
            $ready = $data['ready'] ?? null;

            $reference = $this->reference($actor, $data, $custom !== null && $ready !== null);

            $orders = [];
            if ($custom !== null) {
                $orders[] = $this->openCustom->handle($actor, [...$common, ...$custom], $reference, $source, 'custom.');
            }
            if ($ready !== null) {
                $orders[] = $this->createReady->handle($actor, [...$common, ...$ready], $reference, $source, 'ready.');
            }

            return [
                'order_ids' => array_map(fn (Order $o) => $o->id, $orders),
                'purchase_reference_id' => $reference?->id,
            ];
        }));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reference(User $actor, array $data, bool $both): ?PurchaseReference
    {
        if (isset($data['purchase_reference_id'])) {
            $reference = PurchaseReference::query()->lockForUpdate()->find((int) $data['purchase_reference_id']);
            // Joining needs sight of an order already on the reference, and the same customer.
            $visible = $reference !== null && $reference->orders()->get()->contains(fn (Order $o) => OrderAccess::canView($actor, $o));
            if (! $visible || $reference->customer_id !== (int) $data['customer_id']) {
                throw ValidationException::withMessages(['purchase_reference_id' => __('orders.validation.reference_customer')]);
            }

            return $reference;
        }

        if (! $both) {
            return null;
        }

        return PurchaseReference::query()->create([
            'number' => $this->sequence->format('purchase_references', 'PR'),
            'customer_id' => (int) $data['customer_id'],
            'created_by' => $actor->id,
        ]);
    }
}
