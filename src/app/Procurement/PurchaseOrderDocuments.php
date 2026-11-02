<?php

namespace App\Procurement;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PurchaseStatus;
use App\Enums\StateMachine;
use App\Files\AttachmentService;
use App\Models\Branch;
use App\Models\Facility;
use App\Models\Procurement\PurchaseDocumentVersion;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderDocument;
use App\Models\Procurement\PurchaseOrderLine;
use App\Models\Procurement\PurchaseOrderSend;
use App\Models\Procurement\Supplier;
use App\Models\User;
use App\Notifications\Outbox;
use App\Support\StaleRecord;
use App\Support\Translatable\Translated;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The purchase order as a document (ADR-0038): generate and keep its PDF (one per content fingerprint, recording the
 * template version), preview the owner's layout, email it to the supplier through the outbox (queued, retried with
 * backoff, status on the send log) and save a new layout version. Every change is audited in its transaction.
 */
final class PurchaseOrderDocuments
{
    public const PURPOSE = 'purchase_order_pdf';

    public function __construct(
        private readonly PurchaseOrderPdf $pdf,
        private readonly AttachmentService $files,
        private readonly Outbox $outbox,
        private readonly ProcurementWrite $write,
    ) {}

    /**
     * The stored PDF of the order's current content under the current template (generated on first need).
     */
    public function document(User $actor, PurchaseOrder $po, string $locale): PurchaseOrderDocument
    {
        abort_unless(PurchaseAccess::canView($actor, $po), 403);
        $current = PurchaseDocumentLayout::current();
        $prices = $current['layout']['flags']['prices'] && PurchaseAccess::canSeeCosts($actor, $po);
        [$data, $images] = $this->pdf->data($po, $current['layout'], $current['version'], $locale, $prices);
        $fingerprint = hash('sha256', json_encode([$data, array_map(fn (string $b) => hash('sha256', $b), $images)], JSON_THROW_ON_ERROR));

        $existing = PurchaseOrderDocument::query()->where('purchase_order_id', $po->id)->where('fingerprint', $fingerprint)->first();
        if ($existing !== null) {
            return $existing;
        }

        $bytes = $this->pdf->render($po, $current['layout'], $current['version'], $locale, $prices);

        return DB::transaction(function () use ($actor, $po, $bytes, $current, $fingerprint, $locale) {
            $attachment = $this->files->storeGenerated($po, $bytes, $po->number.'.pdf', 'pdf', 'application/pdf', self::PURPOSE, $actor);

            return PurchaseOrderDocument::query()->firstOrCreate(
                ['purchase_order_id' => $po->id, 'fingerprint' => $fingerprint],
                ['template_version' => $current['version'], 'locale' => $locale, 'attachment_id' => $attachment->id, 'generated_by' => $actor->id],
            );
        });
    }

    /**
     * A preview of an unsaved layout on the viewer's latest purchase order (or a sample when they have none).
     *
     * @param  array<string, mixed>  $layout
     */
    public function preview(User $actor, array $layout, string $locale): string
    {
        $layout = PurchaseDocumentLayout::normalize($layout);
        $po = PurchaseAccess::scoped(PurchaseOrder::query(), $actor)->orderByDesc('id')->first() ?? self::sample();
        $prices = $layout['flags']['prices'] && ($po->exists ? PurchaseAccess::canSeeCosts($actor, $po) : true);

        return $this->pdf->render($po, $layout, PurchaseDocumentLayout::current()['version'] + 1, $locale, $prices);
    }

