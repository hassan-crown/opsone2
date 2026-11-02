<?php

use App\Actions\Rules\SavePaymentPlan;
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
 * Saves a plan through the owner's action (P-103).
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
