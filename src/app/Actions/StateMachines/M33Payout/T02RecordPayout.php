<?php

namespace App\Actions\StateMachines\M33Payout;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PayoutStatus;
use App\Enums\StateMachine;
use App\Files\AttachmentService;
use App\Finance\FinanceWrite;
use App\Models\Commissions\CommissionPayout;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * M-33-T02 (P-96): record the payout as executed outside OpsOne — method, date, reference and a proof file. It is
 * the record of money that really left: no bank transfer is implied, it is never refused for a balance that changed
 * after the reservation (a later refund shows as a negative difference carried on the statement), and it happens
 * once — a double submit replays, another attempt is refused from `paid` without a side effect.
 */
final class T02RecordPayout
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly AttachmentService $files,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{method: string, paid_on: string, reference: string, note?: string|null}
     */
    public static function validate(array $data, ?UploadedFile $proof): array
    {
        /** @var array{method: string, paid_on: string, reference: string, note?: string|null} */
        return Validator::make([...$data, 'proof' => $proof], [
            'method' => ['required', Rule::in(CommissionPayout::METHODS)],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['required', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:500'],
            'proof' => ['required', 'file'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, CommissionPayout $payout, array $data, UploadedFile $proof, ?int $lockVersion, ?string $key, string $source = TransitionRecorder::SOURCE_WEB): CommissionPayout
    {
        $data = self::validate($data, $proof);
        $payload = [...$data, 'payout' => $payout->id, 'lock_version' => $lockVersion, 'proof' => hash_file('sha256', (string) $proof->getRealPath())];

        $this->write->run('commission.payout.record', $actor, $key, $payload, function () use ($actor, $payout, $data, $proof, $lockVersion, $source) {
            $fresh = PayoutLock::payout($actor, $payout, $lockVersion, 'T02');
            $this->apply($actor, $fresh, $data, $proof, $source);

            return ['payout_id' => $fresh->id];
        });

        return $payout->refresh();
    }

    /**
     * @param  array{method: string, paid_on: string, reference: string, note?: string|null}  $data
     */
    public function apply(User $actor, CommissionPayout $payout, array $data, UploadedFile $proof, string $source): void
    {
        $this->files->store($actor, $payout, $proof, 'commission_payout');
        $payout->forceFill([
            'status' => PayoutStatus::Paid, 'method' => $data['method'], 'paid_on' => $data['paid_on'],
            'reference' => $data['reference'], 'note' => $data['note'] ?? null, 'paid_by' => $actor->id, 'paid_at' => now(),
            'lock_version' => $payout->lock_version + 1,
        ])->save();
        $this->transitions->record(StateMachine::M33, 'T02', $payout, PayoutStatus::Reserved, PayoutStatus::Paid, $actor, $source, null, [
            'amount_minor' => $payout->amount_minor, 'method' => $data['method'],
        ]);
        Audit::record(AuditAction::CommissionPayoutPaid, $payout, $actor, after: [
            'amount_minor' => $payout->amount_minor, 'method' => $data['method'], 'paid_on' => $data['paid_on'], 'reference' => $data['reference'],
        ], source: $source, label: $payout->member->name);
    }
}
