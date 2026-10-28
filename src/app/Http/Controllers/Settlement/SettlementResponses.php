<?php

namespace App\Http\Controllers\Settlement;

use App\Finance\FinancePresenter;
use App\Finance\Settlement\SettlementPresenter;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settlement writes answer with the refreshed order money and settlement payloads (`{finance, settlement}`).
 */
trait SettlementResponses
{
    /**
     * @param  array<string, mixed>  $extra
     */
    private function respond(Request $request, Order $order, array $extra = [], int $status = 200): JsonResponse
    {
        $order->refresh();
        $actor = $this->actor($request);

        return response()->json([
            ...$extra,
            'finance' => FinancePresenter::forOrder($order, $actor),
            'settlement' => SettlementPresenter::forOrder($order, $actor),
        ], $status);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
