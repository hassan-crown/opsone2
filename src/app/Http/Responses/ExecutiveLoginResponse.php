<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Reports\Dashboard\ExecutiveDashboard;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse;

/**
 * After sign-in, whoever may open the owner dashboard (owner, general manager, branch manager) lands on it; everyone
 * else keeps Fortify's default home. An intended URL (a deep link they were sent to the login from) still wins.
 */
class ExecutiveLoginResponse implements LoginResponse
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $user = $request->user();
        $home = $user instanceof User && ExecutiveDashboard::canView($user) ? route('executive', absolute: false) : config('fortify.home');

        return redirect()->intended($home);
    }
}
