<?php

namespace Tests\Support;

use App\Licensing\CurrentLicense;
use App\Licensing\Development\DevelopmentKeypair;
use App\Licensing\LicenseEnvelope;
use Carbon\CarbonImmutable;

/**
 * Builds licenses signed with the development key for tests.
 */
final class LicenseFactory
{
    /** @var list<string> */
    private static array $installed = [];

    /**
     * Point the app at a per-test license file and place a valid license (growth plan unless overridden).
     * Removed by TestCase::tearDown via cleanup().
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function install(array $overrides = []): CurrentLicense
    {
        $path = storage_path('framework/testing/license-'.getmypid().'-'.bin2hex(random_bytes(4)).'.json');
        @mkdir(dirname($path), 0755, true);
        config(['license.path' => $path]);
        self::$installed[] = $path;
        app()->forgetScopedInstances();

        return self::place($overrides);
    }

    public static function cleanup(): void
    {
        foreach (self::$installed as $path) {
            @unlink($path);
        }
        self::$installed = [];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $overrides = []): array
    {
        $now = CarbonImmutable::now('UTC');

        return [
            'schema_version' => 1,
            'license_id' => 'lic-test',
            'facility_id' => 'facility-1',
            'deployment_id' => 'deployment-1',
            'domains' => [(string) parse_url((string) config('app.url'), PHP_URL_HOST)],
            'plan' => 'growth',
            'capabilities' => ['ready_goods', 'branch_comparisons'],
            'limits' => ['seats' => 15, 'branches' => 3, 'storage_mb' => 25600],
            'activation_id' => 'act-1',
            'starts_at' => $now->subDays(10)->toIso8601ZuluString(),
            'ends_at' => $now->addDays(355)->toIso8601ZuluString(),
            'grace_days' => 7,
            'wind_down_days' => 30,
            'retention_days' => 90,
            'issued_at' => $now->subDays(10)->toIso8601ZuluString(),
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function signed(array $overrides = [], ?string $keyId = null, ?string $secretKey = null): string
    {
        return LicenseEnvelope::sign(
            self::payload($overrides),
            $keyId ?? DevelopmentKeypair::KEY_ID,
            $secretKey ?? DevelopmentKeypair::secretKey(),
        );
    }

    /**
     * Write a license file (bypassing verification) and evaluate it.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function place(array $overrides = []): CurrentLicense
    {
        return self::placeRaw(self::signed($overrides));
    }

    public static function placeRaw(string $contents): CurrentLicense
    {
        file_put_contents((string) config('license.path'), $contents);

        $license = app(CurrentLicense::class);
        $license->refresh();

        return $license;
    }
}
