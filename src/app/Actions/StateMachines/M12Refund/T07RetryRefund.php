<?php

namespace App\Actions\StateMachines\M12Refund;

use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Finance\FinanceLock;
use App\Finance\FinanceWrite;
use App\Models\Finance\Refund;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * M-12-T07 (P-43): retry after a final failure, with the new attempt's reference. The limit is checked again, since
 * the failure released the reservation and other refunds may have taken it meanwhile.
 */
final class T07RetryRefund
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

        $this->write->run('refund.retry', $actor, $idempotencyKey, [...$data, 'refund' => $refund->id, 'lock_version' => $lockVersion], function () use ($actor, $refund, $data, $lockVersion, $source) {
            [$order, $locked, $head] = RefundStep::lock($actor, $refund, Permission::P43, $lockVersion, 'T07');
            T01StartRefund::start($this->transitions, $locked, $data['reference'] ?? null, 'T07', $actor, $source);
            FinanceLock::touch($head);
            Audit::record(AuditAction::RefundRetried, $order, $actor, after: ['refund' => $locked->id, 'attempt' => $locked->attempt], source: $source, label: $order->number);

            return ['refund_id' => $locked->id];
        });

        return $refund->refresh();
    }
}
