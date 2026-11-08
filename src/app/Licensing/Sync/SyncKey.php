<?php

namespace App\Licensing\Sync;

use SensitiveParameter;

/**
 * This deployment's Ed25519 sync keypair (ADR-0047 §3). Separate from the license signing key: the secret half lives
 * only in `src/.env` (`LICENSE_SYNC_SECRET_KEY`, base64 of the 64-byte libsodium secret key); the central app holds the
 * public half. Never logged or printed.
 */
final readonly class SyncKey
{
    /**
     * @param  non-empty-string  $secretKey
     */
    private function __construct(
        #[SensitiveParameter] public string $secretKey,
        public string $publicKey,
    ) {}

    /**
     * Null when the value is missing or is not a 64-byte Ed25519 secret key.
     */
    public static function fromBase64(#[SensitiveParameter] ?string $value): ?self
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $bytes = base64_decode($value, true);
        if ($bytes === false || strlen($bytes) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return null;
        }

        return new self($bytes, sodium_crypto_sign_publickey_from_secretkey($bytes));
    }

    public static function generate(): self
    {
        $pair = sodium_crypto_sign_keypair();

        return new self(sodium_crypto_sign_secretkey($pair), sodium_crypto_sign_publickey($pair));
    }

    public function secretBase64(): string
    {
        return base64_encode($this->secretKey);
    }

    public function publicBase64(): string
    {
        return base64_encode($this->publicKey);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['publicKey' => $this->publicBase64()];
    }
}
