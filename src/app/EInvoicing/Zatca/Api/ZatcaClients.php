<?php

namespace App\EInvoicing\Zatca\Api;

use InvalidArgumentException;

/**
 * The client of an environment: `fake` in-process, `simulation` / `production` the Fatoora gateway. An environment
 * not allowed by config('zatca.environments') is refused, so a test or demo stack never calls ZATCA.
 */
final class ZatcaClients
{
    public const ENVIRONMENTS = ['fake', 'simulation', 'production'];

    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        return array_values(array_intersect(self::ENVIRONMENTS, (array) config('zatca.environments', ['fake'])));
    }

    public function for(string $environment): ZatcaClient
    {
        if (! in_array($environment, self::allowed(), true)) {
            throw new InvalidArgumentException("ZATCA environment {$environment} is not enabled for this deployment.");
        }

        return match ($environment) {
            'fake' => new FakeZatcaClient,
            default => new HttpZatcaClient(
                (string) config("zatca.{$environment}.base_url"),
                (int) config('zatca.timeout', 20),
                (int) config('zatca.connect_timeout', 5),
            ),
        };
    }
}
