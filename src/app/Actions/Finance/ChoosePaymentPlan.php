<?php

namespace App\Actions\Finance;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\OrderAgreement;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Finance\FinanceWrite;
use App\Finance\OrderSchedule;
use App\Models\Finance\PaymentPlan;
use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The order's payment plan (P-38, ADR-0037). Before the agreement it is only recorded: the approval copies its lines
 * into the schedule. Once the schedule exists, choosing a plan replaces the schedule from its lines under the same
 * rules as FinSchedule (never above the agreement, never below what a due already holds).
 */
final class ChoosePaymentPlan
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly OrderSchedule $schedule,
    ) {}

    public function handle(User $actor, Order $order, int $planId, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): void
    {
        $this->write->run('schedule.plan', $actor, $idempotencyKey, ['order_id' => $order->id, 'plan_id' => $planId, 'lock_version' => $lockVersion], function () use ($actor, $order, $planId, $lockVersion, $source) {
            $order = FinanceLock::order($order->id, StateMachine::M11, 'schedule');
            Access::authorize($actor, Permission::P38, $order);
            $head = FinanceLock::head($order);
            FinanceLock::checkVersion($head, $lockVersion);
            $plan = PaymentPlan::query()->active()->find($planId)
                ?? throw ValidationException::withMessages(['plan_id' => __('finance.plans.validation.unknown')]);

            $this->schedule->sync($order, $head, $actor, $source);
            $agreement = OrderAgreement::current($order);
            if ($agreement === null) {
                $before = $head->payment_plan_key;
                $head->forceFill(['payment_plan_id' => $plan->id, 'payment_plan_key' => $plan->key])->save();
                Audit::record(AuditAction::PaymentPlanChosen, $order, $actor, before: ['plan' => $before], after: ['plan' => $plan->key], source: $source, label: $order->number);
            } else {
                $this->schedule->applyPlan($order, $head, $agreement, $plan, $actor, $source);
            }
            FinanceLock::touch($head);

            return ['order_id' => $order->id];
        });
    }
}
