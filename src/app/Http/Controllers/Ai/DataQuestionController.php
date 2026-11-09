<?php

namespace App\Http\Controllers\Ai;

use App\Ai\Insights\DataAnswer;
use App\Ai\Insights\DataQuestion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AskDataQuestionRequest;
use Illuminate\Http\JsonResponse;

/**
 * «اسأل بياناتك» (ADR-0050, AI-8). The endpoint is for any signed-in user; DataQuestion refuses (404) unless the
 * facility turned the feature on, the license has `ai_plus` and the user may open at least one report.
 */
class DataQuestionController extends Controller
{
    public function ask(AskDataQuestionRequest $request, DataQuestion $question): JsonResponse
    {
        abort_unless($question->available($request->user()), 404);

        $answer = $question->ask($request->user(), $request->validated('question'));
        abort_if($answer->status === DataAnswer::OFF, 404);

        return response()->json($answer->toArray());
    }
}
