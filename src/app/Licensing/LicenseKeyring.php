<?php

namespace App\Licensing;

use App\Licensing\Development\DevelopmentKeypair;

/**
 * Trusted Ed25519 public keys by key id. Never holds a private key.
 *
 * Several keys can be trusted at once so the central app can rotate its signing key with an
 * overlap window (central-app plan §4.3): licenses signed with the retiring key stay valid
 * until every deployment has installed one signed with the new key.
 */
final readonly class LicenseKeyring
{
    /**
     * @param  array<string, string>  $keys  key id => raw 32-byte public key
     */
    public function __construct(private array $keys) {}

    /**
     * The configured production keys, plus the development key in local/testing only.
     *
     * `$publicKeys` is `LICENSE_PUBLIC_KEYS`: comma-separated `kid=base64` pairs. The legacy
     * `$keyId`/`$publicKey` pair is still honoured and wins on a duplicate key id. Malformed
     * entries, keys of the wrong length and the development key id are skipped silently: a
     * typo must never trust a key, and the remaining keys keep working.
     */
    public static function make(?string $keyId, ?string $publicKey, bool $trustDevelopmentKey, ?string $publicKeys = null): self
    {
        $keys = [];

        self::add($keys, $keyId, $publicKey);

        foreach (explode(',', (string) $publicKeys) as $entry) {
            $parts = explode('=', $entry, 2);

            if (count($parts) === 2) {
                self::add($keys, $parts[0], $parts[1]);
            }
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

    /**
     * @return list<string>
     */
    public function keyIds(): array
    {
        return array_keys($this->keys);
    }

    /**
     * @param  array<string, string>  $keys
     */
    private static function add(array &$keys, ?string $keyId, ?string $publicKey): void
    {
        $keyId = trim((string) $keyId);
        $raw = base64_decode(trim((string) $publicKey), true);

        if ($keyId === '' || $keyId === DevelopmentKeypair::KEY_ID || isset($keys[$keyId])
            || ! is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return;
        }

        $keys[$keyId] = $raw;
    }
}
