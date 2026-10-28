<?php

namespace App\Finance\Gateway;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A provider notification that could not be verified: ignored, nothing written.
 */
class InvalidGatewaySignature extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => 'invalid signature'], 400);
    }
}
