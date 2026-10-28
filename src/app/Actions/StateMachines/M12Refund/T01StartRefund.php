<?php

namespace App\Actions\StateMachines\M12Refund;

use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Finance\FinanceWrite;
use App\Finance\Settlement\RefundLimits;
use App\Models\Finance\Refund;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * M-12-T01 (P-43): the refund is sent (in v1 paid outside the system, Q-42). The limit is checked again — the
 * reservation of this refund counts once. The money is still on the order until T02.
 */
final class T01StartRefund
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly TransitionRecorder $transitions,
    ) {}

    /**
     * @param  array<string, mixed>  $data  {reference?}
     */
    public function handle(User $actor, Refund $refund, array $data, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): Refund
    {
        /** @var array{reference?: string|null} $data */
        $data = Validator::make($data, ['reference' => ['nullable', 'string', 'max:80']])->validate();

        $this->write->run('refund.start', $actor, $idempotencyKey, [...$data, 'refund' => $refund->id, 'lock_version' => $lockVersion], function () use ($actor, $refund, $data, $lockVersion, $source) {
            [$order, $locked, $head] = RefundStep::lock($actor, $refund, Permission::P43, $lockVersion, 'T01');
            self::start($this->transitions, $locked, $data['reference'] ?? null, 'T01', $actor, $source);
            FinanceLock::touch($head);
            Audit::record(AuditAction::RefundStarted, $order, $actor, after: ['refund' => $locked->id, 'attempt' => $locked->attempt], source: $source, label: $order->number);

            return ['refund_id' => $locked->id];
        });

        return $refund->refresh();
    }

    /**
     * T01 and T07 (retry): re-check the limit with this refund's own reservation left out, then a new attempt.
     */
    public static function start(TransitionRecorder $transitions, Refund $refund, ?string $reference, string $code, ?User $actor, string $source): void
    {
        $payment = $refund->payment;
        if (RefundLimits::refundable($payment, $refund->order_id, $refund->id)->compare($refund->amount()) < 0) {
            throw new InvalidTransition(StateMachine::M12, $code, 'not_refundable');
        }
        $attempt = $refund->attempt + 1;
        $from = RefundStep::move($refund, $code, ['attempt' => $attempt, 'reference' => $reference, 'failure_reason' => null]);
        $transitions->record(StateMachine::M12, $code, $refund, $from, $refund->status, $actor, $source, null, ['attempt' => $attempt, 'reference' => $reference]);
    }
}
