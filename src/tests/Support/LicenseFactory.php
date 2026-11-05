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
     * The latest default `issued_at` handed out in this test. A license issued before the trusted one is refused as
     * superseded and the app falls back to the older license, so a test that replaces its license must never get an
     * earlier date — not even when the container clock steps back across a second under parallel load.
     */
    private static ?CarbonImmutable $lastIssued = null;

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
        self::$lastIssued = null;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $overrides = []): array
    {
        $now = CarbonImmutable::now('UTC');
        $issued = $now->subDays(10);
        if (self::$lastIssued !== null && $issued->lt(self::$lastIssued)) {
            $issued = self::$lastIssued;
        }
        self::$lastIssued = $issued;

        return [
            'schema_version' => 1,
            'license_id' => 'lic-test',
            'facility_id' => 'facility-1',
            'deployment_id' => 'deployment-1',
            'domains' => [(string) parse_url((string) config('app.url'), PHP_URL_HOST)],
            'plan' => 'growth',
            'capabilities' => ['ready_goods', 'branch_comparisons', 'marketing_reports'],
            'limits' => ['seats' => 15, 'branches' => 3, 'storage_mb' => 25600],
            'activation_id' => 'act-1',
            'starts_at' => $now->subDays(10)->toIso8601ZuluString(),
            'ends_at' => $now->addDays(355)->toIso8601ZuluString(),
            'grace_days' => 7,
            'wind_down_days' => 30,
            'retention_days' => 90,
            'issued_at' => $issued->toIso8601ZuluString(),
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
