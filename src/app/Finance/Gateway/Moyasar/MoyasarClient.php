<?php

namespace App\Finance\Gateway\Moyasar;

use App\Finance\Gateway\GatewayUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Moyasar REST API v1 with the facility's secret key (HTTP basic, key as user). Every call is bounded (connect and
 * total timeouts). Reads retry twice on a connection error or a 5xx; creating an invoice never retries (a lost
 * response must not leave a second payable invoice we do not know). Faked with `Http::fake()` in tests.
 */
final class MoyasarClient
{
    public function __construct(private readonly MoyasarSettings $settings) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createInvoice(array $data): array
    {
        return $this->send(fn (PendingRequest $http) => $http->post('invoices', $data));
    }

    /**
     * @return array<string, mixed>
     */
    public function invoice(string $id): array
    {
        return $this->send(fn (PendingRequest $http) => $http->retry(3, 200, fn (Throwable $e) => self::retryable($e), throw: false)->get('invoices/'.rawurlencode($id)));
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(string $id): array
    {
        return $this->send(fn (PendingRequest $http) => $http->retry(3, 200, fn (Throwable $e) => self::retryable($e), throw: false)->get('payments/'.rawurlencode($id)));
    }

    /**
     * @param  \Closure(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(\Closure $call): array
    {
        $credentials = $this->settings->credentials() ?? throw new GatewayUnavailable('Moyasar is not configured.');
        $http = Http::baseUrl(rtrim((string) config('services.moyasar.base_url'), '/'))
            ->withBasicAuth($credentials['secret_key'], '')
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(max(1, (int) config('services.moyasar.timeout', 10)));

        try {
            $response = $call($http);
        } catch (ConnectionException|RequestException $e) {
            throw new GatewayUnavailable('Moyasar unreachable: '.class_basename($e));
        }
        if (! $response->successful()) {
            // The body may echo request data; only the status is kept.
            throw new GatewayUnavailable('Moyasar answered HTTP '.$response->status());
        }
        $json = $response->json();

        return is_array($json) ? $json : throw new GatewayUnavailable('Moyasar answered without JSON.');
    }

    private static function retryable(Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || ($e instanceof RequestException && $e->response->serverError());
    }
}
