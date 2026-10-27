<?php

/**
 * Frozen lines (COM-11), optimistic locking on every write, and the M-04/M-05 guards: undefined or not-allowed
 * transitions and edits in a state that forbids them are refused without effect.
 */

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Actions\Catalog\SaveVariant;
use App\Enums\CustomOrderStatus;
use App\Enums\ReadyOrderStatus;
use App\Models\Catalog\CatalogVersion;
use App\Models\Order;
use App\Models\OrderLine;
use App\Orders\OrderTransitions;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/OrderTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = ordersFixture();
});

describe('snapshot immutability', function () {
    it('keeps the line on the version and price it was added with after the catalog changes', function () {
        $this->actingAs($this->f->sales)->postJson('/orders', readyPayload($this->f->inA, $this->f->variant, 2))->assertCreated();
        $line = lastOrder()->lines()->firstOrFail();
        $versionAtAdd = $line->catalog_version_id;

        app(SaveVariant::class)->handle($this->f->org->owner, $this->f->variant->product, $this->f->variant, [
            'code' => 'HND-60-BLK', 'name' => ['ar' => 'أسود لامع', 'en' => 'Gloss black'], 'price_minor' => 9900, 'is_default' => false,
        ]);

        $line->refresh();
        expect($this->f->variant->refresh()->current_version)->toBe(2)
            ->and($line->catalog_version_id)->toBe($versionAtAdd)
            ->and($line->unit_price_minor)->toBe(4500)
            ->and($line->snapshot['item']['price_minor'])->toBe(4500)
            ->and($line->snapshot['item']['name']['ar'])->toBe('أسود مطفي')
            ->and(CatalogVersion::query()->find($versionAtAdd)->payload['price_minor'])->toBe(4500)
            ->and(lastOrder()->total_minor)->toBe(9000);
    });

    it('freezes a custom product version and the chosen options', function () {
        $this->actingAs($this->f->sales)->postJson('/orders', customPayload($this->f->inA, ['custom' => [
            'need_summary' => 'x',
            'lines' => [['type' => 'custom_product', 'product_id' => $this->f->product->id, 'quantity' => '3.5', 'unit' => 'm', 'options' => [['key' => 'color', 'value' => 'أبيض']]]],
        ]]))->assertCreated();

        $line = lastOrder()->lines()->firstOrFail();
        expect($line->snapshot['options'][0]['value'])->toBe('أبيض')
            ->and($line->snapshot['item']['reference_price_minor'])->toBe(125050)
            ->and($line->unit_price_minor)->toBeNull()
            ->and($line->quantity)->toBe('3.500');

        $this->actingAs($this->f->sales)->postJson('/orders', customPayload($this->f->inA, ['custom' => [
            'need_summary' => 'x', 'lines' => [['type' => 'custom_product', 'product_id' => $this->f->product->id, 'options' => [['key' => 'nope', 'value' => 'x']]]],
        ]]))->assertUnprocessable();
    });

    it('never updates or deletes a line; removal keeps it in history', function () {
        $this->actingAs($this->f->sales)->postJson('/orders', readyPayload($this->f->inA, $this->f->variant, 1))->assertCreated();
        $line = lastOrder()->lines()->firstOrFail();

        expect(fn () => $line->update(['quantity' => 5]))->toThrow(LogicException::class)
            ->and(fn () => $line->refresh()->update(['snapshot' => []]))->toThrow(LogicException::class)
            ->and(fn () => $line->refresh()->delete())->toThrow(LogicException::class);

        $order = lastOrder();
        $this->actingAs($this->f->sales)->deleteJson("/orders/{$order->id}/lines/{$line->id}", ['lock_version' => $order->lock_version])->assertOk();
        expect(OrderLine::query()->find($line->id)->removed_at)->not->toBeNull()
            ->and(lastOrder()->total_minor)->toBe(0);
    });
});

