<?php

use App\Actions\Rules\PublishConfigRelease;
use App\Actions\Rules\SavePaymentPlan;
use App\Configuration\ConfigReleases;
use App\Configuration\ConfigSnapshot;
use App\Models\ConfigRelease;
use App\Models\Finance\OrderDue;
use App\Models\Finance\PaymentPlan;
use App\Models\User;

require_once __DIR__.'/../Finance/FinanceTestHelpers.php';

/**
 * One plan line as the editor sends it (value as text).
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function planLine(string $key, string $basis, ?string $value, string $event = 'agreement', array $extra = []): array
{
    return ['key' => $key, 'name' => ['ar' => 'دفعة '.$key, 'en' => null], 'basis' => $basis, 'value' => $value, 'event' => $event, 'stage_key' => null, 'offset_days' => 0, 'grace_days' => 0, ...$extra];
}

/**
 * Saves a plan through the owner's action (P-104).
 *
 * @param  list<array<string, mixed>>  $lines
 */
function savePlan(User $actor, string $key, array $lines, bool $default = false, ?PaymentPlan $plan = null): PaymentPlan
{
    return app(SavePaymentPlan::class)->handle($actor, $plan, ['key' => $key, 'name' => ['ar' => 'خطة '.$key, 'en' => null], 'is_default' => $default, 'lines' => $lines], $plan?->lock_version);
}

/**
 * 40% at the agreement, 40% when installation starts, the rest 15 days after delivery with 3 grace days.
 */
function installmentPlan(User $actor, bool $default = true): PaymentPlan
{
    return savePlan($actor, 'forty_forty', [
        planLine('first', 'percent', '40'),
        planLine('second', 'percent', '40', 'stage.started', ['stage_key' => 'install']),
        planLine('final', 'rest', null, 'delivery.completed', ['offset_days' => 15, 'grace_days' => 3]),
    ], $default);
}

/**
 * Publishes the draft rules as the next configuration release (ADR-0040): drafts take effect only once published.
 */
function publishRules(User $actor, ?string $notes = null): ConfigRelease
{
    return app(PublishConfigRelease::class)->publish($actor, $notes, ConfigReleases::latest()->number ?? 0, ConfigSnapshot::hash(ConfigSnapshot::capture()));
}

/**
 * @return list<array{reference: string, amount: int, event: string, stage: string|null, offset: int, grace: int}>
 */
function planDues(int $orderId): array
{
    return array_values(OrderDue::query()->where('order_id', $orderId)->whereNull('removed_at')->orderBy('position')->get()
        ->map(fn (OrderDue $d) => ['reference' => $d->reference, 'amount' => $d->amount_minor, 'event' => $d->due_event->value, 'stage' => $d->stage_key, 'offset' => $d->offset_days, 'grace' => $d->grace_days])->all());
}
