<?php

namespace App\Inbox;

use App\Access\Access;
use App\Access\Permission;
use App\Inbox\Jobs\SendConversationMessage;
use App\Models\Inbox\Conversation;
use App\Models\Inbox\ConversationMessage;
use App\Models\User;
use App\Whatsapp\Inbox\ConversationTransport;
use App\Whatsapp\MessageStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Queues a staff reply in a conversation (AI-3). Server-side rules, whatever the composer showed:
 * P-108 over the conversation; the inbox switched on and the provider configured; the customer not opted out
 * (STOP, ADR-0030); inside Meta's 24-hour window free text, outside it only a template the owner listed as approved,
 * with exactly its number of parameters. A repeated submit with the same key returns the first message.
 */
final class ReplySender
{
    public function __construct(
        private readonly InboxSettings $settings,
        private readonly ConversationTransport $transport,
    ) {}

    /**
     * @throws AuthorizationException|ValidationException
     */
    public function text(User $actor, Conversation $conversation, string $body, string $key): ConversationMessage
    {
        $this->guard($actor, $conversation);
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => __('validation.required', ['attribute' => 'body'])]);
        }

        return $this->queue($actor, $conversation, $key, function (Conversation $locked) use ($body) {
            if (! $locked->windowOpen()) {
                throw ValidationException::withMessages(['body' => __('inbox.errors.window_closed')]);
            }

            return ['type' => 'text', 'body' => $body];
        });
    }

    /**
     * @param  list<string>  $params
     *
     * @throws AuthorizationException|ValidationException
     */
    public function template(User $actor, Conversation $conversation, string $name, string $language, array $params, string $key): ConversationMessage
    {
        $this->guard($actor, $conversation);
        $template = $this->settings->template($name, $language);
        if ($template === null) {
            throw ValidationException::withMessages(['template' => __('inbox.errors.template_unknown')]);
        }
        $params = array_map(fn (string $p) => trim($p), $params);
        if (count($params) !== $template['params'] || in_array('', $params, true)) {
            throw ValidationException::withMessages(['params' => __('inbox.errors.template_params', ['count' => $template['params']])]);
        }

        $body = json_encode(['params' => $params], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->queue($actor, $conversation, $key, fn (Conversation $locked) => [
            'type' => 'template',
            'template' => $template['name'],
            'template_language' => $template['language'],
            'body' => $body,
        ]);
    }

    /**
     * @throws AuthorizationException|ValidationException
     */
    private function guard(User $actor, Conversation $conversation): void
    {
        if (! $this->settings->enabled()) {
            abort(404);
        }
        Access::authorize($actor, Permission::P108, $conversation);
        if (! $this->transport->isConfigured()) {
            throw ValidationException::withMessages(['body' => __('inbox.errors.not_configured')]);
        }
        if ($conversation->customer?->whatsapp_opt_out_at !== null) {
            throw ValidationException::withMessages(['body' => __('inbox.errors.opted_out')]);
        }
    }

    /**
     * @param  callable(Conversation): array<string, string>  $content
     */
    private function queue(User $actor, Conversation $conversation, string $key, callable $content): ConversationMessage
    {
        $existing = ConversationMessage::query()->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            if ($existing->conversation_id !== $conversation->id) {
                throw ValidationException::withMessages(['key' => __('inbox.errors.duplicate_key')]);
            }

            return $existing;
        }

        $message = DB::transaction(function () use ($actor, $conversation, $key, $content) {
            /** @var Conversation $locked */
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $now = now();
            $message = $locked->messages()->create([
                ...$content($locked),
                'direction' => ConversationMessage::OUT,
                'idempotency_key' => $key,
                'status' => MessageStatus::Queued->value,
                'sent_by' => $actor->id,
                'occurred_at' => $now,
            ]);
            $locked->forceFill(['last_message_at' => $now, 'unread_count' => 0])->save();

            return $message;
        });

        SendConversationMessage::dispatch($message->id);

        return $message->refresh();
    }
}
