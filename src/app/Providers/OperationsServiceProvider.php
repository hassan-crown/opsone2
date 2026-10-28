<?php

namespace App\Providers;

use App\Operations\Upgrade\RecordMigrationKinds;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Deployment operations (ADR-0028): backups, upgrade preflight, health, facility closure export.
 */
class OperationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(MigrationsEnded::class, RecordMigrationKinds::class);
    }
}
