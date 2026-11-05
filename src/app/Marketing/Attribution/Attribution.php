<?php

namespace App\Marketing\Attribution;

use App\Models\Marketing\MarketingTouch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Date;

/**
 * First / last touch of the visitor (ADR-0045, Q-79): kept 90 days in the first-party cookie `opsone_src` (encrypted,
 * HTTP-only, never shared with any platform), and written as two `marketing_touches` rows on the lead, appointment
 * request, checkout, customer and order the visitor creates. No consent is needed for this internal attribution; the
 * privacy policy template says so.
 */
final class Attribution
{
    public const COOKIE = 'opsone_src';

    public const DAYS = 90;

    /**
     * Updates the cookie from this visit: the first touch is set once; the last one moves on every campaign / click /
     * external visit. An inner page view changes nothing.
     */
    public static function capture(Request $request): void
    {
        $stored = self::read($request);
        $touch = TouchClassifier::classify($request->query(), $request->headers->get('referer'), $request->getHost(), $request->path());
        if ($stored !== null && $touch === null) {
            return;
        }
        $touch ??= TouchClassifier::direct($request->path());
        $touch['touched_at'] = now()->toIso8601String();
        $value = ['first' => $stored['first'] ?? $touch, 'last' => $touch];

        Cookie::queue(Cookie::make(self::COOKIE, (string) json_encode($value, JSON_UNESCAPED_UNICODE), self::DAYS * 24 * 60, '/', null, $request->isSecure(), true, false, 'lax'));
        $request->attributes->set(self::COOKIE, $value);
    }

    /**
     * Writes the visitor's touches on a new record, once (a record that already has touches keeps them).
     */
    public static function recordFor(Model $record, ?Request $request = null): void
    {
        $touches = self::read($request ?? request());
        if ($touches === null || self::has($record)) {
            return;
        }
        foreach ([MarketingTouch::FIRST, MarketingTouch::LAST] as $kind) {
            $t = $touches[$kind];
            MarketingTouch::query()->create([
                'touchable_type' => $record->getMorphClass(), 'touchable_id' => $record->getKey(), 'kind' => $kind,
                ...array_intersect_key($t, array_flip(['source', 'medium', 'campaign', 'term', 'content', 'click_id_type', 'click_id', 'referrer_host', 'landing_path'])),
                'touched_at' => isset($t['touched_at']) ? Date::parse((string) $t['touched_at']) : null,
            ]);
        }
    }

    /** The order / customer a lead becomes inherits the lead's touches (when it has none of its own). */
    public static function inherit(Model $from, Model $to): void
    {
        if (self::has($to)) {
            return;
        }
        $rows = MarketingTouch::query()->where('touchable_type', $from->getMorphClass())->where('touchable_id', $from->getKey())->get();
        foreach ($rows as $row) {
            $copy = $row->replicate(['id', 'created_at']);
            $copy->forceFill(['touchable_type' => $to->getMorphClass(), 'touchable_id' => $to->getKey()])->save();
        }
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    public static function summaryOf(Model $record): array
    {
        return MarketingTouch::query()->where('touchable_type', $record->getMorphClass())->where('touchable_id', $record->getKey())
            ->get()->mapWithKeys(fn (MarketingTouch $t) => [$t->kind => $t->summary()])->all();
    }

    private static function has(Model $record): bool
    {
        return MarketingTouch::query()->where('touchable_type', $record->getMorphClass())->where('touchable_id', $record->getKey())->exists();
    }

    /**
     * @return array{first: array<string, string|null>, last: array<string, string|null>}|null
     */
    private static function read(Request $request): ?array
    {
        $raw = $request->cookie(self::COOKIE);
        $value = $request->attributes->get(self::COOKIE) ?? (is_string($raw) ? json_decode($raw, true) : null);
        if (! is_array($value) || ! is_array($value['first'] ?? null) || ! is_array($value['last'] ?? null)) {
            return null;
        }
        foreach (['first', 'last'] as $kind) {
            if (! is_string($value[$kind]['source'] ?? null) || ! is_string($value[$kind]['medium'] ?? null)) {
                return null;
            }
        }

        return $value;
    }
}
