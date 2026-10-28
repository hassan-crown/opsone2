<?php

namespace App\Actions\StateMachines\M12Refund;

use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\RefundStatus;
use App\Enums\StateMachine;
use App\Files\AttachmentService;
use App\Finance\FinanceWrite;
use App\Finance\Settlement\RefundPosting;
use App\Models\Finance\Refund;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * M-12-T02 (P-43 with external proof, FinRefundExec): the refund was paid. The net collected and the allocation
 * change once (RefundPosting) and the voucher RF-YYYY-000123 is issued. From a request it records T01 first in the
 * same transaction (sent and confirmed at once, the usual manual case). The request itself is never the result.
 */
final class T02ConfirmRefund
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly RefundPosting $posting,
        private readonly AttachmentService $files,
    ) {}

    /**
     * @param  array<string, mixed>  $data  {reference, executed_on?, note?}
     */
    public function handle(User $actor, Refund $refund, array $data, UploadedFile $proof, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): Refund
    {
        /** @var array{reference: string, note?: string|null} $data */
        $data = Validator::make($data, ['reference' => ['required', 'string', 'max:80'], 'note' => ['nullable', 'string', 'max:1000']])->validate();
        $payload = [...$data, 'refund' => $refund->id, 'lock_version' => $lockVersion, 'proof' => hash_file('sha256', (string) $proof->getRealPath())];

        $this->write->run('refund.confirm', $actor, $idempotencyKey, $payload, function () use ($actor, $refund, $data, $proof, $lockVersion, $source) {
            $code = $refund->refresh()->status === RefundStatus::Requested ? 'T01' : 'T02';
            [$order, $locked, $head] = RefundStep::lock($actor, $refund, Permission::P43, $lockVersion, $code);
            if ($code === 'T01') {
                T01StartRefund::start($this->transitions, $locked, $data['reference'], 'T01', $actor, $source);
            }
            $this->files->store($actor, $locked, $proof, 'refund_proof');
            $number = $this->posting->execute($locked, $order, $head, 'T02', $actor, $source);
            $from = RefundStep::move($locked, 'T02', ['reference' => $data['reference']]);
            $this->transitions->record(StateMachine::M12, 'T02', $locked, $from, RefundStatus::Executed, $actor, $source, $data['note'] ?? null, ['number' => $number, 'reference' => $data['reference']]);
            Audit::record(AuditAction::RefundExecuted, $order, $actor, after: [
                'refund' => $locked->id, 'number' => $number, 'amount_minor' => $locked->amount_minor, 'payment' => $locked->payment_id,
            ], source: $source, label: $order->number);

            return ['refund_id' => $locked->id, 'number' => $number];
        });

        return $refund->refresh();
    }
}
