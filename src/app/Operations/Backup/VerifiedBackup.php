<?php

namespace App\Operations\Backup;

use Generator;
use RuntimeException;
use ZipArchive;

/**
 * A decrypted backup whose every entry matched its manifest checksum.
 */
final class VerifiedBackup
{
    public function __construct(public readonly BackupManifest $manifest, private readonly ZipArchive $zip) {}

    /**
     * @return list<mixed>
     */
    public function json(string $entry): array
    {
        $contents = $this->zip->getFromName($entry);
        if ($contents === false) {
            throw new RuntimeException("Missing entry {$entry}.");
        }

        return array_values((array) json_decode($contents, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @return Generator<int, string>
     */
    public function lines(string $entry): Generator
    {
        $stream = $this->stream($entry);
        try {
            while (($line = fgets($stream)) !== false) {
                yield $line;
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return resource
     */
    public function stream(string $entry)
    {
        return $this->zip->getStream($entry) ?: throw new RuntimeException("Missing entry {$entry}.");
    }

    public function close(): void
    {
        $this->zip->close();
    }
}
