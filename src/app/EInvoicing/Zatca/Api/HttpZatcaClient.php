<?php

namespace App\EInvoicing\Zatca\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The real Fatoora gateway (simulation or production base URL, ADR-0032). Bounded: connect and total timeouts from
 * config, no in-request retry (the outbox owns retries and their backoff). Bodies carry the Base64 invoice; nothing
 * here logs a request, a credential or a response body.
 */
final class HttpZatcaClient implements ZatcaClient
{
    public function __construct(private readonly string $baseUrl, private readonly int $timeout, private readonly int $connectTimeout) {}

    public function complianceCsid(string $csrBase64, string $otp): ZatcaResponse
    {
        return $this->send($this->request()->withHeaders(['OTP' => $otp]), 'compliance', ['csr' => $csrBase64]);
    }

    public function complianceCheck(ZatcaCredentials $credentials, string $uuid, string $hash, string $xml): ZatcaResponse
    {
        return $this->send($this->request($credentials), 'compliance/invoices', self::invoice($uuid, $hash, $xml));
    }

    public function productionCsid(ZatcaCredentials $credentials, string $complianceRequestId): ZatcaResponse
    {
        return $this->send($this->request($credentials), 'production/csids', ['compliance_request_id' => $complianceRequestId]);
    }

    public function report(ZatcaCredentials $credentials, string $uuid, string $hash, string $xml): ZatcaResponse
    {
        return $this->send($this->request($credentials)->withHeaders(['Clearance-Status' => '0']), 'invoices/reporting/single', self::invoice($uuid, $hash, $xml));
    }

    public function clear(ZatcaCredentials $credentials, string $uuid, string $hash, string $xml): ZatcaResponse
    {
        return $this->send($this->request($credentials)->withHeaders(['Clearance-Status' => '1']), 'invoices/clearance/single', self::invoice($uuid, $hash, $xml));
    }

    private function request(?ZatcaCredentials $credentials = null): PendingRequest
    {
        $request = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->withHeaders(['Accept-Version' => 'V2', 'Accept-Language' => 'en'])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);

        return $credentials === null ? $request : $request->withBasicAuth($credentials->token, $credentials->secret);
    }

    /**
     * @param  array<string, string>  $body
     */
    private function send(PendingRequest $request, string $path, array $body): ZatcaResponse
    {
        try {
            $response = $request->post($path, $body);
        } catch (ConnectionException) {
            throw new ZatcaUnavailable("ZATCA {$path}: connection failed.");
        }

        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            throw new ZatcaUnavailable("ZATCA {$path}: HTTP {$status}.");
        }
        $json = $response->json();

        return new ZatcaResponse($status, is_array($json) ? $json : []);
    }

    /**
     * @return array{invoiceHash: string, uuid: string, invoice: string}
     */
    private static function invoice(string $uuid, string $hash, string $xml): array
    {
        return ['invoiceHash' => $hash, 'uuid' => $uuid, 'invoice' => base64_encode($xml)];
    }
}
