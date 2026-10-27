<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An optimistic-lock conflict: the client wrote against a version that someone else changed meanwhile. Thrown
 * before any write. The screen reloads and shows the newer record (`OrderStale`).
 */
class StaleRecord extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct('The record changed since it was read.');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('orders.stale'), 'code' => 'stale_version', 'lock_version' => $this->currentVersion,
            ], 409);
        }

        return back()->withErrors(['lock_version' => __('orders.stale')]);
    }
}
