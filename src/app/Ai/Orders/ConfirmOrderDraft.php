<?php

namespace App\Ai\Orders;

use App\Actions\Orders\CreateOrders;
use App\Ai\AiSuggestions;
use App\Ai\AiSuggestionStatus;
use App\Models\Ai\AiSuggestion;
use App\Models\Inbox\Conversation;
use App\Models\User;
use App\Support\LockingTransaction;
use Illuminate\Validation\ValidationException;

/**
 * «أنشئ الطلب» on a reviewed conversation draft (AI-5). The staff member's edited lines — never the model's output —
 * go through the existing CreateOrders Action (P-15 over the chosen branch, customer visibility, `ready_goods`,
 * frozen catalog versions and prices by OrderLineBuilder, gapless numbers, idempotency), with transition source
 * `ai_suggestion`. In the same transaction the draft is marked accepted (as proposed) or edited (with what was
 * actually created). A draft that is no longer open creates nothing.
 */
final class ConfirmOrderDraft
{
    public const SOURCE = 'ai_suggestion';

    public function __construct(
        private readonly CreateOrders $create,
        private readonly AiSuggestions $suggestions,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated by ConfirmOrderDraftRequest
     * @return list<int> the created order ids
     */
    public function handle(User $actor, Conversation $conversation, AiSuggestion $draft, array $data): array
    {
        $customerId = $conversation->customer_id;
        if ($customerId === null) {
            throw ValidationException::withMessages(['customer_id' => __('inbox.order_draft.no_customer')]);
        }

        $custom = [];
        $ready = [];
        foreach ((array) $data['lines'] as $line) {
            $row = self::orderLine((array) $line);
            if ($row['type'] === 'ready_variant') {
                $ready[] = $row;
            } else {
                $custom[] = $row;
            }
        }

        $summary = trim((string) ($data['need_summary'] ?? ''));
        if ($summary === '') {
            $summary = mb_substr(implode('، ', array_filter(array_map(fn ($l) => (string) ($l['description'] ?? ''), $custom))), 0, 2000);
        }

        $orderData = [
            'customer_id' => $customerId,
            'branch_id' => (int) $data['branch_id'],
        ];
        if ($custom !== []) {
            $orderData['custom'] = [
                'need_summary' => $summary !== '' ? $summary : __('inbox.order_draft.default_summary'),
                'needs_measurement' => (bool) ($data['needs_measurement'] ?? false),
                'lines' => $custom,
            ];
        }
        if ($ready !== []) {
            $orderData['ready'] = ['lines' => $ready];
        }

        return LockingTransaction::run(function () use ($actor, $conversation, $draft, $data, $orderData) {
            /** @var AiSuggestion|null $locked */
            $locked = AiSuggestion::query()->lockForUpdate()->find($draft->id);
            if ($locked === null || $locked->status !== AiSuggestionStatus::Proposed || $locked->requested_by !== $actor->id
                || $locked->subject_id !== $conversation->id || ($locked->expires_at !== null && $locked->expires_at->isPast())) {
                throw ValidationException::withMessages(['draft' => __('inbox.order_draft.closed')]);
            }

            ['result' => $result] = $this->create->handle($actor, (string) $data['idempotency_key'], $orderData, self::SOURCE);
            $orderIds = array_map('intval', $result['order_ids']);

            $final = ['lines' => self::fingerprint((array) $data['lines'])];
            $proposed = ['lines' => self::fingerprint(self::proposedForm((array) ($locked->payload['lines'] ?? [])))];
            $this->suggestions->accept($locked, $actor, $final == $proposed ? null : [
                'need_summary' => $orderData['custom']['need_summary'] ?? null,
                'lines' => $data['lines'],
                'branch_id' => (int) $data['branch_id'],
                'order_ids' => $orderIds,
            ]);

            return $orderIds;
        });
    }

    /**
     * A draft line as the review form starts it (the screen's initial state).
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public static function formLine(array $line): array
    {
        $type = $line['type'] ?? null;

        return [
            'key' => (string) ($line['key'] ?? ''),
            'type' => $type,
            'product_id' => $type === 'custom_product' ? $line['item_id'] : null,
            'variant_id' => $type === 'ready_variant' ? $line['item_id'] : null,
            'description' => (string) ($line['description'] ?? ''),
            'quantity' => $line['quantity'] ?? ($type === null ? null : '1'),
            'unit' => $line['unit'] ?? null,
            'width_cm' => OrderDraftLines::mmToCm($line['width_mm'] ?? null),
            'height_cm' => OrderDraftLines::mmToCm($line['height_mm'] ?? null),
            'depth_cm' => OrderDraftLines::mmToCm($line['depth_mm'] ?? null),
            'color' => $line['color'] ?? null,
        ];
    }

    /**
     * @param  list<mixed>  $lines
     * @return list<array<string, mixed>>
     */
    private static function proposedForm(array $lines): array
    {
        return array_map(fn ($l) => self::formLine((array) $l), $lines);
    }

    /**
     * What decides «accepted as is» vs «edited»: item, quantity, unit and sizes of each line.
     *
     * @param  list<mixed>  $lines
     * @return list<array<string, mixed>>
     */
    private static function fingerprint(array $lines): array
    {
        return array_map(fn ($l) => [
            'type' => $l['type'] ?? null,
            'item' => (int) ($l['product_id'] ?? $l['variant_id'] ?? 0),
            'quantity' => OrderDraftLines::quantity($l['quantity'] ?? null),
            'width' => OrderDraftLines::cmToMm($l['width_cm'] ?? null),
            'height' => OrderDraftLines::cmToMm($l['height_cm'] ?? null),
            'depth' => OrderDraftLines::cmToMm($l['depth_cm'] ?? null),
        ], array_map(fn ($l) => (array) $l, $lines));
    }

    /**
     * One reviewed line as CreateOrders expects it. Sizes and colour have no column on an order line, so they are
     * written into its description, where the order screen and the measurement step read them.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private static function orderLine(array $line): array
    {
        $type = (string) $line['type'];
        $description = trim((string) ($line['description'] ?? ''));
        $sizes = array_filter([
            'width' => OrderDraftLines::cmToMm($line['width_cm'] ?? null),
            'height' => OrderDraftLines::cmToMm($line['height_cm'] ?? null),
            'depth' => OrderDraftLines::cmToMm($line['depth_cm'] ?? null),
        ]);
        if ($sizes !== [] && $type !== 'ready_variant') {
            $parts = [];
            foreach ($sizes as $dimension => $mm) {
                $parts[] = __('inbox.order_draft.size.'.$dimension).' '.OrderDraftLines::mmToCm($mm).' '.__('inbox.order_draft.cm');
            }
            $description = trim($description.' ('.implode('، ', $parts).')');
        }
        $color = trim((string) ($line['color'] ?? ''));
        if ($color !== '' && $type !== 'ready_variant') {
            $description = trim($description.' — '.__('inbox.order_draft.color').': '.$color);
        }

        return array_filter([
            'type' => $type,
            'product_id' => $type === 'custom_product' ? (int) $line['product_id'] : null,
            'variant_id' => $type === 'ready_variant' ? (int) $line['variant_id'] : null,
            'quantity' => isset($line['quantity']) && $line['quantity'] !== '' ? (string) $line['quantity'] : ($type === 'ready_variant' ? null : '1'),
            'unit' => $type === 'ready_variant' ? null : self::unitLabel($line['unit'] ?? null),
            'description' => $description !== '' ? mb_substr($description, 0, 2000) : null,
            'options' => isset($line['options']) && is_array($line['options']) && $line['options'] !== [] ? $line['options'] : null,
        ], fn ($v) => $v !== null);
    }

    private static function unitLabel(mixed $unit): ?string
    {
        return match ($unit) {
            'meter' => 'm',
            'square_meter' => 'm2',
            'piece' => null,
            default => is_string($unit) && trim($unit) !== '' ? mb_substr(trim($unit), 0, 16) : null,
        };
    }
}
