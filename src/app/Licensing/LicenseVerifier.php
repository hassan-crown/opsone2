<?php

namespace App\Licensing;

use InvalidArgumentException;
use JsonException;

/**
 * Verifies a signed license envelope against this deployment (ADR-0004 §2).
 *
 * The time window is not a rejection reason: an expired license is still a valid, signed
 * statement of the term, and LicensePayload::statusAt() derives its M-19 phase.
 */
final readonly class LicenseVerifier
{
    public const int MAX_BYTES = 65536;

    public function __construct(
        private LicenseKeyring $keyring,
        private ?string $host,
        private ?string $deploymentId = null,
    ) {}

    /**
     * @throws LicenseVerificationException
     */
    public function verify(string $contents): LicensePayload
    {
        if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
            throw new LicenseVerificationException(LicenseProblem::Corrupt);
        }

        $envelope = $this->decodeJson($contents, LicenseProblem::Corrupt);

        $keyId = $envelope['kid'] ?? null;
        $payload = $envelope['payload'] ?? null;
        $signature = $envelope['signature'] ?? null;

        if (($envelope['format'] ?? null) !== LicenseEnvelope::FORMAT
            || ! is_string($keyId) || ! is_string($payload) || ! is_string($signature)) {
            throw new LicenseVerificationException(LicenseProblem::Corrupt);
        }

        $publicKey = $this->keyring->publicKey($keyId);

        if ($publicKey === null || $publicKey === '') {
            throw new LicenseVerificationException(LicenseProblem::UnknownKey);
        }

        $bytes = LicenseEnvelope::decode($payload);
        $rawSignature = LicenseEnvelope::decode($signature);

        if ($bytes === null || $rawSignature === null || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($rawSignature, LicenseEnvelope::message($bytes), $publicKey)) {
            throw new LicenseVerificationException(LicenseProblem::BadSignature);
        }

        $data = $this->decodeJson($bytes, LicenseProblem::InvalidPayload);

        if (! in_array($data['schema_version'] ?? null, LicensePayload::SUPPORTED_SCHEMAS, true)) {
            throw new LicenseVerificationException(LicenseProblem::UnsupportedSchema);
        }

        try {
            $license = LicensePayload::fromArray($data);
        } catch (InvalidArgumentException $e) {
            throw new LicenseVerificationException(LicenseProblem::InvalidPayload, $e->getMessage());
        }

        if ($this->host === null || ! in_array(strtolower($this->host), $license->domains, true)) {
            throw new LicenseVerificationException(LicenseProblem::DomainMismatch);
        }

        if ($this->deploymentId !== null && $this->deploymentId !== '' && $license->deploymentId !== $this->deploymentId) {
            throw new LicenseVerificationException(LicenseProblem::DeploymentMismatch);
        }

        return $license;
    }

    /**
     * @return array<mixed>
     */
    private function decodeJson(string $json, LicenseProblem $problem): array
    {
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new LicenseVerificationException($problem);
        }

        if (! is_array($decoded)) {
            throw new LicenseVerificationException($problem);
        }

        return $decoded;
    }
}
