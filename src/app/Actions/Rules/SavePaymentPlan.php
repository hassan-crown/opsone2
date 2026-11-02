<?php

namespace App\Actions\Rules;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Finance\Plans\PaymentPlans;
use App\Models\Finance\PaymentPlan;
use App\Models\User;
use App\Support\StaleRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a payment plan (P-104, ADR-0037). The lines are checked by PaymentPlans (100%, one `rest` last,
 * closed triggers, existing stages, every flow gate reference present). One active plan is the default: marking
 * one moves the mark, and the first plan becomes the default. Orders already holding a schedule never change.
 */
final class SavePaymentPlan
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, ?PaymentPlan $plan, array $data, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): PaymentPlan
    {
        RulesAccess::authorize($actor);
        $data = Validator::make($data, PaymentPlans::rules())->validate();
        $lines = PaymentPlans::normalize($data['lines']);

        return DB::transaction(function () use ($actor, $plan, $data, $lines, $lockVersion, $source) {
            if ($plan !== null) {
                $plan = PaymentPlan::query()->lockForUpdate()->findOrFail($plan->id);
                if ($lockVersion !== null && $lockVersion !== $plan->lock_version) {
                    throw new StaleRecord($plan->lock_version);
                }
            } elseif (PaymentPlan::query()->active()->count() >= PaymentPlans::MAX_ACTIVE) {
                throw ValidationException::withMessages(['key' => __('finance.plans.validation.too_many', ['max' => PaymentPlans::MAX_ACTIVE])]);
            }
            $taken = PaymentPlan::query()->where('key', $data['key'])->when($plan !== null, fn ($q) => $q->whereKeyNot($plan->id))->exists();
            if ($taken) {
                throw ValidationException::withMessages(['key' => __('finance.plans.validation.key_taken')]);
            }

            $before = $plan === null ? [] : self::snapshot($plan);
            $plan ??= new PaymentPlan;
            $otherDefault = PaymentPlan::query()->active()->where('is_default', true)->whereKeyNot($plan->id ?? 0)->exists();
            $default = $plan->archived_at === null && ((bool) ($data['is_default'] ?? $plan->is_default) || ! $otherDefault);
            if ($default) {
                PaymentPlan::query()->where('is_default', true)->whereKeyNot($plan->id ?? 0)->update(['is_default' => false]);
            }
            $plan->forceFill([
                'key' => $data['key'], 'name' => ['ar' => $data['name']['ar'], 'en' => $data['name']['en'] ?? null], 'lines' => $lines,
                'is_default' => $default, 'updated_by' => $actor->id, 'lock_version' => $plan->exists ? $plan->lock_version + 1 : 1,
            ])->save();

            Audit::record(AuditAction::PaymentPlanSaved, $plan, $actor, before: $before, after: self::snapshot($plan), source: $source, label: $plan->key);

            return $plan;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(PaymentPlan $plan): array
    {
        return ['key' => $plan->key, 'is_default' => $plan->is_default, 'lines' => $plan->lines, 'archived' => $plan->archived_at !== null];
    }
}
