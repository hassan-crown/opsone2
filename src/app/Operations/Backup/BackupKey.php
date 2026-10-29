<?php

namespace App\Operations\Backup;

use RuntimeException;

/**
 * The deployment's backup key (ADR-0028): 32 random bytes, base64 in `OPS_BACKUP_KEY`. It is never the APP_KEY, so a
 * leaked backup key does not expose sessions or encrypted columns, and rotating one never invalidates the other.
 */
final readonly class BackupKey
{
    private function __construct(public string $bytes) {}

    public static function fromConfig(): self
    {
        return self::fromString(config('ops.backup.key'));
    }

    public static function fromString(mixed $value): self
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('OPS_BACKUP_KEY is not set. Generate one with `php artisan ops:backup-key` and store it outside the server as well.');
        }

        $raw = base64_decode(str_starts_with($value, 'base64:') ? substr($value, 7) : $value, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('OPS_BACKUP_KEY must be 32 bytes, base64 encoded.');
        }

        $appKey = (string) config('app.key');
        $appRaw = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
        if ($appRaw !== false && hash_equals((string) $appRaw, $raw)) {
            throw new RuntimeException('OPS_BACKUP_KEY must differ from APP_KEY.');
        }

        return new self($raw);
    }

    public static function generate(): string
    {
        return base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    /**
     * Identifies the key without revealing it, so a restore can say "wrong key" before decrypting.
     */
    public function fingerprint(): string
    {
        return sodium_crypto_generichash('opsone-backup-key-fingerprint', $this->bytes, 16);
    }
}
