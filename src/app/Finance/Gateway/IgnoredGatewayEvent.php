<?php

namespace App\Finance\Gateway;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A verified notification that settles nothing yet (e.g. a declined card while the hosted invoice can still be paid,
 * or a payment still in progress): acknowledged so the provider stops retrying, nothing written.
 */
class IgnoredGatewayEvent extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['received' => true, 'ignored' => true]);
    }
}
