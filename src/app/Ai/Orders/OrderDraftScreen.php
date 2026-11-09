<?php

namespace App\Ai\Orders;

use App\Access\Access;
use App\Access\Permission;
use App\Catalog\CatalogPresenter;
use App\Enums\OrderKind;
use App\Models\Ai\AiSuggestion;
use App\Models\Branch;
use App\Models\Catalog\CatalogProduct;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Inbox\Conversation;
use App\Models\Inbox\ConversationMessage;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderAccess;
use App\Organization\OrgPresenter;

/**
 * The «مسودة طلب من المحادثة» review screen (AI-5): each proposed line next to the message it came from, its warnings
 * and confidence, the branches the staff member may open an order in, and the server's prices. Amounts appear only
 * with P-37 over an order of that kind in the chosen branch (like the create screen's catalog picker).
 */
final class OrderDraftScreen
{
    /**
     * @return array<string, mixed>
     */
    public static function props(User $actor, Conversation $conversation, AiSuggestion $draft): array
    {
        $payload = (array) $draft->payload;
        $lines = array_values(array_map(fn ($l) => (array) $l, (array) ($payload['lines'] ?? [])));
        $sources = ConversationMessage::query()->where('conversation_id', $conversation->id)
            ->whereIn('id', array_filter(array_map(fn ($l) => $l['source_message_id'] ?? null, $lines)))
            ->get()->keyBy('id');

        $branches = fn (OrderKind $kind) => array_map(
            fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)],
            OrderAccess::creatableBranches($actor, $kind),
        );

        $customer = $conversation->customer;

        return [
            'conversation' => [
                'id' => $conversation->id,
                'title' => $customer->name ?? $conversation->contact_name ?? $conversation->wa_id,
                'customer' => $customer === null ? null : ['id' => $customer->id, 'name' => $customer->name, 'branch_id' => $customer->branch_id],
            ],
            'draft' => [
                'id' => $draft->id,
                'need_summary' => (string) ($payload['need_summary'] ?? ''),
                'needs_measurement' => (bool) ($payload['needs_measurement'] ?? false),
                'created_at' => $draft->created_at?->toIso8601String(),
                'lines' => array_map(function (array $line) use ($sources) {
                    $source = $sources->get($line['source_message_id'] ?? 0);

                    return ConfirmOrderDraft::formLine($line) + [
                        'name' => self::itemName($line['type'] ?? null, (int) ($line['item_id'] ?? 0)),
                        'option_groups' => ($line['type'] ?? null) === 'custom_product' ? (CatalogProduct::query()->find((int) $line['item_id'])->option_groups ?? []) : [],
                        'warnings' => array_values((array) ($line['warnings'] ?? [])),
                        'confidence' => (string) ($line['confidence'] ?? 'low'),
                        'source' => $source === null ? null : ['id' => $source->id, 'text' => mb_substr((string) $source->body, 0, 300)],
                    ];
                }, $lines),
            ],
            'branches' => ['custom' => $branches(OrderKind::Custom), 'ready' => $branches(OrderKind::Ready)],
        ];
    }

    /**
     * Server prices for the lines as the staff member has them now, without amounts they may not see.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    public static function pricing(User $actor, int $branchId, array $lines): array
    {
        $priced = OrderDraftPricing::price($lines);
        $money = [
            'custom' => Access::can($actor, Permission::P37, new Order(['kind' => OrderKind::Custom, 'branch_id' => $branchId])),
            'ready' => Access::can($actor, Permission::P37, new Order(['kind' => OrderKind::Ready, 'branch_id' => $branchId])),
        ];

        $out = [];
        foreach ($lines as $i => $line) {
            $key = (string) ($line['key'] ?? $i);
            $row = $priced['lines'][$key];
            $visible = ($line['type'] ?? null) === 'ready_variant' ? $money['ready'] : $money['custom'];
            $out[$key] = $visible ? $row : ['unit_price_minor' => null, 'line_total_minor' => null, 'reference_minor' => null, 'note' => $row['note']];
        }

        return [
            'money' => $money,
            'lines' => $out,
            'ready_total_minor' => $money['ready'] ? $priced['ready_total_minor'] : null,
            'reference_total_minor' => $money['custom'] ? $priced['reference_total_minor'] : null,
            'reference_partial' => $priced['reference_partial'],
        ];
    }

    private static function itemName(?string $type, int $id): ?string
    {
        if ($type === 'custom_product') {
            $product = CatalogProduct::query()->find($id);

            return $product === null ? null : CatalogPresenter::text($product->name)['label'];
        }
        if ($type === 'ready_variant') {
            $variant = CatalogReadyVariant::query()->with('product')->find($id);

            return $variant === null ? null : CatalogPresenter::text($variant->product->name)['label'].' — '.CatalogPresenter::text($variant->name)['label'];
        }

        return null;
    }
}
