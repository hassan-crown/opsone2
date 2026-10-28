<?php

namespace App\Agreements;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\ChangeStatus;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\StateMachine;
use App\Files\AttachmentService;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementChange;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\CustomerDecision;
use App\Models\Agreements\DiscountDecision;
use App\Models\Attachment;
use App\Models\FinanceSettings;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\StateTransition;
use App\Models\User;
use App\Money\Money;
use App\Orders\OrderAccess;

/**
 * Read model of the order's «Agreement and documents» tab (AgrOrder and its variants). Amounts appear only for a
 * viewer with P-37 (or P-29, who prices the quote) over the order; for anyone else every `*_minor` value, the discount
 * and the presentations are removed from the payload itself (`amounts_visible: false`) — hidden UI is never the
 * control. `can` flags mirror the server checks; the Actions re-check everything.
 */
final class AgreementPresenter
{
    /**
     * The payload a write answers with: only for a viewer of the order (P-16). A designer reaching a document through
     * an assigned task without P-16 gets the ids alone, never the whole agreement.
     *
     * @return array<string, mixed>|null
     */
    public static function forResponse(Order $order, User $viewer): ?array
    {
        return OrderAccess::canView($viewer, $order) ? self::forOrder($order, $viewer) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forOrder(Order $order, User $viewer): array
    {
        $amounts = self::amountsVisible($viewer, $order);
        $agreement = OrderAgreement::current($order);
        $documents = AgreementDocument::query()->where('order_id', $order->id)->orderByDesc('id')->get();
        $change = OrderAgreement::pendingChange($order);
        $lastChange = $change ?? AgreementChange::query()->where('order_id', $order->id)->latest('id')->first();
        $settings = FinanceSettings::current();

        $p = fn (Permission $permission) => Access::can($viewer, $permission, $order);
        $openQuote = $documents->contains(fn (AgreementDocument $d) => $d->kind === DocumentKind::Quote && $d->status->isOpen());

        return [
            'amounts_visible' => $amounts,
            'vat' => ['rate_bp' => $settings->vat_rate_bp, 'inclusive' => $settings->prices_include_vat],
            'contracts_enabled' => $settings->contracts_enabled,
            'agreement' => $agreement === null ? null : self::agreement($agreement, $order, $amounts),
            'documents' => array_values($documents->map(fn (AgreementDocument $d) => self::document($d, $order, $viewer, $amounts))->all()),
            'change' => $lastChange === null ? null : self::change($lastChange, $order, $viewer, $amounts),
            'history' => self::history($order, array_values(array_map(intval(...), $documents->pluck('id')->all()))),
            'can' => [
                'create_quote' => $agreement === null && ! $openQuote && $p(Permission::P29),
                'upload_design' => self::canUploadDesign($viewer, $order),
                'prepare_contract' => $settings->contracts_enabled && $p(Permission::P32),
                'create_change' => $agreement !== null && $change === null && $p(Permission::P36),
            ],
        ];
    }

    public static function amountsVisible(User $viewer, Order $order): bool
    {
        return OrderAccess::canSeeFinancials($viewer, $order) || Access::can($viewer, Permission::P29, $order);
    }

    /**
     * Payload of a discount request for the decision window (AgrDiscountDecision): its own base, discount and total
     * only, shown to the P-31 decider without granting P-37 (matrix P-31).
     *
     * @return array<string, mixed>
     */
    public static function decision(DiscountDecision $decision, User $viewer): array
    {
        $order = $decision->order;
        $subject = $decision->subject_type === DiscountDecision::SUBJECT_DOCUMENT
            ? AgreementDocument::query()->find($decision->subject_id)
            : AgreementChange::query()->find($decision->subject_id);
        $current = $subject !== null && hash_equals($decision->content_hash, ContentHash::of(DiscountCheck::priceKey($subject->content)));
        $totals = $subject?->content['totals'] ?? [];
        $limit = DiscountLimits::of($viewer, 'approval_bp', $order);

        return [
            'id' => $decision->id,
            'status' => $decision->status->value,
            'lock_version' => $decision->lock_version,
            'order' => ['id' => $order->id, 'number' => $order->number, 'customer' => $order->customer->name],
            'subject' => ['type' => $decision->subject_type, 'id' => $decision->subject_id, 'number' => $subject?->number],
            'requester' => $decision->requester->name,
            'requested_at' => $decision->created_at->toIso8601String(),
            'reason' => $decision->reason,
            'limit_bp' => $decision->limit_bp,
            'share_bp' => DiscountLimits::share(Money::of($decision->discount_minor), Money::of($decision->base_minor)),
            'base_minor' => $decision->base_minor,
            'discount_minor' => $decision->discount_minor,
            'over_minor' => max(0, $decision->discount_minor - Money::of($decision->base_minor)->percent($decision->limit_bp)->minor),
            'total_minor' => $current ? ($totals['total_minor'] ?? null) : null,
            'vat_minor' => $current ? ($totals['vat_minor'] ?? null) : null,
            'content_current' => $current,
            'decider' => $decision->decider?->name,
            'decided_at' => $decision->decided_at?->toIso8601String(),
            'decision_reason' => $decision->decision_reason,
            'can' => [
                'decide' => $decision->status->value === 'requested' && $decision->requested_by !== $viewer->id
                    && Access::can($viewer, Permission::P31, $order) && $current
                    && ! DiscountLimits::exceeds(Money::of($decision->discount_minor), Money::of($decision->base_minor), $limit),
                'cancel' => $decision->status->value === 'requested' && $decision->requested_by === $viewer->id,
            ],
            'own_request' => $decision->requested_by === $viewer->id,
            'above_my_limit' => DiscountLimits::exceeds(Money::of($decision->discount_minor), Money::of($decision->base_minor), $limit),
        ];
    }

    /**
     * Removes every amount from a content payload.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function withoutAmounts(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && (str_ends_with($key, '_minor') || in_array($key, ['discount', 'presentations', 'totals'], true))) {
                continue;
            }
            $out[$key] = is_array($item) ? self::withoutAmounts($item) : $item;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function agreement(Agreement $agreement, Order $order, bool $amounts): array
    {
        $decision = $agreement->customerDecision;
        $source = $agreement->source_type === 'document'
            ? AgreementDocument::query()->find($agreement->source_id)?->number
            : AgreementChange::query()->find($agreement->source_id)?->number;
        $payload = [
            'id' => $agreement->id,
            'version' => $agreement->version,
            'approved_at' => $agreement->approved_at->toIso8601String(),
            'source' => ['type' => $agreement->source_type, 'number' => $source],
            'decision' => self::decisionInfo($decision),
            'content' => $amounts ? $agreement->content : self::withoutAmounts($agreement->content),
            'versions' => array_values(OrderAgreement::history($order)->map(fn (Agreement $a) => [
                'version' => $a->version, 'status' => $a->status->value, 'approved_at' => $a->approved_at->toIso8601String(),
            ] + ($amounts ? ['total_minor' => $a->total_minor] : []))->all()),
        ];

        return $amounts ? $payload + [
            'total_minor' => $agreement->total_minor, 'net_minor' => $agreement->net_minor, 'vat_minor' => $agreement->vat_minor,
            'discount_minor' => $agreement->discount_minor, 'products_minor' => $agreement->products_minor,
            'difference_minor' => $agreement->difference_minor, 'settlement_needed_minor' => $agreement->settlement_needed_minor,
        ] : $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(AgreementDocument $document, Order $order, User $viewer, bool $amounts): array
    {
        $document->setRelation('order', $order);
        $p = fn (Permission $permission) => Access::can($viewer, $permission, $order);
        $status = $document->status;
        $decision = CustomerDecision::query()->where('subject_type', 'document')->where('subject_id', $document->id)->latest('id')->first();
        $discount = $document->kind === DocumentKind::Quote && $status === DocumentStatus::Draft
            ? DiscountCheck::state($viewer, DiscountDecision::SUBJECT_DOCUMENT, $document->id, $document->content, $order)
            : null;
        $prepare = Access::can($viewer, $document->kind->preparePermission(), $document);

        return [
            'id' => $document->id,
            'kind' => $document->kind->value,
            'number' => $document->number,
            'status' => $status->value,
            'replaces' => $document->replaces?->number,
            'valid_until' => $document->valid_until?->toDateString(),
            'sent_at' => $document->sent_at?->toIso8601String(),
            'sent_by' => $document->sender?->name,
            'decided_at' => $document->decided_at?->toIso8601String(),
            'cancelled_reason' => $document->cancelled_reason,
            'content' => $amounts ? $document->content : self::withoutAmounts($document->content),
            'content_hash' => $document->content_hash,
            'lock_version' => $document->lock_version,
            'files' => self::files($document->attachments()->get()->all(), $viewer),
            'decision' => $decision === null ? null : self::decisionInfo($decision, $viewer),
            'can' => [
                'edit' => $status === DocumentStatus::Draft && $prepare,
                'send' => $status === DocumentStatus::Draft && $p(Permission::P30),
                'revise' => $document->kind !== DocumentKind::Design && $prepare
                    && (in_array($status, [DocumentStatus::AwaitingCustomer, DocumentStatus::ChangesRequested, DocumentStatus::Expired], true)
                        || ($status === DocumentStatus::Approved && $document->kind === DocumentKind::Contract)),
                'replace_design' => $document->kind === DocumentKind::Design && $prepare
                    && in_array($status, [DocumentStatus::AwaitingCustomer, DocumentStatus::ChangesRequested, DocumentStatus::Approved], true),
                'extend' => $status === DocumentStatus::Expired && $p(Permission::P30),
                'cancel' => in_array($status, [DocumentStatus::Draft, DocumentStatus::AwaitingCustomer, DocumentStatus::ChangesRequested], true) && $p(Permission::P30),
                'record_approval' => $status === DocumentStatus::AwaitingCustomer && $p(Permission::P34),
                'request_discount' => ($discount['state'] ?? null) === DiscountCheck::NEEDS_DECISION && $p(Permission::P29),
            ],
        ] + ($amounts ? ['discount' => self::discount($discount)] : []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function change(AgreementChange $change, Order $order, User $viewer, bool $amounts): array
    {
        $p = fn (Permission $permission) => Access::can($viewer, $permission, $order);
        $editable = in_array($change->status, [ChangeStatus::Draft, ChangeStatus::NeedsRevision], true);
        $discount = $editable ? DiscountCheck::state($viewer, DiscountDecision::SUBJECT_CHANGE, $change->id, $change->content, $order) : null;
        $base = $change->baseAgreement;
        $actions = OrderAction::query()->where('order_id', $order->id)->whereIn('key', $change->affected ?? [])->get()
            ->map(fn (OrderAction $a) => ['key' => $a->key, 'name' => $a->name->get(), 'held' => $a->held_by_change_id === $change->id])->all();
        $payload = [
            'id' => $change->id,
            'number' => $change->number,
            'status' => $change->status->value,
            'description' => $change->description,
            'base_version' => $base->version,
            'content' => $amounts ? $change->content : self::withoutAmounts($change->content),
            'affected' => array_values($actions),
            'delay_days' => $change->delay_days,
            'revision_reason' => $change->revision_reason,
            'cancelled_reason' => $change->cancelled_reason,
            'presented_at' => $change->presented_at?->toIso8601String(),
            'presentations' => count($change->presentations ?? []),
            'content_hash' => $change->content_hash,
            'lock_version' => $change->lock_version,
            'can' => [
                'edit' => $editable && $p(Permission::P36),
                'present' => $editable && $p(Permission::P36),
                'request_revision' => $change->status === ChangeStatus::AwaitingApproval && $p(Permission::P36),
                'apply' => $change->status === ChangeStatus::AwaitingApproval && $p(Permission::P36) && $p(Permission::P34),
                'withdraw' => $change->status->isOpen() && $p(Permission::P36),
                'request_discount' => ($discount['state'] ?? null) === DiscountCheck::NEEDS_DECISION && $p(Permission::P36),
            ],
        ];

        return $amounts ? $payload + [
            'discount' => self::discount($discount), 'difference_minor' => $change->difference_minor, 'base_total_minor' => $base->total_minor,
        ] : $payload;
    }

    /**
     * @param  array{state: string, limit_bp: int|null, share_bp: int, over_minor: int, decision_id: int|null}|null  $discount
     * @return array<string, mixed>|null
     */
    private static function discount(?array $discount): ?array
    {
        return $discount === null ? null : $discount + [
            'decision' => $discount['decision_id'] === null ? null : self::decisionRef(DiscountDecision::query()->find($discount['decision_id'])),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decisionRef(?DiscountDecision $decision): ?array
    {
        return $decision === null ? null : [
            'id' => $decision->id, 'status' => $decision->status->value, 'requester' => $decision->requester->name,
            'decider' => $decision->decider?->name, 'decided_at' => $decision->decided_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decisionInfo(CustomerDecision $decision, ?User $viewer = null): array
    {
        return [
            'decision' => $decision->decision,
            'method' => $decision->method,
            'channel' => $decision->channel,
            'decided_at' => $decision->decided_at->toIso8601String(),
            'recorded_by' => $decision->recorder?->name,
            'customer_mobile' => $decision->customer_mobile,
            'note' => $decision->note,
            'evidence' => $viewer === null ? [] : self::files($decision->attachments()->get()->all(), $viewer),
        ];
    }

    /**
     * @param  array<int, Attachment>  $attachments
     * @return list<array{uuid: string, name: string, url: string|null}>
     */
    private static function files(array $attachments, User $viewer): array
    {
        $files = app(AttachmentService::class);

        return array_values(array_map(fn (Attachment $a) => [
            'uuid' => $a->uuid,
            'name' => $a->original_name,
            'url' => $files->canDownload($viewer, $a) ? $files->downloadUrl($a, $viewer) : null,
        ], $attachments));
    }

    /**
     * Agreement activity, newest first: M-08/M-09/M-26 transitions of this order's records.
     *
     * @param  list<int>  $documentIds
     * @return list<array<string, mixed>>
     */
    private static function history(Order $order, array $documentIds): array
    {
        $changeIds = AgreementChange::query()->where('order_id', $order->id)->pluck('id')->all();
        $decisionIds = DiscountDecision::query()->where('order_id', $order->id)->pluck('id')->all();
        $morph = fn (string $class): string => match ($class) {
            AgreementDocument::class => (new AgreementDocument)->getMorphClass(),
            AgreementChange::class => (new AgreementChange)->getMorphClass(),
            default => (new DiscountDecision)->getMorphClass(),
        };

        return array_values(StateTransition::query()
            ->where(function ($q) use ($documentIds, $changeIds, $decisionIds, $morph) {
                $q->where(fn ($w) => $w->where('subject_type', $morph(AgreementDocument::class))->whereIn('subject_id', $documentIds))
                    ->orWhere(fn ($w) => $w->where('subject_type', $morph(AgreementChange::class))->whereIn('subject_id', $changeIds))
                    ->orWhere(fn ($w) => $w->where('subject_type', $morph(DiscountDecision::class))->whereIn('subject_id', $decisionIds));
            })
            ->whereIn('machine', [StateMachine::M08->value, StateMachine::M09->value, StateMachine::M26->value])
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(40)->get()
            ->map(fn (StateTransition $t) => [
                'id' => $t->id,
                'machine' => $t->machine->value,
                'transition' => $t->transition,
                'to' => $t->to_state,
                'actor' => $t->actor_user_id === null ? null : User::query()->whereKey($t->actor_user_id)->value('name'),
                'source' => $t->source,
                'reason' => $t->reason,
                'at' => $t->occurred_at->toIso8601String(),
            ])->all());
    }

    private static function canUploadDesign(User $viewer, Order $order): bool
    {
        $probe = new AgreementDocument(['order_id' => $order->id, 'kind' => DocumentKind::Design, 'status' => DocumentStatus::Draft]);
        $probe->setRelation('order', $order);

        return Access::can($viewer, Permission::P33, $probe);
    }
}