    /**
     * Emails the order's PDF to the supplier (P-46 on the order; confirmed and still open). Subject and body start
     * from the facility's texts and may be edited in the dialog. The same idempotency key never sends twice.
     *
     * @param  array{recipient?: string|null, subject?: string|null, body?: string|null, locale?: string|null}  $input
     */
    public function send(User $actor, PurchaseOrder $po, array $input, ?string $key, ?PurchaseOrderSend $retryOf = null): PurchaseOrderSend
    {
        Access::authorize($actor, Permission::P46, $po);
        if (! in_array($po->status, [PurchaseStatus::Ordered, PurchaseStatus::PartiallyReceived, PurchaseStatus::Received], true)) {
            throw new InvalidTransition(StateMachine::M13, 'email', 'not_from_'.$po->status->value);
        }
        $po->loadMissing(['supplier', 'branch']);
        $locale = in_array($input['locale'] ?? null, ['ar', 'en'], true) ? (string) $input['locale'] : 'ar';
        $recipient = trim((string) ($input['recipient'] ?? '')) ?: (string) $po->supplier->email;
        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['recipient' => __('purchase_document.error.recipient')]);
        }
        $defaults = self::texts($po, $locale);
        $subject = trim((string) ($input['subject'] ?? '')) ?: $defaults['subject'];
        $body = trim((string) ($input['body'] ?? '')) ?: $defaults['body'];

        $document = $this->document($actor, $po, $locale);

        $result = $this->write->run('po.email', $actor, $key, ['po' => $po->id, 'recipient' => $recipient, 'subject' => $subject, 'body' => $body, 'locale' => $locale, 'retry_of' => $retryOf?->id],
            function () use ($actor, $po, $document, $recipient, $subject, $body, $locale, $retryOf) {
                $send = PurchaseOrderSend::query()->create([
                    'purchase_order_id' => $po->id, 'purchase_order_document_id' => $document->id, 'recipient' => mb_substr($recipient, 0, 190),
                    'subject' => mb_substr($subject, 0, 200), 'body' => $body, 'locale' => $locale, 'retry_of' => $retryOf?->id, 'sent_by' => $actor->id,
                ]);
                $message = $this->outbox->enqueuePurchaseOrder($recipient, $send->id, 'purchase-order-send:'.$send->id);
                $send->forceFill(['outbox_message_id' => $message?->id])->save();
                Audit::record(AuditAction::PurchaseEmailed, $po, $actor, after: [
                    'number' => $po->number, 'send' => $send->id, 'document' => $document->id, 'template_version' => $document->template_version,
                    'retry_of' => $retryOf?->id,
                ], source: TransitionRecorder::SOURCE_WEB, branchIds: [$po->branch_id], label: $po->number);

                return ['send_id' => $send->id];
            });

        return PurchaseOrderSend::query()->findOrFail((int) $result['result']['send_id']);
    }

    /**
     * Saves the owner's layout and email texts as a new version (P-45, facility-wide). An unchanged save is a no-op.
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $email
     */
    public function saveLayout(User $actor, array $layout, array $email, int $baseVersion): int
    {
        Access::authorize($actor, Permission::P45);
        $clean = PurchaseDocumentLayout::validated($layout, $email);

        return DB::transaction(function () use ($actor, $clean, $baseVersion) {
            $current = PurchaseDocumentVersion::query()->lockForUpdate()->orderByDesc('version')->first();
            $currentNumber = $current->version ?? 0;
            if ($currentNumber !== $baseVersion) {
                throw new StaleRecord($currentNumber);
            }
            $before = PurchaseDocumentLayout::current();
            if ($before['layout'] == $clean['layout'] && $before['email'] == $clean['email']) {
                return $currentNumber;
            }
            $row = PurchaseDocumentVersion::query()->create([
                'version' => $currentNumber + 1, 'layout' => $clean['layout'], 'email' => $clean['email'], 'created_by' => $actor->id,
            ]);
            Audit::record(AuditAction::PurchaseDocumentSaved, $row, $actor,
                before: ['version' => $currentNumber, 'layout' => $before['layout'], 'email' => $before['email']],
                after: ['version' => $row->version, 'layout' => $clean['layout'], 'email' => $clean['email']],
                source: TransitionRecorder::SOURCE_WEB, label: 'v'.$row->version);

            return $row->version;
        });
    }

    /**
     * The facility's email texts for this order, placeholders filled.
     *
     * @return array{subject: string, body: string}
     */
    public static function texts(PurchaseOrder $po, string $locale): array
    {
        $email = PurchaseDocumentLayout::current()['email'];
        $facility = Facility::current();
        $values = [
            'number' => $po->number,
            'supplier' => (string) $po->supplier->name->get($locale),
            'facility' => $locale === 'en' && $facility?->name_en ? $facility->name_en : (string) $facility?->name_ar,
            'branch' => $locale === 'en' && $po->branch->name_en ? $po->branch->name_en : $po->branch->name_ar,
            'expected_on' => $po->expected_on?->toDateString() ?? '—',
        ];
        $pick = fn (string $field) => $locale === 'en' && ($email[$field]['en'] ?? '') !== '' ? $email[$field]['en'] : $email[$field]['ar'];

        return [
            'subject' => PurchaseDocumentLayout::fill($pick('subject'), $values),
            'body' => PurchaseDocumentLayout::fill($pick('body'), $values),
        ];
    }

    /**
     * An unsaved purchase order for previewing the layout before any order exists.
     */
    private static function sample(): PurchaseOrder
    {
        $po = new PurchaseOrder([
            'number' => 'PO-0000-000000', 'status' => PurchaseStatus::Ordered, 'expected_on' => now()->addDays(14)->toDateString(),
            'notes' => __('purchase_document.sample.notes'), 'gross_minor' => 1150000,
        ]);
        $po->forceFill(['id' => 0, 'created_at' => now()]);
        $po->setRelation('supplier', new Supplier(['name' => Translated::fromArray(['ar' => 'ورشة نموذجية', 'en' => 'Sample workshop'])->toArray(), 'phone' => '0500000000']));
        $po->setRelation('branch', Branch::query()->orderBy('id')->first() ?? new Branch(['name_ar' => 'الفرع الرئيسي']));
        $po->setRelation('lines', new Collection([new PurchaseOrderLine([
            'line_no' => 1, 'item_type' => PurchaseOrderLine::MATERIAL, 'item_id' => 0, 'unit' => 'piece', 'quantity_milli' => 1000,
            'item_name' => Translated::fromArray(['ar' => 'مطبخ حسب التصميم المعتمد', 'en' => 'Kitchen as per the approved design'])->toArray(),
            'unit_cost_minor' => 1000000, 'gross_minor' => 1150000,
        ])]));

        return $po;
    }
}
