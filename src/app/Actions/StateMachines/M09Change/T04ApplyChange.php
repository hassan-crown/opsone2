<?php

namespace App\Actions\StateMachines\M09Change;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Actions\StateMachines\M08Document\T02RecordExternalApproval;
use App\Agreements\AgreementLock;
use App\Agreements\AgreementWrite;
use App\Agreements\ChangeApplication;
use App\Files\AttachmentService;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementChange;
use App\Models\Agreements\CustomerDecision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * M-09-T04 by staff (P-36 with P-34, AGR-05/07): record the customer's external approval of the presented change
 * with its evidence and apply it once — a new agreement version, the earlier one kept, the difference handed to
 * finance (settlement when it falls below what was collected; never an automatic refund) and the held work released.
 * Approving content older than the latest presentation or built on an older agreement is refused.
 */
final class T04ApplyChange
{
    public function __construct(
        private readonly AgreementWrite $write,
        private readonly ChangeApplication $application,
        private readonly AttachmentService $files,
    ) {}

    /**
     * @param  array{channel: string, decided_on: string, note?: string|null, content_hash: string}  $data
     */
    public function handle(User $actor, AgreementChange $change, array $data, UploadedFile $evidence, ?int $lockVersion, ?string $idempotencyKey = null, string $source = TransitionRecorder::SOURCE_WEB): Agreement
    {
        /** @var array{channel: string, decided_on: string, note?: string|null, content_hash: string} $data */
        $data = Validator::make($data, T02RecordExternalApproval::rules())->validate();
        $payload = [...$data, 'change_id' => $change->id, 'lock_version' => $lockVersion, 'evidence' => hash_file('sha256', (string) $evidence->getRealPath())];

        $result = $this->write->run('change.apply', $actor, $idempotencyKey, $payload, function () use ($actor, $change, $data, $evidence, $lockVersion, $source) {
            [$order, $change] = AgreementLock::change($change, $lockVersion, 'T04');
            Access::authorize($actor, Permission::P36, $order);
            Access::authorize($actor, Permission::P34, $order);
            ChangeApplication::ensureDecidable($change, $data['content_hash'], 'T04');

            $agreement = $this->application->apply($order, $change, [
                'method' => CustomerDecision::METHOD_EXTERNAL, 'channel' => $data['channel'],
                'decided_at' => CarbonImmutable::parse($data['decided_on']), 'recorded_by' => $actor->id, 'note' => $data['note'] ?? null,
            ], $actor, $source);
            $decision = CustomerDecision::query()->findOrFail($agreement->customer_decision_id);
            $decision->setRelation('order', $order);
            $this->files->store($actor, $decision, $evidence, 'approval_evidence');

            return ['agreement_id' => $agreement->id];
        });

        return Agreement::query()->findOrFail((int) $result['result']['agreement_id']);
    }
}
