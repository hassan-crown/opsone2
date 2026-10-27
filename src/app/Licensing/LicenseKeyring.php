<?php

namespace App\Licensing;

use App\Licensing\Development\DevelopmentKeypair;

/**
 * Trusted Ed25519 public keys by key id. Never holds a private key.
 */
final readonly class LicenseKeyring
{
    /**
     * @param  array<string, string>  $keys  key id => raw 32-byte public key
     */
    public function __construct(private array $keys) {}

    /**
     * The configured production key, plus the development key in local/testing only.
     */
    public static function make(?string $keyId, ?string $publicKey, bool $trustDevelopmentKey): self
    {
        $keys = [];

        $raw = is_string($publicKey) && $publicKey !== '' ? base64_decode($publicKey, true) : false;

        if (is_string($keyId) && $keyId !== '' && $keyId !== DevelopmentKeypair::KEY_ID
            && is_string($raw) && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            $keys[$keyId] = $raw;
        }

        if ($trustDevelopmentKey) {
            $keys[DevelopmentKeypair::KEY_ID] = DevelopmentKeypair::publicKey();
        }

        return new self($keys);
    }

    public function publicKey(string $keyId): ?string
    {
        return $this->keys[$keyId] ?? null;
    }
}
