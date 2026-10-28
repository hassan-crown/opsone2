<?php

namespace App\Http\Controllers\Inventory;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shared answers of the stock controllers: a screen as Inertia (or JSON for API callers), the signed-in actor.
 */
trait StockScreens
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function screen(Request $request, string $component, array $payload): Response|JsonResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($payload);
        }

        return Inertia::render($component, $payload);
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
