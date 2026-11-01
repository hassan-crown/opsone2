<?php

namespace App\Notifications\Channels\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Cloud API (Meta), template messages. Credentials come from `config('whatsapp.cloud')` (environment) only.
 *
 * Bounded: connect and total timeouts, and a small number of immediate retries on connection errors and 5xx. The
 * outbox owns the longer backoff and guarantees the same message is not sent twice (its claim is atomic; the
 * provider id is stored as soon as it is known). Provider error text can echo the number or the token, so only the
 * HTTP status and Meta's numeric error code are ever kept.
 */
final class CloudApiWhatsAppChannel implements WhatsAppChannel
{
    /** Meta error codes that retrying cannot fix: not a WhatsApp user, template missing/paused, parameter mismatch, no consent. */
    private const PERMANENT_CODES = ['131026', '131047', '131048', '131049', '131051', '132000', '132001', '132005', '132007', '132012', '132015', '132016', '130429'];

    public function isConfigured(): bool
    {
        return filled(config('whatsapp.cloud.phone_number_id')) && filled(config('whatsapp.cloud.access_token'));
    }

    public function sendTemplate(WhatsAppTemplateMessage $message): string
    {
        if (! $this->isConfigured()) {
            throw new WhatsAppException('WhatsApp Cloud API is not configured.', false, 'not_configured');
        }

        $url = sprintf('%s/%s/%s/messages',
            rtrim((string) config('whatsapp.cloud.base_url'), '/'),
            config('whatsapp.cloud.api_version'),
            config('whatsapp.cloud.phone_number_id'),
        );

        try {
            $response = $this->request()->post($url, [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($message->to, '+'),
                'type' => 'template',
                'template' => [
                    'name' => $message->template,
                    'language' => ['code' => $message->language],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => array_map(fn (string $value) => ['type' => 'text', 'text' => $value], $message->parameters),
                    ]],
                ],
            ]);
        } catch (ConnectionException) {
            throw new WhatsAppException('WhatsApp provider unreachable.', true, 'connection');
        } catch (RequestException $e) {
            throw $this->refused($e->response->status(), (string) data_get($e->response->json(), 'error.code', ''));
        }

        if ($response->failed()) {
            throw $this->refused($response->status(), (string) data_get($response->json(), 'error.code', ''));
        }

        $id = data_get($response->json(), 'messages.0.id');
        if (! is_string($id) || $id === '') {
            throw new WhatsAppException('WhatsApp provider answered without a message id.', true, 'no_id');
        }

        return $id;
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) config('whatsapp.cloud.access_token'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('whatsapp.cloud.connect_timeout'))
            ->timeout((int) config('whatsapp.cloud.timeout'))
            ->retry(
                max(1, 1 + (int) config('whatsapp.cloud.retries')),
                250,
                fn ($exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            );
    }

    private function refused(int $status, string $code): WhatsAppException
    {
        $permanent = in_array($code, self::PERMANENT_CODES, true) || ($status >= 400 && $status < 500 && ! in_array($status, [408, 429], true));

        return new WhatsAppException("WhatsApp provider refused the message (HTTP {$status}".($code !== '' ? ", code {$code}" : '').').', ! $permanent, $code !== '' ? $code : (string) $status);
    }
}
