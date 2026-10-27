<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    public const MODES = ['light', 'dark', 'contrast', 'system'];

    /**
     * Share the stored theme preference (cookie `appearance`) with the root view.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appearance = $request->cookie('appearance');

        View::share('appearance', in_array($appearance, self::MODES, true) ? $appearance : 'system');

        return $next($request);
    }
}
