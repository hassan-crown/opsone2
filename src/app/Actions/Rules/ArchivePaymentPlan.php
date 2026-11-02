<?php

namespace App\Actions\Rules;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Finance\PaymentPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Archives a plan (P-104): it is no longer offered, and an order that chose it before its agreement gets the default.
 * The default plan cannot be archived — mark another one first. Orders whose schedule was copied keep it.
 */
final class ArchivePaymentPlan
{
    public function handle(User $actor, PaymentPlan $plan, string $source = TransitionRecorder::SOURCE_WEB): void
    {
        RulesAccess::authorize($actor);

        DB::transaction(function () use ($actor, $plan, $source) {
            $plan = PaymentPlan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($plan->is_default) {
                throw ValidationException::withMessages(['plan' => __('finance.plans.validation.archive_default')]);
            }
            if ($plan->archived_at !== null) {
                return;
            }
            $plan->forceFill(['archived_at' => now(), 'lock_version' => $plan->lock_version + 1, 'updated_by' => $actor->id])->save();
            Audit::record(AuditAction::PaymentPlanArchived, $plan, $actor, after: ['key' => $plan->key], source: $source, label: $plan->key);
        });
    }
}
