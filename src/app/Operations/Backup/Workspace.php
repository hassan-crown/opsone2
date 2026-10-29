<?php

namespace App\Operations\Backup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A private scratch directory for one backup, restore or export (0700, under storage/app, never public). Plaintext
 * archives exist only here and are deleted when the operation ends, whether it succeeded or not.
 */
final class Workspace
{
    private function __construct(private readonly string $root) {}

    public static function create(): self
    {
        $root = storage_path('app/ops-tmp/'.Str::uuid()->toString());
        if (! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Cannot create a private work directory.');
        }

        return new self($root);
    }

    public function path(string $relative): string
    {
        $path = $this->root.'/'.ltrim($relative, '/');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $path;
    }

    public function delete(): void
    {
        File::deleteDirectory($this->root);
    }
}
