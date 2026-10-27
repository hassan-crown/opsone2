<?php

namespace App\Licensing;

use RuntimeException;

/**
 * Installs a license file only after it verifies (M-19-T04 / T06). Never writes an invalid file.
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
    public function install(string $contents): LicenseSnapshot
    {
        $payload = $this->verifier->verify($contents);
        $trusted = $this->license->trusted();

        if ($trusted !== null && $payload->issuedAt->lt($trusted->issuedAt)) {
            throw new LicenseVerificationException(LicenseProblem::Superseded);
        }

        $this->write($this->license->path(), $contents);

        return $this->license->refresh();
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
