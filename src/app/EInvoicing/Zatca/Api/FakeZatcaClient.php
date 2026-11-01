<?php

namespace App\EInvoicing\Zatca\Api;

use App\EInvoicing\Zatca\InvoiceHasher;
use App\EInvoicing\Zatca\InvoiceSigner;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * An in-process stand-in for the Fatoora gateway (tests, local and demo data; ADR-0032). It behaves like ZATCA where
 * it matters: the CSR is signed by a throw-away test CA into a CSID (any 6-digit OTP except 000000), submissions are
 * checked for a valid hash and signature (400 with an error otherwise), standard invoices come back "cleared" with the
 * same XML. Tests can queue raw outcomes with `queue()`, consumed one per call: 503/429 throw (retries), 4xx answer
 * with an error (rejection), 2xx lets that call behave normally.
 */
final class FakeZatcaClient implements ZatcaClient
{
    /** @var list<int> */
    private static array $queued = [];

    /** @var list<array{call: string, uuid: string|null}> */
    public static array $calls = [];

    private static ?OpenSSLAsymmetricKey $caKey = null;

    private static ?string $caCert = null;

    /**
     * The next calls answer with these HTTP statuses (5xx/429 throw like the real client).
     */
    public static function queue(int ...$statuses): void
    {
        self::$queued = array_values([...self::$queued, ...$statuses]);
    }

    public static function reset(): void
    {
        self::$queued = [];
        self::$calls = [];
    }

    public function complianceCsid(string $csrBase64, string $otp): ZatcaResponse
    {
        self::$calls[] = ['call' => 'compliance', 'uuid' => null];
        if (($forced = $this->forced('compliance')) !== null) {
            return $forced;
        }
        if (preg_match('/^\d{6}$/', $otp) !== 1 || $otp === '000000') {
            return new ZatcaResponse(400, ['errors' => [['code' => 'Invalid-OTP', 'message' => 'The provided OTP is invalid.']]]);
        }

        return new ZatcaResponse(200, [
            'requestID' => (string) random_int(1_000_000_000, 9_999_999_999),
            'dispositionMessage' => 'ISSUED',
            'binarySecurityToken' => base64_encode($this->issue((string) base64_decode($csrBase64))),
            'secret' => bin2hex(random_bytes(16)),
        ]);
    }

    public function complianceCheck(ZatcaCredentials $credentials, string $uuid, string $hash, string $xml): ZatcaResponse
    {
        self::$calls[] = ['call' => 'compliance_check', 'uuid' => $uuid];

        return $this->forced('compliance_check') ?? $this->check($hash, $xml, str_contains($xml, 'name="01'));
    }

    public function productionCsid(ZatcaCredentials $credentials, string $complianceRequestId): ZatcaResponse
    {
        self::$calls[] = ['call' => 'production', 'uuid' => null];

        // Same key pair, so the compliance certificate stands in for the production one.
        return $this->forced('production') ?? new ZatcaResponse(200, [
            'requestID' => (string) random_int(1_000_000_000, 9_999_999_999),
            'dispositionMessage' => 'ISSUED',
            'binarySecurityToken' => $credentials->token,
            'secret' => bin2hex(random_bytes(16)),
        ]);
    }

    public function report(ZatcaCredentials $credentials, string $uuid, string $hash, string $xml): ZatcaResponse
    {
        self::$calls[] = ['call' => 'report', 'uuid' => $uuid];

        return $this->forced('report') ?? $this->check($hash, $xml, false);
    }

    public function clear(ZatcaCredentials $credentials, string $uuid, string $hash, string $xml): ZatcaResponse
    {
        self::$calls[] = ['call' => 'clear', 'uuid' => $uuid];

        return $this->forced('clear') ?? $this->check($hash, $xml, true);
    }

    private function check(string $hash, string $xml, bool $clearance): ZatcaResponse
    {
        $verified = InvoiceSigner::verify($xml);
        $errors = [];
        if ($hash !== InvoiceHasher::hash($xml)) {
            $errors[] = ['code' => 'invoiceHash', 'message' => 'The invoice hash does not match the invoice.'];
        }
        foreach ($verified['errors'] as $error) {
            $errors[] = ['code' => 'signature', 'message' => "Signature check failed: {$error}."];
        }
        $results = ['status' => $errors === [] ? 'PASS' : 'ERROR', 'errorMessages' => $errors, 'warningMessages' => [], 'infoMessages' => []];
        if ($errors !== []) {
            return new ZatcaResponse(400, ['validationResults' => $results] + ($clearance ? ['clearanceStatus' => 'NOT_CLEARED'] : ['reportingStatus' => 'NOT_REPORTED']));
        }

        return new ZatcaResponse(200, $clearance
            ? ['validationResults' => $results, 'clearanceStatus' => 'CLEARED', 'clearedInvoice' => base64_encode($xml)]
            : ['validationResults' => $results, 'reportingStatus' => 'REPORTED']);
    }

    private function forced(string $call): ?ZatcaResponse
    {
        $status = array_shift(self::$queued);
        if ($status === null || $status < 300) {
            // 2xx = let this call behave normally (lets a test reach the call it wants to fail).
            return null;
        }
        if ($status === 429 || $status >= 500) {
            throw new ZatcaUnavailable("ZATCA {$call}: HTTP {$status}.");
        }

        return new ZatcaResponse($status, ['validationResults' => ['status' => 'ERROR', 'errorMessages' => [['code' => 'FAKE-'.$status, 'message' => 'Queued fake outcome.']], 'warningMessages' => []]]);
    }

    /**
     * Signs the CSR with the test CA: the Base64 certificate body (what ZATCA's token decodes to).
     */
    private function issue(string $csrPem): string
    {
        if (self::$caKey === null || self::$caCert === null) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1']);
            if ($key === false) {
                throw new RuntimeException('The fake CA key could not be created.');
            }
            $csr = openssl_csr_new(['commonName' => 'OpsOne-Fake-ZATCA-CA', 'domainComponent' => 'fake'], $key, ['digest_alg' => 'sha256']);
            $ca = $csr instanceof \OpenSSLCertificateSigningRequest ? openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256'], 1) : false;
            if ($ca === false || ! openssl_x509_export($ca, $caPem)) {
                throw new RuntimeException('The fake CA could not be created.');
            }
            self::$caKey = $key;
            self::$caCert = $caPem;
        }

        $cert = openssl_csr_sign($csrPem, self::$caCert, self::$caKey, 365, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        if ($cert === false || ! openssl_x509_export($cert, $pem)) {
            throw new RuntimeException('The fake CSID could not be issued.');
        }
        while (openssl_error_string() !== false) {
            // drain
        }

        return (string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);
    }
}
