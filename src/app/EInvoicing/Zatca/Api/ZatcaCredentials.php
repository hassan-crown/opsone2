<?php

namespace App\EInvoicing\Zatca\Api;

/**
 * A CSID as HTTP basic credentials: the binarySecurityToken and its secret. Never logged, never serialized.
 */
final readonly class ZatcaCredentials
{
    public function __construct(#[\SensitiveParameter] public string $token, #[\SensitiveParameter] public string $secret) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['token' => '[hidden]', 'secret' => '[hidden]'];
    }
}
