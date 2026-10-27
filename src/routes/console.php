<?php

use App\Actions\StateMachines\M01Invitation\T02ExpireInvitation;
use App\Actions\StateMachines\M37OwnershipTransfer\T04ExpireOwnershipTransfer;
use App\Models\OutboxMessage;
use App\Notifications\OutboxDeliverer;
use App\Notifications\OutboxStatus;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Time service for M-01-T02 (invitation expiry) and M-37-T04 (ownership transfer expiry).
Artisan::command('access:expire', function (T02ExpireInvitation $invitations, T04ExpireOwnershipTransfer $transfers) {
    $this->info(sprintf('Expired %d invitation(s) and %d ownership transfer(s).', $invitations->handleDue(), $transfers->handleDue()));
})->purpose('Expire lapsed staff invitations and ownership transfer requests');

Schedule::command('access:expire')->everyFifteenMinutes()->withoutOverlapping();

// Outbox safety net: re-queue messages whose delivery job was lost, and drop delivered ones after 30 days.
Artisan::command('outbox:process', function (OutboxDeliverer $deliverer) {
    $this->info(sprintf('Re-queued %d outbound message(s).', $deliverer->redispatchDue()));
})->purpose('Re-queue due or stuck outbound messages');

Artisan::command('outbox:prune', function () {
    $deleted = OutboxMessage::query()
        ->whereIn('status', [OutboxStatus::Sent->value, OutboxStatus::Skipped->value, OutboxStatus::Dead->value])
        ->where('updated_at', '<', now()->subDays(30))
        ->delete();
    $this->info(sprintf('Pruned %d outbound message(s).', $deleted));
})->purpose('Delete old finished outbound messages');

Schedule::command('outbox:process')->everyMinute()->withoutOverlapping();
Schedule::command('outbox:prune')->daily();
