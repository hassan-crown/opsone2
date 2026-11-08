<?php

namespace App\Licensing\Sync;

use App\Licensing\LicenseEnvelope;

/**
 * Signs a sync request (ADR-0047 §3): Ed25519 over the `OPSONE-SYNC-v1` message, base64url without padding.
 * The prefix separates this context from license signatures, so neither can stand in for the other.
 */
final class SyncRequestSigner
{
    public const string CONTEXT = 'OPSONE-SYNC-v1';

    public const string HEADER_DEPLOYMENT = 'X-OpsOne-Deployment';

    public const string HEADER_TIMESTAMP = 'X-OpsOne-Timestamp';

    public const string HEADER_NONCE = 'X-OpsOne-Nonce';

    public const string HEADER_SIGNATURE = 'X-OpsOne-Signature';

    public static function message(string $method, string $path, string $deploymentId, int $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            self::CONTEXT,
            strtoupper($method),
            $path,
            $deploymentId,
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    public static function sign(string $message, SyncKey $key): string
    {
        return LicenseEnvelope::encode(sodium_crypto_sign_detached($message, $key->secretKey));
    }

    /**
     * 128 random bits, base64url.
     */
    public static function nonce(): string
    {
        return LicenseEnvelope::encode(random_bytes(16));
    }

    /**
     * The signed headers for one request.
     *
     * @return array<string, string>
     */
    public static function headers(string $method, string $path, string $deploymentId, int $timestamp, string $nonce, string $body, SyncKey $key): array
    {
        return [
            self::HEADER_DEPLOYMENT => $deploymentId,
            self::HEADER_TIMESTAMP => (string) $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_SIGNATURE => self::sign(self::message($method, $path, $deploymentId, $timestamp, $nonce, $body), $key),
        ];
    }
}
