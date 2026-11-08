<?php

namespace App\Help;

/**
 * OpsOne's direct support channels (email, WhatsApp, phone) read from deployment config. Only configured
 * channels are returned; OpsOne sets them at hand-over, the facility has no settings screen for them.
 */
class SupportContact
{
    /**
     * @return array{email: string|null, whatsapp: array{number: string, url: string}|null, phone: array{number: string, url: string}|null}|null
     *                                                                                                                                           null when no channel is configured
     */
    public static function channels(): ?array
    {
        $email = self::clean(config('opsone.support.email'));
        $whatsapp = self::digits(config('opsone.support.whatsapp'));
        $phone = self::clean(config('opsone.support.phone'));
        $phoneDigits = self::digits($phone);

        $channels = [
            'email' => $email,
            'whatsapp' => $whatsapp !== null ? ['number' => '+'.$whatsapp, 'url' => 'https://wa.me/'.$whatsapp] : null,
            'phone' => $phoneDigits !== null ? ['number' => $phone, 'url' => 'tel:'.(str_starts_with($phone, '+') ? '+' : '').$phoneDigits] : null,
        ];

        return array_filter($channels, fn ($c) => $c !== null) === [] ? null : $channels;
    }

    /** Whether the support email is set, i.e. a submitted request is actually mailed to OpsOne. */
    public static function emailConfigured(): bool
    {
        return self::clean(config('opsone.support.email')) !== null;
    }

    private static function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /** Digits only (wa.me wants no plus, spaces or dashes); null when nothing usable remains. */
    private static function digits(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', is_string($value) ? $value : '') ?? '';

        return $digits === '' ? null : $digits;
    }
}
