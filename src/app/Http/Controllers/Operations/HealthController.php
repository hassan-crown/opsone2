<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Operations\Health\HealthCheck;
use Illuminate\Http\JsonResponse;

/**
 * Monitoring probe (ADR-0028): 200 when every dependency is ok, 503 otherwise. No session, no details.
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $checks = $health->run();
        $ok = ! in_array(false, array_map(fn (string $r) => $r === 'ok', $checks), true);

        return response()
            ->json(['status' => $ok ? 'ok' : 'fail', 'checks' => $checks], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
