<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Whatsapp\WhatsAppWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Meta's WhatsApp webhook (ADR-0030): no session, no CSRF — the body's HMAC signature is the authentication. A body
 * with a missing or wrong signature is refused (403) before it is parsed.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request, WhatsAppWebhook $webhook): Response
    {
        $challenge = $webhook->challenge($request);

        return $challenge === null
            ? response('', 403)
            : response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, WhatsAppWebhook $webhook): JsonResponse
    {
        if (! $webhook->validSignature($request)) {
            return response()->json(['message' => 'invalid signature'], 403);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'invalid body'], 400);
        }

        return response()->json(['received' => true, ...$webhook->apply($payload)]);
    }
}