describe('optimistic locking', function () {
    it('applies a write at the current version and bumps it', function () {
        $this->actingAs($this->f->sales)->postJson('/orders', customPayload($this->f->inA))->assertCreated();
        $order = lastOrder();

        $this->actingAs($this->f->sales)->putJson("/orders/{$order->id}", ['lock_version' => 1, 'need_summary' => 'احتياج محدث'])
            ->assertOk()->assertJsonPath('order.lock_version', 2);
        expect($order->refresh()->need_summary)->toBe('احتياج محدث');
    });

    it('rejects a stale version with 409 and changes nothing', function () {
        $this->actingAs($this->f->sales)->postJson('/orders', customPayload($this->f->inA))->assertCreated();
        $order = lastOrder();
        $this->actingAs($this->f->sales)->putJson("/orders/{$order->id}", ['lock_version' => 1, 'need_summary' => 'أول'])->assertOk();

        $this->actingAs($this->f->sales)->putJson("/orders/{$order->id}", ['lock_version' => 1, 'need_summary' => 'قديم'])
            ->assertStatus(409)->assertJsonPath('code', 'stale_version')->assertJsonPath('lock_version', 2);
        $this->actingAs($this->f->sales)->postJson("/orders/{$order->id}/lines", ['lock_version' => 1, 'type' => 'custom_need', 'description' => 'x'])
            ->assertStatus(409);

        $order->refresh();
        expect($order->need_summary)->toBe('أول')
            ->and($order->lock_version)->toBe(2)
            ->and($order->lines()->count())->toBe(1);
    });
});

describe('M-04 / M-05 guards', function () {
    it('refuses transitions the machines do not define from the current state', function () {
        $custom = new Order(['kind' => 'custom', 'status' => CustomOrderStatus::InProgress->value]);
        $ready = new Order(['kind' => 'ready', 'status' => ReadyOrderStatus::Draft->value]);

        expect(OrderTransitions::check($custom, 'T01'))->toBe(CustomOrderStatus::Paused)
            ->and(fn () => OrderTransitions::check($custom, 'T02'))->toThrow(InvalidTransition::class) // resume while running
            ->and(fn () => OrderTransitions::check($custom, 'T07'))->toThrow(InvalidTransition::class) // close while running
            ->and(fn () => OrderTransitions::check($custom, 'T99'))->toThrow(InvalidTransition::class)
            ->and(OrderTransitions::check($ready, 'T01'))->toBe(ReadyOrderStatus::Confirmed)
            ->and(fn () => OrderTransitions::check($ready, 'T03'))->toThrow(InvalidTransition::class) // pay without awaiting
            ->and(fn () => OrderTransitions::check($ready, 'T10'))->toThrow(InvalidTransition::class); // close a draft
    });

    it('blocks edits once the order leaves an editable state, without effect', function () {
        $this->actingAs($this->f->sales)->postJson('/orders', readyPayload($this->f->inA, $this->f->variant))->assertCreated();
        $order = lastOrder();
        Order::query()->whereKey($order->id)->update(['status' => ReadyOrderStatus::Confirmed->value]);
        $order->refresh();

        expect(Access::inspect($this->f->sales, 'P-17', $order)->reason?->value)->toBe('record_state')
            ->and(Access::can($this->f->sales, 'P-15', $order))->toBeFalse();

        $this->actingAs($this->f->sales)->postJson("/orders/{$order->id}/lines", [
            'lock_version' => $order->lock_version, 'type' => 'ready_variant', 'variant_id' => $this->f->variant->id, 'quantity' => 1,
        ])->assertForbidden();
        expect($order->lines()->count())->toBe(1);

        $custom = Order::query()->forceCreate(['number' => 'T-1', 'kind' => 'custom', 'status' => 'closed', 'customer_id' => $this->f->inA->id, 'branch_id' => $this->f->org->a->id, 'created_by' => $this->f->sales->id]);
        expect(Access::can($this->f->sales, 'P-17', $custom))->toBeFalse()
            ->and(Access::can($this->f->sales, 'P-19', $custom))->toBeFalse();
    });

    it('refuses a ready line on a custom order and the reverse', function () {
        $this->actingAs($this->f->sales)->postJson('/orders', customPayload($this->f->inA))->assertCreated();
        $order = lastOrder();

        $this->actingAs($this->f->sales)->postJson("/orders/{$order->id}/lines", [
            'lock_version' => 1, 'type' => 'ready_variant', 'variant_id' => $this->f->variant->id, 'quantity' => 1,
        ])->assertUnprocessable();
        expect($order->refresh()->lock_version)->toBe(1);
    });
});
