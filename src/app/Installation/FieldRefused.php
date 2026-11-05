<?php

namespace App\Installation;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A field step that does not apply now (already arrived, photos missing, not signed…). Thrown before any write.
 * JSON callers get 409 `{message, condition}`; page callers go back with the message under `field`.
 */
class FieldRefused extends RuntimeException
{
    public function __construct(public readonly string $condition)
    {
        parent::__construct("installation field step refused: {$condition}");
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $message = __('installation.refused.'.$this->condition);
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'condition' => $this->condition], 409);
        }

        return back()->withErrors(['field' => $message]);
    }
}
