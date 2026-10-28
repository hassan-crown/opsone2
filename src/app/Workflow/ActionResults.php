<?php

namespace App\Workflow;

use App\Enums\ActionStatus;
use App\Enums\StateMachine;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\StateTransition;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\Catalogue\ActionType;
use App\Workflow\Catalogue\CompletionMode;
use LogicException;

/**
 * Entry point for the domains that own a result (P6 agreement and finance, P7 supply and delivery): they report the
 * fact from their own record and the engine re-checks the matching action (M-27-T03). The engine never creates a
 * payment, approval or delivery itself, and a repeated signal returns the known result without a second effect.
 */
final class ActionResults
{
    public function __construct(private readonly StageEngine $engine) {}

    /**
     * @param  string|null  $purpose  the action's distinguishing input: payment/supply `reference`, approval `subject`,
     *                                document `document`, required document `document_type`; null for a delivery
     * @return OrderAction|null the matched action, or null when the order's flow has no such action
     */
    public function verifyFromSource(Order $order, ActionType $type, ?string $purpose, string $sourceType, int|string $sourceId, ?User $actor = null): ?OrderAction
    {
        if ($type->completion() !== CompletionMode::Source) {
            throw new LogicException("{$type->value} is verified by its task, not by a source record.");
        }

        return LockingTransaction::run(function () use ($order, $type, $purpose, $sourceType, $sourceId, $actor) {
            $order = WorkflowLock::order($order->id);
            $source = ['type' => $sourceType, 'id' => $sourceId];

            $action = OrderAction::query()->where('order_id', $order->id)->where('type', $type->value)->get()
                ->first(fn (OrderAction $a) => $type->referenceOf($a->inputs ?? []) === $type->referenceOf(self::inputsFor($type, $purpose)));
            if ($action === null) {
                return null;
            }

            // Same signal again: the known result, nothing new.
            if ($action->source_reference === $source) {
                return $action;
            }

            // A result taken back by a rework (M-27-T05) is the old content: a replay of it never verifies again.
            if (self::reworkedAway($action, $source)) {
                return $action;
            }

            match ($action->status) {
                ActionStatus::Ready, ActionStatus::AwaitingResult => $this->engine->verify($order, $action, $actor, $source),
                // Recorded early or under an allowance: keep the truth; it applies when the action opens (T01→T03).
                ActionStatus::Unavailable, ActionStatus::Allowed, ActionStatus::NotRequired => $action->forceFill(['source_reference' => $source])->save(),
                default => null,
            };

            WorkflowLock::touch($order);

            return $action->refresh();
        });
    }

    /**
     * @param  array{type: string, id: int|string}  $source
     */
    private static function reworkedAway(OrderAction $action, array $source): bool
    {
        return StateTransition::query()->where('machine', StateMachine::M27->value)->where('transition', 'T05')
            ->where('subject_type', $action->getMorphClass())->where('subject_id', $action->id)->get()
            ->contains(function (StateTransition $t) use ($source) {
                $previous = $t->context['previous_source'] ?? null;

                return is_array($previous) && ($previous['type'] ?? null) === $source['type']
                    && (string) ($previous['id'] ?? '') === (string) $source['id'];
            });
    }

    /**
     * @return array<string, mixed>
     */
    private static function inputsFor(ActionType $type, ?string $purpose): array
    {
        return match ($type) {
            ActionType::PaymentCheck, ActionType::SupplyFollowUp => ['reference' => $purpose],
            ActionType::CustomerApproval => ['subject' => $purpose],
            ActionType::PrepareDocument => ['document' => $purpose],
            default => [],
        };
    }
}
