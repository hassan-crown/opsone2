<?php

use App\Actions\Catalog\SetCatalogStatus;
use App\Catalog\CatalogStatus;
use App\Models\Catalog\CatalogProduct;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Customer;
use App\Models\MemberGrant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\Org;

require_once __DIR__.'/../Catalog/CatalogTestHelpers.php';

/**
 * Orders fixture: Org (branches A, B north; C south), sales in A, a customer per branch, a custom product with one
 * option group and a published ready variant priced 45.00 SAR.
 *
 * @return object{org: Org, sales: User, inA: Customer, inB: Customer, inC: Customer, product: CatalogProduct, variant: CatalogReadyVariant}
 */
function ordersFixture(): object
{
    $org = Org::make();
    $product = makeProduct($org->owner, ['option_groups' => [[
        'key' => 'color', 'label' => ['ar' => 'اللون', 'en' => 'Color'], 'type' => 'single', 'required' => false,
        'values' => [['ar' => 'أبيض', 'en' => 'White']],
    ]]]);
    $ready = makeReady($org->owner);
    $variant = makeVariant($org->owner, $ready);
    app(SetCatalogStatus::class)->handle($org->owner, $ready, CatalogStatus::Published);
    app(SetCatalogStatus::class)->handle($org->owner, $variant->refresh(), CatalogStatus::Published);

    return (object) [
        'org' => $org,
        'sales' => $org->member('sales', [$org->a]),
        'inA' => Customer::factory()->create(['branch_id' => $org->a->id]),
        'inB' => Customer::factory()->create(['branch_id' => $org->b->id]),
        'inC' => Customer::factory()->create(['branch_id' => $org->c->id]),
        'product' => $product->refresh(),
        'variant' => $variant->refresh(),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function customPayload(Customer $customer, array $overrides = []): array
{
    return [
        'idempotency_key' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'branch_id' => $customer->branch_id,
        'custom' => ['need_summary' => 'مطبخ كامل مع جزيرة', 'needs_measurement' => true, 'lines' => [
            ['type' => 'custom_need', 'description' => 'جزيرة وسطية'],
        ]],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function readyPayload(Customer $customer, CatalogReadyVariant $variant, int $quantity = 2, array $overrides = []): array
{
    return [
        'idempotency_key' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'branch_id' => $customer->branch_id,
        'ready' => ['lines' => [['type' => 'ready_variant', 'variant_id' => $variant->id, 'quantity' => $quantity]]],
        ...$overrides,
    ];
}

function grantOrders(User $user, string $permission, string $scope, ?array $targets = null): void
{
    MemberGrant::query()->create([
        'membership_id' => Org::membershipOf($user)->id, 'permission' => $permission, 'effect' => 'allow', 'scope' => $scope, 'targets' => $targets,
    ]);
}

function lastOrder(): Order
{
    return Order::query()->latest('id')->firstOrFail();
}
