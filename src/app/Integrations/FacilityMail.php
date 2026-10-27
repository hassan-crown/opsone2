<?php

namespace App\Integrations;

use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Chooses the mailer for outbound mail: the facility's own SMTP account when enabled (Q-22), otherwise the
 * application mailer (`log` in development).
 */
final class FacilityMail
{
    public const MAILER = 'facility';

    public function __construct(
        private readonly IntegrationSettings $settings,
        private readonly PublicHost $hosts,
    ) {}

    public function mailer(): string
    {
        $smtp = $this->settings->smtp();

        if ($smtp === null) {
            return (string) config('mail.default');
        }

        $this->register($smtp);

        return self::MAILER;
    }

    /**
     * @return array{address: string, name: string|null}|null
     */
    public function from(): ?array
    {
        $smtp = $this->settings->smtp();

        return $smtp === null ? null : ['address' => $smtp['from_address'], 'name' => $smtp['from_name']];
    }

    /**
     * Registers the facility mailer for the saved (possibly not yet enabled) account — used by "send test email".
     *
     * @param  array<string, mixed>  $smtp
     */
    public function register(array $smtp): void
    {
        // Re-checked at connect time: DNS may have changed since the settings were saved (SSRF, rebinding).
        if (! $this->hosts->allows((string) ($smtp['host'] ?? ''))) {
            throw new RuntimeException('SMTP host does not resolve to a public address.');
        }

        config(['mail.mailers.'.self::MAILER => [
            'transport' => 'smtp',
            'scheme' => ($smtp['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
            'host' => $smtp['host'],
            'port' => (int) $smtp['port'],
            'username' => $smtp['username'] ?? null,
            'password' => $smtp['password'] ?? null,
            'timeout' => 10,
        ]]);

        Mail::purge(self::MAILER);
    }
}
