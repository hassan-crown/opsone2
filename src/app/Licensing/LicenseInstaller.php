<?php

namespace App\Licensing;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Installs a license file only after it verifies (M-19-T04 / T06). Never writes an invalid file.
 * Every installation is audited (the file is not in the database, so the audit row follows the atomic rename).
 */
final readonly class LicenseInstaller
{
    public function __construct(
        private LicenseVerifier $verifier,
        private CurrentLicense $license,
    ) {}

    /**
     * @throws LicenseVerificationException
     */
    public function install(string $contents, ?User $actor = null, string $source = TransitionRecorder::SOURCE_SYSTEM): LicenseSnapshot
    {
        $payload = $this->verifier->verify($contents);
        $trusted = $this->license->trusted();

        if ($trusted !== null && $payload->issuedAt->lt($trusted->issuedAt)) {
            throw new LicenseVerificationException(LicenseProblem::Superseded);
        }

        $this->write($this->license->path(), $contents);
        $snapshot = $this->license->refresh();

        DB::transaction(fn () => Audit::record(
            AuditAction::LicenseInstalled, null, $actor,
            before: $trusted === null ? [] : self::facts($trusted),
            after: self::facts($payload),
            source: $source,
            label: $payload->plan,
        ));

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private static function facts(LicensePayload $payload): array
    {
        return [
            'license_id' => $payload->licenseId,
            'plan' => $payload->plan,
            'capabilities' => $payload->capabilities,
            'limits' => $payload->limits,
            'starts_at' => $payload->startsAt,
            'ends_at' => $payload->endsAt,
            'issued_at' => $payload->issuedAt,
        ];
    }

    private function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create license directory [{$directory}].");
        }

        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($temporary, $contents) === false || ! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Cannot write license file [{$path}].");
        }
    }
}
