<?php

namespace App\Actions\StateMachines\M08Document;

use App\Access\Access;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Agreements\AgreementWrite;
use App\Agreements\Numbers;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\StateMachine;
use App\Files\AttachmentService;
use App\Models\Agreements\AgreementDocument;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Workflow\Transition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * M-08 creation for a design (P-33, AGR-03): a draft holding the design file as a private attachment, the lines it
 * concerns and the description shown to the customer. Uploading is not approval; a later file for the same design
 * is a new draft that replaces the earlier one when sent or approved (AGR-05, AGR-06).
 */
final class T00UploadDesign
{
    public function __construct(
        private readonly AgreementWrite $write,
        private readonly Numbers $numbers,
        private readonly TransitionRecorder $transitions,
        private readonly AttachmentService $files,
    ) {}

    /**
     * @param  list<int>  $lineIds
     */
    public function handle(User $actor, Order $order, UploadedFile $file, string $description, array $lineIds = [], ?AgreementDocument $replaces = null, ?string $idempotencyKey = null, string $source = TransitionRecorder::SOURCE_WEB): AgreementDocument
    {
        $description = trim($description);
        if ($description === '' || mb_strlen($description) > 2000) {
            throw ValidationException::withMessages(['description' => __('agreements.validation.description')]);
        }
        $payload = ['order_id' => $order->id, 'file' => hash_file('sha256', (string) $file->getRealPath()), 'description' => $description,
            'lines' => $lineIds, 'replaces' => $replaces?->id];

        $result = $this->write->run('design.upload', $actor, $idempotencyKey, $payload, function () use ($actor, $order, $file, $description, $lineIds, $replaces, $source) {
            $order = AgreementLock::order($order->id, StateMachine::M08, 'T00');
            $known = OrderLine::query()->where('order_id', $order->id)->whereNull('removed_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (array_diff($lineIds, $known) !== []) {
                throw ValidationException::withMessages(['lines' => __('agreements.validation.order_line')]);
            }
            if ($replaces !== null) {
                $replaces = AgreementDocument::query()->lockForUpdate()->findOrFail($replaces->id);
                if ($replaces->order_id !== $order->id || $replaces->kind !== DocumentKind::Design
                    || ! in_array($replaces->status, [DocumentStatus::AwaitingCustomer, DocumentStatus::ChangesRequested, DocumentStatus::Approved], true)) {
                    Transition::refuse(StateMachine::M08, 'T00', 'not_replaceable');
                }
            }

            $document = new AgreementDocument([
                'order_id' => $order->id, 'kind' => DocumentKind::Design, 'status' => DocumentStatus::Draft,
                'replaces_id' => $replaces?->id, 'created_by' => $actor->id,
            ]);
            $document->setRelation('order', $order);
            Access::authorize($actor, DocumentKind::Design->preparePermission(), $document);

            $document->forceFill(['number' => $this->numbers->document(DocumentKind::Design), 'content' => ['description' => $description, 'order_line_ids' => $lineIds]])->save();
            $attachment = $this->files->store($actor, $document, $file, 'design');
            try {
                $document->forceFill(['content' => [...$document->content, 'file' => [
                    'uuid' => $attachment->uuid, 'name' => $attachment->original_name, 'sha256' => $attachment->sha256, 'size_bytes' => $attachment->size_bytes,
                ]]])->save();
                $this->transitions->record(StateMachine::M08, 'T00', $document, null, DocumentStatus::Draft, $actor, $source, context: ['order_id' => $order->id]);
                Audit::record(AuditAction::DocumentCreated, $order, $actor, after: ['document' => $document->number, 'kind' => 'design'], source: $source, label: $order->number);
            } catch (Throwable $e) {
                Storage::disk($attachment->disk)->delete($attachment->path);

                throw $e;
            }

            return ['document_id' => $document->id];
        });

        return AgreementDocument::query()->findOrFail((int) $result['result']['document_id']);
    }
}
