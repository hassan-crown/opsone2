<?php

namespace App\Actions\StateMachines\M08Document;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Agreements\AgreementWrite;
use App\Agreements\DocumentApproval;
use App\Files\AttachmentService;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\CustomerDecision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * M-08-T02 by staff (P-34, AGR-05, Q-40 a): record that the customer approved the sent content outside OpsOne (a
 * message, paper, a call), with the channel, date and evidence. It satisfies the approval itself — no second review
 * — and never needs the customer to sign in. The screen sends the content hash it showed: approving content other
 * than what was sent is refused. Approving a quote fixes the order's agreement once (AGR-01, AGR-09).
 */
final class T02RecordExternalApproval
{
    public function __construct(
        private readonly AgreementWrite $write,
        private readonly DocumentApproval $approval,
        private readonly AttachmentService $files,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'channel' => ['required', 'in:'.implode(',', CustomerDecision::CHANNELS)],
            'decided_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
            'content_hash' => ['required', 'string', 'size:64'],
        ];
    }

    /**
     * @param  array{channel: string, decided_on: string, note?: string|null, content_hash: string}  $data
     */
    public function handle(User $actor, AgreementDocument $document, array $data, UploadedFile $evidence, ?int $lockVersion, ?string $idempotencyKey = null, string $source = TransitionRecorder::SOURCE_WEB): AgreementDocument
    {
        /** @var array{channel: string, decided_on: string, note?: string|null, content_hash: string} $data */
        $data = Validator::make($data, self::rules())->validate();
        $payload = [...$data, 'document_id' => $document->id, 'lock_version' => $lockVersion, 'evidence' => hash_file('sha256', (string) $evidence->getRealPath())];

        $this->write->run('document.external_approval', $actor, $idempotencyKey, $payload, function () use ($actor, $document, $data, $evidence, $lockVersion, $source) {
            [$order, $document] = AgreementLock::document($document, $lockVersion, 'T02');
            Access::authorize($actor, Permission::P34, $order);
            DocumentApproval::ensureDecidable($document, $data['content_hash'], 'T02');

            $decision = $this->approval->approve($order, $document, [
                'method' => CustomerDecision::METHOD_EXTERNAL,
                'channel' => $data['channel'],
                'decided_at' => CarbonImmutable::parse($data['decided_on']),
                'recorded_by' => $actor->id,
                'note' => $data['note'] ?? null,
            ], $actor, $source);
            $decision->setRelation('order', $order);
            // Last step: if storing the evidence fails, the whole approval rolls back with it.
            $this->files->store($actor, $decision, $evidence, 'approval_evidence');

            return ['document_id' => $document->id, 'decision_id' => $decision->id];
        });

        return $document->refresh();
    }
}
