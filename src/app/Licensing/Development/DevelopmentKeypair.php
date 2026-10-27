<?php

namespace App\Licensing\Development;

/**
 * NON-SECRET Ed25519 test keypair, published in the repository on purpose (ADR-0004).
 *
 * Its public key is trusted only when the app runs in `local` or `testing`; a license signed
 * with it is rejected (`unknown_key`) in dev, stage and production. Never reuse it elsewhere.
 */
final class DevelopmentKeypair
{
    public const string KEY_ID = 'opsone-dev-insecure';

    public const string PUBLIC_KEY = 'flhrJYOYIC0N/tazzWH+M3pI3i4J279BiG54KHCs6is=';

    public const string SECRET_KEY = 'fWFtxXmN3VOXIc+w6J65dShzo8N9uHmjGhIpYUritXp+WGslg5ggLQ3+1rPNYf4zekjeLgnbv0GIbngocKzqKw==';

    public static function publicKey(): string
    {
        return (string) base64_decode(self::PUBLIC_KEY, true);
    }

    public static function secretKey(): string
    {
        return (string) base64_decode(self::SECRET_KEY, true);
    }
}
