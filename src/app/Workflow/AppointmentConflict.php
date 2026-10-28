<?php

namespace App\Workflow;

use App\Access\Exceptions\InvalidTransition;
use App\Enums\StateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A confirmation (M-16-T02) or approved change (T04) overlapping a confirmed appointment of the same member, refused
 * because the policy is «block» (`conflict_blocked`) or no reason was given under «allow with reason»
 * (`conflict_reason_required`). Lists only the clashing appointment ids and times for the P-54 holder.
 */
final class AppointmentConflict extends InvalidTransition
{
    /**
     * @param  list<array{id: int, starts_at: string, ends_at: string, member_ids: list<int>}>  $conflicts
     */
    public function __construct(string $transition, string $condition, public readonly array $conflicts)
    {
        parent::__construct(StateMachine::M16, $transition, $condition);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('workflow.appointment.'.$this->condition),
                'condition' => $this->condition,
                'conflicts' => $this->conflicts,
            ], 409);
        }

        return back()->withErrors(['starts_at' => __('workflow.appointment.'.$this->condition)]);
    }
}
