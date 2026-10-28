<?php

namespace App\Actions\StateMachines\M39Dispute;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DisputeOutcome;
use App\Files\AttachmentService;
use App\Finance\FinanceWrite;
use App\Finance\Settlement\SettlementInput;
use App\Models\Finance\FinancialDispute;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * M-39-T03 (P-85, FinDisputeResolve): resolved with what was verified and the proof of the treatment. The disputed part
 * returns to reminders and reports (no reminder is sent at once). An upheld dispute does not refund or correct by
 * itself: the person opens the refund (M-12) or the correction (M-11-T07) from its own action.
 */
final class T03ResolveDispute
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly AttachmentService $files,
    ) {}

    /**
     * @param  array<string, mixed>  $data  {outcome, resolution}
     */
    public function handle(User $actor, FinancialDispute $dispute, array $data, UploadedFile $proof, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): FinancialDispute
    {
        /** @var array{outcome: string, resolution: string} $data */
        $data = Validator::make($data, [
            'outcome' => ['required', 'in:'.implode(',', array_column(DisputeOutcome::cases(), 'value'))],
            'resolution' => ['required', 'string', 'max:2000'],
        ])->validate();
        $resolution = SettlementInput::reason($data['resolution'], 'resolution', 2000);
        $payload = [...$data, 'dispute' => $dispute->id, 'lock_version' => $lockVersion, 'proof' => hash_file('sha256', (string) $proof->getRealPath())];

        $this->write->run('dispute.resolve', $actor, $idempotencyKey, $payload, function () use ($actor, $dispute, $data, $resolution, $proof, $lockVersion, $source) {
            [$order, $locked] = DisputeStep::lock($actor, $dispute, $lockVersion, 'T03');
            $this->files->store($actor, $locked, $proof, 'dispute_resolution');
            DisputeStep::move($this->transitions, $locked, 'T03', [
                'outcome' => DisputeOutcome::from($data['outcome']), 'resolution' => $resolution, 'resolved_by' => $actor->id, 'resolved_at' => now(),
            ], $actor, $source, $resolution);
            Audit::record(AuditAction::DisputeResolved, $order, $actor, after: ['dispute' => $locked->id, 'outcome' => $data['outcome']], reason: $resolution, source: $source, label: $order->number);

            return ['dispute_id' => $locked->id];
        });

        return $dispute->refresh();
    }
}
