<?php

namespace App\Actions\StateMachines\M08Document;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Agreements\AgreementWrite;
use App\Agreements\ContractContent;
use App\Agreements\Numbers;
use App\Agreements\OrderAgreement;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\StateMachine;
use App\Files\AttachmentService;
use App\Models\Agreements\AgreementDocument;
use App\Models\FinanceSettings;
use App\Models\Order;
use App\Models\User;
use App\Workflow\Transition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * M-08 creation for a contract (P-32, AGR-03, Q-36 c): optional per facility; built from the active agreement with
 * the parties' data copied in (later profile edits never change it), or an external contract file. No contract
 * templates in v1. Preparing a contract is not its approval: the customer approves it like any document (Q-40 a).
 */
final class T00PrepareContract
{
    public function __construct(
        private readonly AgreementWrite $write,
        private readonly Numbers $numbers,
        private readonly TransitionRecorder $transitions,
        private readonly AttachmentService $files,
    ) {}

    public function handle(User $actor, Order $order, ?UploadedFile $file = null, ?string $terms = null, ?string $idempotencyKey = null, string $source = TransitionRecorder::SOURCE_WEB): AgreementDocument
    {
        $terms = $terms === null || trim($terms) === '' ? null : mb_substr(trim($terms), 0, 5000);
        $payload = ['order_id' => $order->id, 'file' => $file === null ? null : hash_file('sha256', (string) $file->getRealPath()), 'terms' => $terms];

        $result = $this->write->run('contract.prepare', $actor, $idempotencyKey, $payload, function () use ($actor, $order, $file, $terms, $source) {
            $order = AgreementLock::order($order->id, StateMachine::M08, 'T00');
            Access::authorize($actor, Permission::P32, $order);
            if (! FinanceSettings::current()->contracts_enabled) {
                Transition::refuse(StateMachine::M08, 'T00', 'contracts_disabled');
            }
            $agreement = OrderAgreement::current($order);
            if ($file === null && $agreement === null) {
                Transition::refuse(StateMachine::M08, 'T00', 'no_agreement');
            }
            $open = AgreementDocument::query()->where('order_id', $order->id)->where('kind', DocumentKind::Contract->value)
                ->whereIn('status', [DocumentStatus::Draft->value, DocumentStatus::AwaitingCustomer->value])->exists();
            if ($open) {
                Transition::refuse(StateMachine::M08, 'T00', 'contract_open');
            }

            $content = $file === null
                ? ContractContent::fromAgreement($order, $agreement, $terms)
                : ['source' => 'file', 'description' => $terms, 'agreement_version' => $agreement?->version];
            $document = AgreementDocument::query()->create([
                'order_id' => $order->id, 'kind' => DocumentKind::Contract, 'status' => DocumentStatus::Draft,
                'number' => $this->numbers->document(DocumentKind::Contract), 'content' => $content, 'created_by' => $actor->id,
            ]);
            $attachment = $file === null ? null : $this->files->store($actor, $document, $file, 'contract');
            try {
                if ($attachment !== null) {
                    $document->forceFill(['content' => [...$content, 'file' => [
                        'uuid' => $attachment->uuid, 'name' => $attachment->original_name, 'sha256' => $attachment->sha256, 'size_bytes' => $attachment->size_bytes,
                    ]]])->save();
                }
                $this->transitions->record(StateMachine::M08, 'T00', $document, null, DocumentStatus::Draft, $actor, $source, context: ['order_id' => $order->id]);
                Audit::record(AuditAction::DocumentCreated, $order, $actor, after: ['document' => $document->number, 'kind' => 'contract', 'source' => $content['source']],
                    source: $source, label: $order->number);
            } catch (Throwable $e) {
                if ($attachment !== null) {
                    Storage::disk($attachment->disk)->delete($attachment->path);
                }

                throw $e;
            }

            return ['document_id' => $document->id];
        });

        return AgreementDocument::query()->findOrFail((int) $result['result']['document_id']);
    }
}
