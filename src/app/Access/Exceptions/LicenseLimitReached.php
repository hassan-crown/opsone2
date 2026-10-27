<?php

namespace App\Access\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Adding a seat, an active branch or stored file bytes would exceed the license (or no limit is proven). Thrown before any write.
 */
class LicenseLimitReached extends RuntimeException
{
    /**
     * @param  'seats'|'branches'|'storage'  $resource
     */
    public function __construct(
        public readonly string $resource,
        public readonly int $used,
        public readonly ?int $limit,
    ) {
        parent::__construct("License limit reached for {$resource}: {$used} of ".($limit ?? 'unproven'));
    }

    public function userMessage(): string
    {
        return $this->limit === null
            ? __('license.limit.unproven')
            : __('license.limit.'.$this->resource, ['used' => $this->used, 'limit' => $this->limit]);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->userMessage(),
                'resource' => $this->resource,
                'used' => $this->used,
                'limit' => $this->limit,
            ], 409);
        }

        return back()->withErrors(['license' => $this->userMessage()]);
    }
}
