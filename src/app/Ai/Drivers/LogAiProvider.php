<?php

namespace App\Ai\Drivers;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\AiProvider;
use Illuminate\Support\Facades\Log;

/**
 * Default driver: nothing leaves the server. It records that a call would have been made — task, model and sizes
 * only, never the prompt (which may still hold first names or business data) — and answers «not sent», so the
 * feature falls back to its manual path.
 */
final class LogAiProvider implements AiProvider
{
    public function name(): string
    {
        return 'log';
    }

    public function complete(AiRequest $request): AiResponse
    {
        Log::info('AI request not sent (log driver)', [
            'task' => $request->task->value,
            'model' => $request->model,
            'system_chars' => mb_strlen($request->system),
            'content_chars' => mb_strlen($request->content),
        ]);

        return AiResponse::notSent($request->model);
    }
}
