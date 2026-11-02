<?php

namespace App\Actions\Rules;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Messages\MessageCatalogue;
use App\Messages\MessageTemplates;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Support\RequestCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Rewords one event's message on one channel and language, or resets it to the built-in text (P-103, ADR-0037). Only
 * catalogue events and their channels; placeholders only from the event's variables. It applies to messages queued
 * from now on; queued and sent ones keep their text.
 */
final class SaveMessageTemplate
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'event' => ['required', 'string', 'max:48'],
            'channel' => ['required', 'in:'.implode(',', MessageCatalogue::CHANNELS)],
            'locale' => ['required', 'in:'.implode(',', MessageCatalogue::LOCALES)],
            'subject' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, string $source = TransitionRecorder::SOURCE_WEB): MessageTemplate
    {
        RulesAccess::authorize($actor);
        $data = Validator::make($data, self::rules())->validate();
        $definition = self::definition($data['event'], $data['channel']);
        $subject = isset($data['subject']) && trim((string) $data['subject']) !== '' ? trim((string) $data['subject']) : null;
        $body = trim((string) $data['body']);
        MessageTemplates::checkVariables($body, $definition['variables'], 'body');
        if ($subject !== null) {
            MessageTemplates::checkVariables($subject, $definition['variables'], 'subject');
        }

        return DB::transaction(function () use ($actor, $data, $subject, $body, $source) {
            $template = MessageTemplate::query()->lockForUpdate()
                ->firstOrNew(['event' => $data['event'], 'channel' => $data['channel'], 'locale' => $data['locale']]);
            $before = $template->exists ? ['subject' => $template->subject, 'body' => $template->body] : [];
            $template->forceFill(['subject' => $subject, 'body' => $body, 'updated_by' => $actor->id])->save();
            Audit::record(AuditAction::MessageTemplateSaved, $template, $actor, before: $before, after: ['subject' => $subject, 'body' => $body],
                source: $source, label: "{$data['event']} · {$data['channel']} · {$data['locale']}");
            app(RequestCache::class)->flush();

            return $template;
        });
    }

    public function reset(User $actor, string $event, string $channel, string $locale, string $source = TransitionRecorder::SOURCE_WEB): void
    {
        RulesAccess::authorize($actor);
        self::definition($event, $channel);

        DB::transaction(function () use ($actor, $event, $channel, $locale, $source) {
            $template = MessageTemplate::query()->lockForUpdate()->where(['event' => $event, 'channel' => $channel, 'locale' => $locale])->first();
            if ($template === null) {
                return;
            }
            Audit::record(AuditAction::MessageTemplateReset, $template, $actor, before: ['subject' => $template->subject, 'body' => $template->body],
                source: $source, label: "{$event} · {$channel} · {$locale}");
            $template->delete();
            app(RequestCache::class)->flush();
        });
    }

    /**
     * @return array{channels: list<string>, audience: string, body: string, subject: string|null, variables: list<string>}
     */
    private static function definition(string $event, string $channel): array
    {
        $definition = MessageCatalogue::get($event);
        if ($definition === null || ! in_array($channel, $definition['channels'], true)) {
            throw ValidationException::withMessages(['event' => __('rules.messages.validation.event')]);
        }

        return $definition;
    }
}
