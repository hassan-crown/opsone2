<?php

namespace App\Ai\Orders;

use App\Ai\Inbox\ReplyContext;
use App\Catalog\CatalogPresenter;
use App\Enums\OrderKind;
use App\Models\Catalog\CatalogProduct;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\User;
use App\Orders\OrderAccess;
use Illuminate\Database\Eloquent\Collection;

/**
 * The bounded catalog list the model may choose from (AI-5, plan §2 axis 3: «يختار النموذج من قائمة مرشحين محدودة لا
 * من فراغ»). Only selectable items of the kinds the staff member may open an order of are offered, ranked by the
 * words they share with the conversation. Each candidate is named by a letter label (`catalog.a.name`), never by its
 * database id, and carries no price: the model never sees, and never returns, money. The server keeps the label →
 * item map and resolves the model's choice; an unknown label resolves to nothing (the line needs a manual choice).
 */
final class OrderDraftCatalog
{
    public const LIMIT = 40;

    /**
     * @param  array<string, array{type: string, id: int}>  $map  label → catalog item
     * @param  array<string, string>  $facts
     */
    private function __construct(public readonly array $map, public readonly array $facts) {}

    /**
     * @param  list<string>  $texts  the conversation, to rank candidates by shared words
     */
    public static function for(User $user, array $texts): self
    {
        $words = self::words(implode(' ', $texts));
        $candidates = [];

        if (OrderAccess::creatableBranches($user, OrderKind::Custom) !== []) {
            /** @var Collection<int, CatalogProduct> $products */
            $products = CatalogProduct::query()->orderBy('id')->get();
            foreach ($products as $product) {
                if (! $product->isSelectable()) {
                    continue;
                }
                $name = CatalogPresenter::text($product->name)['label'];
                $candidates[] = [
                    'type' => 'custom_product', 'id' => $product->id, 'name' => $name,
                    'unit' => $product->pricing_basis->value,
                    'category' => (string) $product->category,
                    'options' => self::options($product),
                    'score' => self::score($words, $name.' '.$product->category.' '.$product->name->get('en')),
                ];
            }
        }

        if (OrderAccess::creatableBranches($user, OrderKind::Ready) !== []) {
            /** @var Collection<int, CatalogReadyVariant> $variants */
            $variants = CatalogReadyVariant::query()->with('product')->orderBy('id')->get();
            foreach ($variants as $variant) {
                if (! $variant->isSelectable()) {
                    continue;
                }
                $name = CatalogPresenter::text($variant->product->name)['label'].' — '.CatalogPresenter::text($variant->name)['label'];
                $candidates[] = [
                    'type' => 'ready_variant', 'id' => $variant->id, 'name' => $name,
                    'unit' => 'piece', 'category' => '', 'options' => '',
                    'score' => self::score($words, $name.' '.$variant->product->name->get('en').' '.$variant->name->get('en')),
                ];
            }
        }

        // Most shared words first; ties keep catalog order (stable sort).
        usort($candidates, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $candidates = array_slice($candidates, 0, self::LIMIT);

        $map = [];
        $facts = [];
        foreach ($candidates as $i => $c) {
            $label = ReplyContext::label($i);
            $map[$label] = ['type' => $c['type'], 'id' => $c['id']];
            $prefix = 'catalog.'.$label;
            $facts[$prefix.'.name'] = $c['name'];
            $facts[$prefix.'.kind'] = $c['type'] === 'ready_variant' ? 'ready' : 'made_to_order';
            $facts[$prefix.'.priced_by'] = $c['unit'];
            if ($c['category'] !== '') {
                $facts[$prefix.'.category'] = $c['category'];
            }
            if ($c['options'] !== '') {
                $facts[$prefix.'.options'] = $c['options'];
            }
        }

        return new self($map, $facts);
    }

    /**
     * @return array{type: string, id: int}|null
     */
    public function resolve(?string $label): ?array
    {
        return $label === null ? null : ($this->map[strtolower(trim($label))] ?? null);
    }

    private static function options(CatalogProduct $product): string
    {
        $parts = [];
        foreach ((array) ($product->option_groups ?? []) as $group) {
            $label = (string) ($group['label']['ar'] ?? $group['key'] ?? '');
            $values = array_filter(array_map(fn ($v) => (string) ($v['ar'] ?? ''), (array) ($group['values'] ?? [])));
            $parts[] = $label.($values !== [] ? ': '.implode('، ', $values) : '');
        }

        return mb_substr(implode('؛ ', $parts), 0, 300);
    }

    /**
     * @return array<string, true>
     */
    private static function words(string $text): array
    {
        $text = mb_strtolower($text);
        preg_match_all('/[\p{L}]{3,}/u', $text, $m);
        $words = [];
        foreach ($m[0] as $word) {
            // «المطبخ» and «مطبخ» are the same word for ranking.
            $words[preg_replace('/^(ال|بال|وال|لل)/u', '', $word) ?? $word] = true;
        }

        return $words;
    }

    /**
     * @param  array<string, true>  $words
     */
    private static function score(array $words, string $haystack): int
    {
        return count(array_intersect_key(self::words($haystack), $words));
    }
}
