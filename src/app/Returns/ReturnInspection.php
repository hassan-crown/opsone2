<?php

namespace App\Returns;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Enums\StateMachine;
use App\Inventory\MovementKind;
use App\Inventory\Quantity;
use App\Models\Returns\OrderReturn;
use App\Models\Returns\OrderReturnPart;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Records the inspection of one received part, once (M-15-T02): good + damaged + rejected must equal the received
 * quantity; the good quantity is posted back to the part's location, damaged gets a custody row, rejected moves
 * nothing. The inspector needs P-53 on the order (checked by the caller) and on the part's location.
 */
final class ReturnInspection
{
    public function __construct(private readonly ReturnStock $stock) {}

    /**
     * @param  array{good_milli: int, damaged_milli: int, rejected_milli: int}  $result
     */
    public function apply(User $actor, OrderReturn $return, OrderReturnPart $part, array $result, ?string $note, string $field, string $transition): void
    {
        Access::authorize($actor, Permission::P53, $part->location);
        if ($part->isInspected()) {
            throw new InvalidTransition(StateMachine::M15, $transition, 'already_inspected');
        }
        foreach (['good_milli', 'damaged_milli', 'rejected_milli'] as $key) {
            if ($result[$key] < 0) {
                throw ValidationException::withMessages([$field => __('returns.error.quantity')]);
            }
        }
        if ($result['good_milli'] + $result['damaged_milli'] + $result['rejected_milli'] !== $part->quantity_milli) {
            throw ValidationException::withMessages([$field => __('returns.error.inspection_sum', ['received' => Quantity::format($part->quantity_milli)])]);
        }

        $part->forceFill([...$result, 'inspection_note' => $note, 'inspected_by' => $actor->id, 'inspected_at' => now()])->save();
        $this->stock->post(MovementKind::ReturnIn, $return, $part, $result['good_milli'], $actor, 'return:'.$part->id.':good', $note);
        $this->stock->post(MovementKind::ReturnDamaged, $return, $part, $result['damaged_milli'], $actor, 'return:'.$part->id.':damaged', $note);
    }
}
