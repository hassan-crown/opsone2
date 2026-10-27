<?php

namespace App\Orders;

use App\Access\Access;
use App\Access\Permission;
use App\Catalog\CatalogPresenter;
use App\Customers\CustomerAccess;
use App\Enums\OrderKind;
use App\Models\Catalog\CatalogProduct;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Organization\OrgPresenter;
use App\Search\FulltextMatch;

/**
 * Data for the create-order pickers: customers the actor may see and the selectable catalog items with the
 * version a new line would freeze (COM-11). Catalog money is left out unless the actor holds P-37 for the branch.
 */
final class OrderPicker
{
    private const LIMIT = 15;

    /**
     * @return list<array<string, mixed>>
     */
    public static function customers(User $actor, string $q): array
    {
        $query = CustomerAccess::visibleCustomers($actor)->with('branch')->orderByDesc('customers.id')->limit(self::LIMIT);
        if (trim($q) !== '') {
            FulltextMatch::apply($query, ['customers.name', 'customers.mobile', 'customers.email'], $q, [['name', 'mobile', 'email']]);
        }

        return array_values($query->get()->map(self::customerRow(...))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function customerRow(Customer $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'kind' => $c->kind->value,
            'mobile' => $c->mobile,
            'branch_id' => $c->branch_id,
            'branch' => OrgPresenter::name($c->branch),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function catalog(User $actor, OrderKind $kind, int $branchId, string $q): array
    {
        $money = Access::can($actor, Permission::P37, new Order(['kind' => $kind, 'branch_id' => $branchId]));

        if ($kind === OrderKind::Ready) {
            $rows = CatalogReadyVariant::query()->with('product')->orderBy('id')->get()
                ->filter(fn (CatalogReadyVariant $v) => $v->isSelectable() && self::matches($q, [(string) $v->name->get(), (string) $v->product->name->get(), (string) $v->code]))
                ->take(self::LIMIT)
                ->map(fn (CatalogReadyVariant $v) => [
                    'id' => $v->id,
                    'type' => 'ready_variant',
                    'name' => CatalogPresenter::text($v->product->name)['label'].' — '.CatalogPresenter::text($v->name)['label'],
                    'code' => $v->code,
                    'version' => $v->current_version,
                ] + ($money ? ['price_minor' => $v->price_minor] : []));

            return array_values($rows->all());
        }

        return array_values(CatalogProduct::query()->orderBy('id')->get()
            ->filter(fn (CatalogProduct $p) => $p->isSelectable() && self::matches($q, [(string) $p->name->get(), (string) $p->category]))
            ->take(self::LIMIT)
            ->map(fn (CatalogProduct $p) => [
                'id' => $p->id,
                'type' => 'custom_product',
                'name' => CatalogPresenter::text($p->name)['label'],
                'category' => $p->category,
                'version' => $p->current_version,
                'option_groups' => $p->option_groups,
            ] + ($money ? ['reference_price_minor' => $p->reference_price_minor] : []))->all());
    }

    /**
     * @param  list<string>  $haystack
     */
    private static function matches(string $q, array $haystack): bool
    {
        $q = mb_strtolower(trim($q));

        return $q === '' || collect($haystack)->contains(fn (string $h) => str_contains(mb_strtolower($h), $q));
    }
}
