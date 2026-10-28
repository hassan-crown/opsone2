<?php

namespace App\Finance\Gateway;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The provider could not be reached or refused the request; nothing is assumed about the payment (M-05-T04). A
 * webhook that cannot be re-read answers 503 so the provider retries it.
 */
class GatewayUnavailable extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => 'provider unavailable'], 503);
    }
}
