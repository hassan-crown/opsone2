<?php

namespace App\Actions\StateMachines\M40Document;

use App\Access\Exceptions\InvalidTransition;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ExternalDocumentStatus;
use App\Enums\StateMachine;
use App\Files\AttachmentService;
use App\Finance\FinanceWrite;
use App\Finance\Settlement\SettlementInput;
use App\Models\Finance\ExternalDocument;
use App\Models\Order;
use App\Models\User;
use App\Workflow\AppointmentBooking;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * M-40-T01 / T04 (P-44, FinInvoiceLink): link the document the accounting system issued — source, number, date,
 * amount, currency and file — to a requested document (T01), as a new one (T01 without a request), or again after a
 * correction request (T04, the earlier file kept). The number is unique per source (`duplicate_number`). Linking is
 * not a compliance check; a missing customer VAT number is a warning, never a block.
 */
final class T01AttachDocument
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly TransitionRecorder $transitions,
        private readonly AttachmentService $files,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'source' => ['required', 'string', 'max:60'],
            'number' => ['required', 'string', 'max:60'],
            'issued_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.AppointmentBooking::today()],
            'amount' => ['required', 'string', 'max:20'],
            'currency' => ['nullable', 'in:SAR'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  rules() + DocumentLinks::rules() (the links only for a new document)
     */
    public function handle(User $actor, Order $order, ?ExternalDocument $document, array $data, UploadedFile $file, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): ExternalDocument
    {
        $data = Validator::make($data, self::rules() + ($document === null ? DocumentLinks::rules() : []))->validate();
        $amount = SettlementInput::money((string) $data['amount'], 'amount');
        $sourceKey = mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $data['source'])));
        $numberKey = mb_strtoupper((string) preg_replace('/\s+/u', '', (string) $data['number']));
        $payload = [...$data, 'order_id' => $order->id, 'document' => $document?->id, 'lock_version' => $lockVersion, 'file' => hash_file('sha256', (string) $file->getRealPath())];

        $result = $this->write->run('document.attach', $actor, $idempotencyKey, $payload, function () use ($actor, $order, $document, $data, $amount, $sourceKey, $numberKey, $file, $lockVersion, $source) {
            if ($document === null) {
                $locked = DocumentStep::order($actor, $order->id, 'T01');
                $target = new ExternalDocument([...DocumentLinks::resolve($locked, $data), 'order_id' => $locked->id]);
                $code = 'T01';
            } else {
                $code = $document->refresh()->status === ExternalDocumentStatus::NeedsCorrection ? 'T04' : 'T01';
                [$locked, $target] = DocumentStep::lock($actor, $document, $lockVersion, $code);
            }
            if ($document !== null && $document->order_id !== $order->id) {
                throw new InvalidTransition(StateMachine::M40, $code, 'other_order');
            }
            $taken = ExternalDocument::query()->where('source_key', $sourceKey)->where('number_key', $numberKey)
                ->when($target->exists, fn ($q) => $q->whereKeyNot($target->id))->exists();
            if ($taken) {
                throw new InvalidTransition(StateMachine::M40, $code, 'duplicate_number');
            }
            $target->setRelation('order', $locked);
            DocumentStep::move($this->transitions, $target, $code, [
                'source' => trim((string) $data['source']), 'source_key' => $sourceKey, 'number' => trim((string) $data['number']), 'number_key' => $numberKey,
                'issued_on' => $data['issued_on'], 'amount_minor' => $amount->minor, 'currency' => $data['currency'] ?? 'SAR',
                'attached_by' => $actor->id, 'correction_reason' => $code === 'T04' ? $target->correction_reason : null,
            ], $actor, $source);
            $this->files->store($actor, $target, $file, 'external_document');
            Audit::record(AuditAction::ExternalDocumentAttached, $locked, $actor, after: [
                'document' => $target->id, 'source' => $target->source, 'number' => $target->number, 'amount_minor' => $amount->minor,
            ], source: $source, label: $locked->number);

            return ['document_id' => $target->id];
        });

        return ExternalDocument::query()->findOrFail((int) $result['result']['document_id']);
    }
}
