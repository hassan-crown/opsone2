<?php

namespace App\Actions\StateMachines\M33Payout;

use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PayoutStatus;
use App\Enums\StateMachine;
use App\Files\AttachmentService;
use App\Finance\FinanceWrite;
use App\Models\Commissions\CommissionPayout;
use App\Models\User;
use App\Money\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * M-33-T04 (P-97): document what really happened to a recorded payout — the amount that actually left (0 when it
 * never left or came back), a reason and a proof. The original stays as it was; the difference becomes a
 * member-level adjustment of the balance (a returned or unpaid amount is available again, an amount paid beyond
 * the lines is carried as negative). Once.
 */
final class T04CorrectPayout
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly AttachmentService $files,
    ) {}

    /**
     * @param  array<string, mixed>  $data  {amount: "0.00", reason}
     */
    public function handle(User $actor, CommissionPayout $payout, array $data, UploadedFile $proof, ?int $lockVersion, ?string $key, string $source = TransitionRecorder::SOURCE_WEB): CommissionPayout
    {
        /** @var array{amount: string, reason: string} $data */
        $data = Validator::make([...$data, 'proof' => $proof], [
            'amount' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:500'],
            'proof' => ['required', 'file'],
        ])->validate();
        try {
            $amount = Money::fromInput(trim($data['amount']));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['amount' => __('commissions.validation.amount')]);
        }
        if ($amount->isNegative()) {
            throw ValidationException::withMessages(['amount' => __('commissions.validation.amount')]);
        }
        $payload = ['amount' => $amount->minor, 'reason' => $data['reason'], 'payout' => $payout->id, 'lock_version' => $lockVersion,
            'proof' => hash_file('sha256', (string) $proof->getRealPath())];

        $this->write->run('commission.payout.correct', $actor, $key, $payload, function () use ($actor, $payout, $data, $amount, $proof, $lockVersion, $source) {
            $fresh = PayoutLock::payout($actor, $payout, $lockVersion, 'T04', Permission::P97);
            $this->files->store($actor, $fresh, $proof, 'commission_payout_correction');
            $fresh->forceFill([
                'status' => PayoutStatus::Corrected, 'corrected_minor' => $amount->minor, 'reason' => $data['reason'],
                'closed_by' => $actor->id, 'closed_at' => now(), 'lock_version' => $fresh->lock_version + 1,
            ])->save();
            $this->transitions->record(StateMachine::M33, 'T04', $fresh, PayoutStatus::Paid, PayoutStatus::Corrected, $actor, $source, $data['reason'], [
                'amount_minor' => $fresh->amount_minor, 'corrected_minor' => $amount->minor,
            ]);
            Audit::record(AuditAction::CommissionPayoutCorrected, $fresh, $actor, before: ['amount_minor' => $fresh->amount_minor],
                after: ['corrected_minor' => $amount->minor], reason: $data['reason'], source: $source, label: $fresh->member->name);

            return ['payout_id' => $fresh->id];
        });

        return $payout->refresh();
    }
}
