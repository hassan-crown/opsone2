<?php

namespace App\Workflow;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A 422 that also carries FlowCheck's structured issues.
 */
final class FlowCheckFailed extends ValidationException
{
    public function __construct(public readonly FlowCheckResult $result, Validator $validator)
    {
        parent::__construct($validator);
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'message' => __('workflow.check.failed'),
            'errors' => $this->errors(),
            'issues' => $this->result->issues,
        ], 422);
    }
}
