<?php

namespace App\Access\Exceptions;

use App\Enums\StateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A transition that is not defined from the current state, or whose conditions do not hold.
 * Thrown before any write, so the record keeps its state (no partial effect).
 */
class InvalidTransition extends RuntimeException
{
    public function __construct(
        public readonly StateMachine $machine,
        public readonly string $transition,
        public readonly string $condition,
    ) {
        parent::__construct("{$machine->value}-{$transition} rejected: {$condition}");
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => __('access.denied.transition'), 'condition' => $this->condition], 409);
        }

        return back()->withErrors(['transition' => __('access.denied.transition')]);
    }
}
