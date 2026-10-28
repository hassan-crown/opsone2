<?php

namespace App\Http\Controllers\Finance;

use App\Finance\Gateway\GatewayPayments;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provider notifications (FIN-07). The gateway verifies the signature before anything is read; an unknown gateway
 * is 404 (none in v1), an untrusted notification 400, a known one applied once (a replay answers the same).
 */
class GatewayWebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, GatewayPayments $payments): JsonResponse
    {
        $payment = $payments->applyWebhook($gateway, $request);

        return response()->json(['received' => true, 'status' => $payment?->status->value]);
    }
}
