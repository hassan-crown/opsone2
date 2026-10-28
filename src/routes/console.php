<?php

use App\Actions\StateMachines\M01Invitation\T02ExpireInvitation;
use App\Actions\StateMachines\M08Document\T05ExpireDocuments;
use App\Actions\StateMachines\M37OwnershipTransfer\T04ExpireOwnershipTransfer;
use App\Actions\StateMachines\M38SupportAccess\T00RequestSupportAccess;
use App\Actions\StateMachines\M38SupportAccess\T04ExpireSupportAccess;
use App\Finance\CollectionReminders;
use App\Models\Membership;
use App\Models\OutboxMessage;
use App\Models\SupportRequest;
use App\Models\User;
use App\Notifications\OutboxDeliverer;
use App\Notifications\OutboxStatus;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Time service for M-01-T02 (invitation expiry) and M-37-T04 (ownership transfer expiry).
Artisan::command('access:expire', function (T02ExpireInvitation $invitations, T04ExpireOwnershipTransfer $transfers) {
    $this->info(sprintf('Expired %d invitation(s) and %d ownership transfer(s).', $invitations->handleDue(), $transfers->handleDue()));
})->purpose('Expire lapsed staff invitations and ownership transfer requests');

Schedule::command('access:expire')->everyFifteenMinutes()->withoutOverlapping();

// Time service for M-08-T05: quotes awaiting the customer past their last valid day expire.
Artisan::command('agreements:expire-documents', function (T05ExpireDocuments $documents) {
    $this->info(sprintf('Expired %d document(s).', $documents->handle()));
})->purpose('Expire quotes whose validity ended without a decision');

Schedule::command('agreements:expire-documents')->dailyAt('00:10')->withoutOverlapping();

// Collection reminders to customers (FIN-11): due soon and overdue, only inside the facility's reminder hours.
Artisan::command('finance:remind', function (CollectionReminders $reminders) {
    $this->info(sprintf('Queued %d collection reminder(s).', $reminders->runDue(now())));
})->purpose('Remind customers of dues soon or overdue (inside the reminder hours)');

Schedule::command('finance:remind')->hourly()->withoutOverlapping();

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

// OpsOne support access (M-38). Until the central app exists, OpsOne operations use these from the server shell.
Artisan::command('support:identity {email} {name}', function (string $email, string $name) {
    if (Membership::query()->whereIn('user_id', User::query()->where('email', $email)->select('id'))->exists()) {
        $this->error('This address belongs to a facility member; a support identity must be a separate account.');

        return 1;
    }
    $user = User::query()->firstOrNew(['email' => $email]);
    $user->forceFill([
        'name' => $name,
        'is_support' => true,
        'password' => $user->exists ? $user->password : Hash::make(Str::random(64)),
        'email_verified_at' => $user->email_verified_at ?? now(),
    ])->save();
    $this->info("Support identity #{$user->id} ready. It can sign in only while a grant is active; set its password with «forgot password».");

    return 0;
})->purpose('Create or mark an OpsOne support identity (no facility membership)');

Artisan::command('support:request-access {email} {--area=*} {--hours=24} {--reason=} {--request=}', function (string $email, T00RequestSupportAccess $request) {
    $support = User::query()->where('email', $email)->where('is_support', true)->first();
    if ($support === null) {
        $this->error('No support identity with that address.');

        return 1;
    }
    $reference = is_string($this->option('request')) ? $this->option('request') : '';
    $supportRequest = preg_match('/^SR-0*(\d+)$/', $reference, $m) === 1 ? SupportRequest::query()->find((int) $m[1]) : null;

    $reason = is_string($this->option('reason')) ? $this->option('reason') : '';
    $areas = array_values(array_filter((array) $this->option('area'), is_string(...)));

    try {
        $grant = $request->handle($support, $areas, (int) $this->option('hours'), $reason, $supportRequest);
    } catch (InvalidArgumentException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $this->info("Requested support access {$grant->uuid}; waiting for the owner's decision.");

    return 0;
})->purpose('Ask the facility owner for time-boxed OpsOne support access (M-38-T00)');

Artisan::command('support:expire', function (T04ExpireSupportAccess $expire) {
    $this->info(sprintf('Ended %d support grant(s) or request(s).', $expire->handleDue()));
})->purpose('End support grants past their window and lapsed requests (M-38-T04)');

Schedule::command('support:expire')->everyMinute()->withoutOverlapping();

// Operations for this deployment (ADR-0028): daily encrypted backup with retention (Q-54 default), heartbeats for
// /health, and expiry of facility export files.
Schedule::command('ops:backup --prune')
    ->dailyAt((string) config('ops.backup.time', '02:30'))
    ->when(fn () => (bool) config('ops.backup.enabled'))
    ->withoutOverlapping(120);
Schedule::command('ops:heartbeat')->everyMinute();
Schedule::command('ops:exports-prune')->hourly()->withoutOverlapping();
