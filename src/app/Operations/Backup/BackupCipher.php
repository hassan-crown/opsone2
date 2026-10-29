<?php

namespace App\Operations\Backup;

use RuntimeException;

/**
 * Streams a file through libsodium secretstream (XChaCha20-Poly1305), 1 MiB per chunk.
 *
 * Layout: magic (8) · key fingerprint (16) · stream header (24) · { length (4, big endian) · ciphertext }*.
 * The last chunk carries the FINAL tag, so a truncated or re-ordered file fails to decrypt.
 */
final class BackupCipher
{
    public const MAGIC = "OPSBAK1\n";

    private const CHUNK = 1048576;

    public static function encrypt(string $plainPath, string $cipherPath, BackupKey $key): void
    {
        $in = self::open($plainPath, 'rb');
        $out = self::open($cipherPath, 'wb');

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key->bytes);
            fwrite($out, self::MAGIC.$key->fingerprint().$header);

            $size = (int) filesize($plainPath);
            $done = 0;
            do {
                $chunk = (string) fread($in, self::CHUNK);
                $done += strlen($chunk);
                $tag = $done >= $size
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $sealed = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
                fwrite($out, pack('N', strlen($sealed)).$sealed);
            } while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public static function decrypt(string $cipherPath, string $plainPath, BackupKey $key): void
    {
        $in = self::open($cipherPath, 'rb');
        $out = self::open($plainPath, 'wb');

        try {
            $prefix = (string) fread($in, strlen(self::MAGIC) + 16);
            if (! str_starts_with($prefix, self::MAGIC)) {
                throw new RuntimeException('Not an OpsOne backup file.');
            }
            if (! hash_equals($key->fingerprint(), substr($prefix, strlen(self::MAGIC)))) {
                throw new RuntimeException('This backup was encrypted with a different OPS_BACKUP_KEY.');
            }

            $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key->bytes);

            $final = false;
            while (! $final) {
                $length = fread($in, 4);
                if ($length === false || strlen($length) !== 4) {
                    throw new RuntimeException('The backup file is truncated.');
                }
                $size = (int) (unpack('N', $length) ?: [1 => 0])[1];
                if ($size < 1) {
                    throw new RuntimeException('The backup file is damaged (bad chunk length).');
                }
                $sealed = (string) fread($in, $size);
                $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $sealed);
                if ($opened === false) {
                    throw new RuntimeException('The backup file is damaged (authentication failed).');
                }
                [$chunk, $tag] = $opened;
                fwrite($out, $chunk);
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }

            if (! feof($in) && fread($in, 1) !== '') {
                throw new RuntimeException('The backup file has trailing data.');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * @return resource
     */
    private static function open(string $path, string $mode)
    {
        $handle = fopen($path, $mode);
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }

        return $handle;
    }
}
