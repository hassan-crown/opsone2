<?php

namespace App\Console\Commands;

use App\Operations\Upgrade\Preflight;
use App\Operations\Upgrade\PreflightCheck;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ops:preflight {--json : Machine-readable output} {--skip-backup-check : Accept a missing recent backup (warn only)}')]
#[Description('Refuse an upgrade when code and schema are incompatible, the queue is busy or no fresh backup exists (NFR-15)')]
class OpsPreflightCommand extends Command
{
    public function handle(Preflight $preflight): int
    {
        $checks = $preflight->run((bool) $this->option('skip-backup-check'));
        $failed = array_filter($checks, fn (PreflightCheck $c) => $c->failed()) !== [];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => ! $failed,
                'version' => config('ops.version'),
                'checks' => array_map(fn (PreflightCheck $c) => ['name' => $c->name, 'result' => $c->result, 'message' => $c->message], $checks),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Check', 'Result', 'Detail'], array_map(fn (PreflightCheck $c) => [$c->name, strtoupper($c->result), $c->message], $checks));
            $failed
                ? $this->components->error('Preflight failed: do not deploy or migrate.')
                : $this->components->info('Preflight passed for version '.config('ops.version').'.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
