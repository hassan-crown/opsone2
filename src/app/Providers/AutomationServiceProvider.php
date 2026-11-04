<?php

namespace App\Providers;

use App\Automation\AutomationContext;
use App\Automation\AutomationRecorder;
use App\Models\AuditEvent;
use App\Models\Interest;
use App\Models\StateTransition;
use App\Notifications\NotificationTargets;
use Illuminate\Support\ServiceProvider;

/**
 * No-Code phase 3 (ADR-0042): the automation engine listens to the rows the application already writes for every
 * change — state transitions and audit events, both inside the change's transaction — and never to model events of
 * its own. A lead notice opens the lead's page (P-09 re-checked on open).
 */
class AutomationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AutomationContext::class);
    }

    public function boot(): void
    {
        StateTransition::created(fn (StateTransition $t) => $this->app->make(AutomationRecorder::class)->transition($t));
        AuditEvent::created(fn (AuditEvent $a) => $this->app->make(AutomationRecorder::class)->audit($a));

        $this->app->make(NotificationTargets::class)->register(
            (new Interest)->getMorphClass(),
            fn (int $id) => Interest::query()->find($id),
            fn (object $interest) => route('interests.show', ['interest' => $interest instanceof Interest ? $interest->id : 0]),
        );
    }
}
