<?php

namespace App\Whatsapp\Inbox;

use App\Notifications\Channels\WhatsApp\CloudApiWhatsAppChannel;
use App\Notifications\Channels\WhatsApp\WhatsAppException;
use App\Notifications\Channels\WhatsApp\WhatsAppTemplateMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Cloud API (Meta) for the inbox, with the facility's own credentials from `config('whatsapp.cloud')`
 * (environment only, Q-72). Bounded like the notification channel: connect and total timeouts and a few immediate
 * retries on connection errors and 5xx; the queue job owns the longer backoff. Only the HTTP status and Meta's numeric
 * error code are ever kept — provider text can echo a number or the token.
 */
final class CloudConversationTransport implements ConversationTransport
{
    /** Codes retrying cannot fix: outside the window (131047), not a WhatsApp user, template problems, policy. */
    private const PERMANENT_CODES = ['131026', '131047', '131048', '131049', '131051', '132000', '132001', '132005', '132007', '132012', '132015', '132016', '130429', '131008', '131009'];

    public function __construct(private readonly CloudApiWhatsAppChannel $templates) {}

    public function isConfigured(): bool
    {
        return $this->templates->isConfigured();
    }

    public function sendText(string $to, string $body): string
    {
        $response = $this->call(fn (PendingRequest $http) => $http->post($this->url((string) config('whatsapp.cloud.phone_number_id').'/messages'), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => ltrim($to, '+'),
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ]));

        $id = data_get($response->json(), 'messages.0.id');
        if (! is_string($id) || $id === '') {
            throw new WhatsAppException('WhatsApp provider answered without a message id.', true, 'no_id');
        }

        return $id;
    }

    public function sendTemplate(WhatsAppTemplateMessage $message): string
    {
        return $this->templates->sendTemplate($message);
    }

    public function fetchMedia(string $mediaId, array $allowedTypes, int $maxBytes): MediaFile
    {
        if (preg_match('/^[A-Za-z0-9_.\-]{1,191}$/', $mediaId) !== 1) {
            throw new MediaRejected('type');
        }

        $meta = $this->call(fn (PendingRequest $http) => $http->get($this->url($mediaId)))->json();
        $url = (string) data_get($meta, 'url', '');
        $mime = (string) data_get($meta, 'mime_type', '');
        $size = data_get($meta, 'file_size');
        MediaCheck::declared($mime, is_numeric($size) ? (int) $size : null, $allowedTypes, $maxBytes);

        // Meta serves media only from its own hosts, with the same bearer token.
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! (str_ends_with($host, '.fbsbx.com') || str_ends_with($host, '.facebook.com') || str_ends_with($host, '.whatsapp.net'))) {
            throw new WhatsAppException('WhatsApp media URL is not a Meta host.', false, 'media_host');
        }

        // Streamed into a capped buffer, no redirects followed: never more than the limit is read into memory.
        $file = $this->call(fn (PendingRequest $http) => $http->withHeaders(['Accept' => '*/*'])
            ->withOptions(['stream' => true, 'allow_redirects' => false])->get($url));
        if ($file->redirect()) {
            throw new WhatsAppException('WhatsApp media download was redirected.', false, 'media_redirect');
        }
        $length = $file->header('Content-Length');
        if ($length !== '' && (int) $length > $maxBytes) {
            throw new MediaRejected('size');
        }

        $stream = $file->toPsrResponse()->getBody();
        $bytes = '';
        while (! $stream->eof()) {
            $bytes .= $stream->read(65536);
            if (strlen($bytes) > $maxBytes) {
                $stream->close();

                throw new MediaRejected('size');
            }
        }

        return MediaCheck::content($bytes, $mime, $allowedTypes, $maxBytes);
    }

    /**
     * @param  callable(PendingRequest): Response  $send
     */
    private function call(callable $send): Response
    {
        if (! $this->isConfigured()) {
            throw new WhatsAppException('WhatsApp Cloud API is not configured.', false, 'not_configured');
        }

        try {
            $response = $send($this->request());
        } catch (ConnectionException) {
            throw new WhatsAppException('WhatsApp provider unreachable.', true, 'connection');
        } catch (RequestException $e) {
            throw $this->refused($e->response->status(), (string) data_get($e->response->json(), 'error.code', ''));
        }

        if ($response->failed()) {
            throw $this->refused($response->status(), (string) data_get($response->json(), 'error.code', ''));
        }

        return $response;
    }

    private function url(string $path): string
    {
        return sprintf('%s/%s/%s', rtrim((string) config('whatsapp.cloud.base_url'), '/'), config('whatsapp.cloud.api_version'), $path);
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

        return new WhatsAppException("WhatsApp provider refused the request (HTTP {$status}".($code !== '' ? ", code {$code}" : '').').', ! $permanent, $code !== '' ? $code : (string) $status);
    }
}
