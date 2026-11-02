<?php

namespace App\Messages;

use App\Models\MessageTemplate;
use App\Support\RequestCache;
use Illuminate\Validation\ValidationException;

/**
 * Renders a message from the facility's wording when it has one, else from the built-in text (ADR-0037). A facility
 * text uses `{variable}` placeholders from the event's closed list only — anything else is refused when saved — and
 * values are inserted as plain text (no markup reaches SMS or mail). Communication follows the latest wording: it is
 * never pinned to an order.
 */
final class MessageTemplates
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function body(string $event, string $channel, string $locale, array $params, string $fallbackKey): string
    {
        $template = self::find($event, $channel, $locale);

        return $template === null ? __($fallbackKey, $params, $locale) : self::fill($template->body, $params);
    }

    /**
     * The mail subject or in-app title, or null to use the caller's built-in one.
     *
     * @param  array<string, scalar|null>  $params
     */
    public static function subject(string $event, string $channel, string $locale, array $params): ?string
    {
        $subject = self::find($event, $channel, $locale)?->subject;

        return $subject === null || trim($subject) === '' ? null : self::fill($subject, $params);
    }

    public static function find(string $event, string $channel, string $locale): ?MessageTemplate
    {
        $all = app(RequestCache::class)->remember('message_templates', fn () => MessageTemplate::query()->get()
            ->keyBy(fn (MessageTemplate $t) => "{$t->event}|{$t->channel}|{$t->locale}")->all());

        return $all["{$event}|{$channel}|{$locale}"] ?? null;
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function fill(string $text, array $params): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', fn (array $m) => array_key_exists($m[1], $params) ? (string) $params[$m[1]] : $m[0], $text);
    }

    /**
     * Refuses placeholders outside the event's variables.
     *
     * @param  list<string>  $variables
     */
    public static function checkVariables(string $text, array $variables, string $path): void
    {
        preg_match_all('/\{([^}]*)\}/', $text, $matches);
        $unknown = array_values(array_diff($matches[1], $variables));
        if ($unknown !== []) {
            throw ValidationException::withMessages([$path => __('rules.messages.validation.variables', ['names' => '{'.implode('}, {', $unknown).'}'])]);
        }
    }

    /**
     * The built-in text with `{variable}` placeholders, as the editor starts from.
     */
    public static function defaultText(string $key, string $locale): string
    {
        $text = __($key, [], $locale);

        return ! is_string($text) || $text === $key ? '' : (string) preg_replace('/:([a-z_]+)/', '{$1}', $text);
    }
}
