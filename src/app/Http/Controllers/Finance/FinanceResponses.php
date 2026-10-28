<?php

namespace App\Http\Controllers\Finance;

use App\Finance\FinancePresenter;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Finance writes answer with the refreshed order money payload (`{finance: Payload}`), like the agreement screens.
 */
trait FinanceResponses
{
    /**
     * @param  array<string, mixed>  $extra
     */
    private function respond(Request $request, Order $order, array $extra = [], int $status = 200): JsonResponse
    {
        return response()->json([...$extra, 'finance' => FinancePresenter::forOrder($order->refresh(), $this->actor($request))], $status);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
