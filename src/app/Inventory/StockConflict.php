<?php

namespace App\Inventory;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A stock record (count) that is not in a state allowing the action. Thrown before any write; 409 with a condition,
 * like App\Access\Exceptions\InvalidTransition for the state machines.
 */
final class StockConflict extends RuntimeException
{
    public function __construct(public readonly string $condition)
    {
        parent::__construct("Stock action rejected: {$condition}");
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => __('access.denied.transition'), 'condition' => $this->condition], 409);
        }

        return back()->withErrors(['transition' => __('access.denied.transition')]);
    }
}
