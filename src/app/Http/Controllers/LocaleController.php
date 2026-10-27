<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * Persist the chosen UI language (cookie, plus the user's profile when signed in).
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(config('app.available_locales'))],
        ]);

        $request->user()?->forceFill(['locale' => $validated['locale']])->save();

        return back()->withCookie(Cookie::make('locale', $validated['locale'], 60 * 24 * 365, httpOnly: false));
    }
}
