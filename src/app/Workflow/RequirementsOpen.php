<?php

namespace App\Workflow;

use App\Access\Exceptions\InvalidTransition;
use App\Enums\StateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Completing a stage (M-06-T04) while required, applicable actions are neither verified nor allowed. Carries the
 * open action keys so the screen can offer «السماح بالمتابعة» (P-35) for exactly those.
 */
final class RequirementsOpen extends InvalidTransition
{
    /**
     * @param  list<string>  $open
     */
    public function __construct(public readonly array $open)
    {
        parent::__construct(StateMachine::M06, 'T04', 'requirements_open');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => __('workflow.stage.requirements_open'), 'condition' => $this->condition, 'open' => $this->open], 409);
        }

        return back()->withErrors(['transition' => __('workflow.stage.requirements_open')]);
    }
}
