<?php

namespace App\Http\Controllers\Help;

use App\Ai\Help\HelpAnswer;
use App\Ai\Help\HelpAssistant;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The help center's «ask» box (ADR-0050, AI-2). Open to every signed-in staff member like the help center itself
 * (P-83); what it may answer from is decided by HelpReader inside HelpAssistant, never by this controller.
 */
class HelpAssistantController extends Controller
{
    public function ask(Request $request, HelpAssistant $assistant): JsonResponse
    {
        abort_unless($assistant->available(), 404);

        $data = $request->validate(['question' => ['required', 'string', 'min:3', 'max:'.HelpAssistant::MAX_QUESTION]]);

        $answer = $assistant->ask($request->user(), $data['question']);
        abort_if($answer->status === HelpAnswer::OFF, 404);

        return response()->json([...$answer->toArray(), 'support_url' => route('help.support', absolute: false)]);
    }
}
