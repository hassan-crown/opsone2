<?php

namespace App\Actions\StateMachines\M33Payout;

use App\Access\Exceptions\InvalidTransition;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Commissions\CommissionBalances;
use App\Enums\PayoutStatus;
use App\Enums\StateMachine;
use App\Finance\FinanceWrite;
use App\Models\Commissions\CommissionPayout;
use App\Models\Commissions\CommissionPayoutLine;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * M-33-T01 (P-96): approve the payout list — the balance is derived again under the member's and their orders'
 * locks, every payable line is held at its amount and the total must be positive (`nothing_available`). Held
 * orders (open settlement, refund or dispute) stay out. With execution details and a proof it continues with T02 in
 * the same action, as the machine allows.
 */
final class T01ApprovePayout
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly CommissionBalances $balances,
        private readonly T02RecordPayout $record,
    ) {}

    /**
     * @param  array<string, mixed>|null  $execution  T02 fields when paying at once
     */
    public function handle(User $actor, CommissionPayout $payout, ?int $lockVersion, ?string $key, ?array $execution = null, ?UploadedFile $proof = null, string $source = TransitionRecorder::SOURCE_WEB): CommissionPayout
    {
        if ($execution !== null) {
            $execution = T02RecordPayout::validate($execution, $proof);
        }
        $payload = ['payout' => $payout->id, 'lock_version' => $lockVersion, 'execution' => $execution,
            'proof' => $proof === null ? null : hash_file('sha256', (string) $proof->getRealPath())];

        $this->write->run('commission.payout.approve', $actor, $key, $payload, function () use ($actor, $payout, $lockVersion, $execution, $proof, $source) {
            $fresh = PayoutLock::payout($actor, $payout, $lockVersion, 'T01');
            PayoutLock::orders($fresh->user_id);
            $payable = $this->balances->payable($fresh->user_id, $fresh->period_to);
            if ($payable['amount_minor'] <= 0) {
                throw new InvalidTransition(StateMachine::M33, 'T01', 'nothing_available');
            }
            foreach ($payable['lines'] as $line) {
                CommissionPayoutLine::query()->create([...$line, 'payout_id' => $fresh->id, 'created_at' => now()]);
            }
            $fresh->forceFill([
                'status' => PayoutStatus::Reserved, 'amount_minor' => $payable['amount_minor'], 'reserved_by' => $actor->id,
                'reserved_at' => now(), 'lock_version' => $fresh->lock_version + 1,
            ])->save();
            $this->transitions->record(StateMachine::M33, 'T01', $fresh, PayoutStatus::Draft, PayoutStatus::Reserved, $actor, $source, null, [
                'amount_minor' => $payable['amount_minor'], 'lines' => count($payable['lines']), 'held' => count($payable['held']),
            ]);
            Audit::record(AuditAction::CommissionPayoutReserved, $fresh, $actor, after: [
                'amount_minor' => $payable['amount_minor'], 'held' => $payable['held'],
            ], source: $source, label: $fresh->member->name);

            if ($execution !== null && $proof !== null) {
                $this->record->apply($actor, $fresh, $execution, $proof, $source);
            }

            return ['payout_id' => $fresh->id];
        });

        return $payout->refresh();
    }
}
