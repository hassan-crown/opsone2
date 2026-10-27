<?php

namespace App\Console\Commands;

use App\Licensing\Development\DevelopmentKeypair;
use App\Licensing\LicenseEnvelope;
use App\Licensing\LicenseInstaller;
use App\Licensing\LicenseVerificationException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

#[Signature('license:dev-issue
    {--plan=growth : basic, growth or scale}
    {--days=365 : Term length in days}
    {--starts= : Term start, ISO-8601 (default: now)}
    {--unactivated : Issue an unactivated provisioning license}
    {--domain=* : Licensed domains (default: the APP_URL host)}
    {--capability=* : Replace the plan capabilities}
    {--seats= : Override the seats limit}
    {--branches= : Override the branches limit}
    {--storage-mb= : Override the storage limit}
    {--retention-days=90 : Read-and-export period after wind-down}
    {--output= : Write the file here instead of installing it}')]
#[Description('Sign and install a license with the NON-SECRET development key (local/testing only)')]
class LicenseDevIssueCommand extends Command
{
    /**
     * Starting point from "الباقات وحدود الاستخدام" (not commercially final).
     *
     * @var array<string, array{capabilities: list<string>, seats: int, branches: int, storage_mb: int}>
     */
    private const array PLANS = [
        'basic' => ['capabilities' => [], 'seats' => 5, 'branches' => 1, 'storage_mb' => 5 * 1024],
        'growth' => ['capabilities' => ['ready_goods', 'branch_comparisons'], 'seats' => 15, 'branches' => 3, 'storage_mb' => 25 * 1024],
        'scale' => ['capabilities' => ['ready_goods', 'branch_comparisons'], 'seats' => 40, 'branches' => 10, 'storage_mb' => 100 * 1024],
    ];

    public function handle(LicenseInstaller $installer): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error('license:dev-issue only runs in the local and testing environments.');

            return self::FAILURE;
        }

        $plan = (string) $this->option('plan');

        if (! isset(self::PLANS[$plan])) {
            $this->components->error('Unknown plan. Use: '.implode(', ', array_keys(self::PLANS)).'.');

            return self::FAILURE;
        }

        try {
            $contents = LicenseEnvelope::sign(
                $this->payload($plan),
                DevelopmentKeypair::KEY_ID,
                DevelopmentKeypair::secretKey(),
            );
        } catch (Throwable $e) {
            $this->components->error('Cannot build the license: '.$e->getMessage());

            return self::FAILURE;
        }

        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            file_put_contents($output, $contents);
            $this->components->info("Development license written to [{$output}].");

            return self::SUCCESS;
        }

        try {
            $snapshot = $installer->install($contents);
        } catch (LicenseVerificationException $e) {
            $this->components->error("License rejected: {$e->problem->value}.");

            return self::FAILURE;
        }

        $this->components->info("Development license installed. Status: {$snapshot->status->value}.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $plan): array
    {
        $preset = self::PLANS[$plan];
        $now = CarbonImmutable::now('UTC');
        $starts = is_string($this->option('starts')) ? CarbonImmutable::parse($this->option('starts'))->utc() : $now;
        $activated = ! $this->option('unactivated');

        /** @var list<string> $domains */
        $domains = (array) $this->option('domain');
        /** @var list<string> $capabilities */
        $capabilities = (array) $this->option('capability');

        return [
            'schema_version' => 1,
            'license_id' => (string) Str::uuid(),
            'facility_id' => 'dev-facility',
            'deployment_id' => 'dev-deployment',
            'domains' => $domains !== [] ? $domains : [(string) parse_url((string) config('app.url'), PHP_URL_HOST)],
            'plan' => $plan,
            'capabilities' => $capabilities !== [] ? $capabilities : $preset['capabilities'],
            'limits' => [
                'seats' => $this->intOption('seats') ?? $preset['seats'],
                'branches' => $this->intOption('branches') ?? $preset['branches'],
                'storage_mb' => $this->intOption('storage-mb') ?? $preset['storage_mb'],
            ],
            'activation_id' => $activated ? 'dev-activation' : null,
            'starts_at' => $activated ? $starts->toIso8601ZuluString() : null,
            'ends_at' => $activated ? $starts->addDays((int) $this->option('days'))->toIso8601ZuluString() : null,
            'grace_days' => 7,
            'wind_down_days' => 30,
            'retention_days' => (int) $this->option('retention-days'),
            'issued_at' => $now->toIso8601ZuluString(),
        ];
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : null;
    }
}
