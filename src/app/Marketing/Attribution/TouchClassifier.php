<?php

namespace App\Marketing\Attribution;

/**
 * Turns one visit into a touch (ADR-0045): campaign tags win, then a click id (gclid → google / cpc …), then the
 * referring site (a search engine is organic, a social network is social, anything else a referral), else direct.
 * Every value is cleaned and cut so nothing a visitor puts in a URL reaches the database as is.
 */
final class TouchClassifier
{
    /** Query parameter => [click id type, source, medium]. */
    private const CLICK_IDS = [
        'gclid' => ['gclid', 'google', 'cpc'],
        'gbraid' => ['gbraid', 'google', 'cpc'],
        'wbraid' => ['wbraid', 'google', 'cpc'],
        'fbclid' => ['fbclid', 'meta', 'social'],
        'ttclid' => ['ttclid', 'tiktok', 'cpc'],
        'ScCid' => ['sccid', 'snapchat', 'cpc'],
    ];

    /** Referrer host suffix => [source, medium]. */
    private const REFERRERS = [
        'google.' => ['google', 'organic'], 'bing.com' => ['bing', 'organic'], 'yahoo.' => ['yahoo', 'organic'],
        'duckduckgo.com' => ['duckduckgo', 'organic'], 'yandex.' => ['yandex', 'organic'],
        'instagram.com' => ['instagram', 'social'], 'facebook.com' => ['facebook', 'social'], 'fb.com' => ['facebook', 'social'],
        't.co' => ['x', 'social'], 'x.com' => ['x', 'social'], 'twitter.com' => ['x', 'social'],
        'tiktok.com' => ['tiktok', 'social'], 'snapchat.com' => ['snapchat', 'social'], 'linkedin.com' => ['linkedin', 'social'],
        'youtube.com' => ['youtube', 'social'], 'wa.me' => ['whatsapp', 'social'], 'whatsapp.com' => ['whatsapp', 'social'],
    ];

    /**
     * The touch of this visit, or null when it is neither a campaign, a click, nor another site (an inner page view).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, string|null>|null
     */
    public static function classify(array $query, ?string $referrer, string $ownHost, string $path): ?array
    {
        $utm = [];
        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
            $utm[$key] = self::clean($query['utm_'.$key] ?? null, $key === 'source' || $key === 'medium' ? 100 : 150);
        }
        [$clickType, $clickId, $clickSource, $clickMedium] = [null, null, null, null];
        foreach (self::CLICK_IDS as $param => [$type, $source, $medium]) {
            if (($value = self::clean($query[$param] ?? null, 255, true)) !== null) {
                [$clickType, $clickId, $clickSource, $clickMedium] = [$type, $value, $source, $medium];
                break;
            }
        }
        $refHost = self::host($referrer);
        $external = $refHost !== null && $refHost !== strtolower($ownHost) && ! str_ends_with($refHost, '.'.strtolower($ownHost));

        if ($utm['source'] !== null || $clickType !== null) {
            $source = $utm['source'] ?? $clickSource;
            $medium = $utm['medium'] ?? $clickMedium ?? 'referral';
        } elseif ($external) {
            [$source, $medium] = self::referrer($refHost);
        } else {
            return null;
        }

        return [
            'source' => strtolower((string) $source), 'medium' => strtolower((string) $medium),
            'campaign' => $utm['campaign'], 'term' => $utm['term'], 'content' => $utm['content'],
            'click_id_type' => $clickType, 'click_id' => $clickId,
            'referrer_host' => $external ? $refHost : null,
            'landing_path' => mb_substr('/'.ltrim($path, '/'), 0, 255),
        ];
    }

    /** @return array<string, string|null> */
    public static function direct(string $path): array
    {
        return [
            'source' => '(direct)', 'medium' => '(none)', 'campaign' => null, 'term' => null, 'content' => null,
            'click_id_type' => null, 'click_id' => null, 'referrer_host' => null, 'landing_path' => mb_substr('/'.ltrim($path, '/'), 0, 255),
        ];
    }

    /** @return array{string, string} */
    private static function referrer(string $host): array
    {
        foreach (self::REFERRERS as $suffix => $pair) {
            $match = str_ends_with($suffix, '.')
                ? (str_starts_with($host, $suffix) || str_contains($host, '.'.$suffix))
                : ($host === $suffix || str_ends_with($host, '.'.$suffix));
            if ($match) {
                return $pair;
            }
        }

        return [$host, 'referral'];
    }

    private static function host(?string $url): ?string
    {
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
        if (! is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);

        return mb_substr(str_starts_with($host, 'www.') ? substr($host, 4) : $host, 0, 190);
    }

    /** Printable text only, trimmed and cut; click ids keep a strict token charset. */
    private static function clean(mixed $value, int $max, bool $token = false): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = $token ? (string) preg_replace('/[^A-Za-z0-9._~-]/', '', $value) : trim((string) preg_replace('/[\p{C}<>"\']+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
