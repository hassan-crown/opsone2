<?php

namespace App\Licensing;

use InvalidArgumentException;

/**
 * Wire format of a signed license (ADR-0004): the payload bytes are signed as-is.
 */
final class LicenseEnvelope
{
    public const string FORMAT = 'opsone-license';

    /** Domain separation prefix of the signed message. */
    public const string CONTEXT = "OPSONE-LICENSE\n";

    public static function message(string $payload): string
    {
        return self::CONTEXT.$payload;
    }

    /**
     * Sign a payload and return the envelope JSON. Only the development keypair is ever
     * available inside the tenant app; production licenses are signed by the central app.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function sign(array $payload, string $keyId, string $secretKey): string
    {
        if ($secretKey === '') {
            throw new InvalidArgumentException('A secret key is required to sign.');
        }

        $bytes = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return json_encode([
            'format' => self::FORMAT,
            'kid' => $keyId,
            'payload' => self::encode($bytes),
            'signature' => self::encode(sodium_crypto_sign_detached(self::message($bytes), $secretKey)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $text): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $text) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
