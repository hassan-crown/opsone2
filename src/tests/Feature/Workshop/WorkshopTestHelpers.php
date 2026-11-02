<?php

use App\Actions\StateMachines\M13PurchaseOrder\T01ConfirmPurchaseOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\Supplier;
use App\Models\User;
use App\Workflow\FlowTemplates;
use App\Workflow\StageEngine;

require_once __DIR__.'/../Procurement/ProcurementTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/**
 * Workshop fixture (ADR-0038): the procurement fixture on a facility provisioned with a retail template, a workshop
 * supplier with a lead time and an email, and one custom order of branch A opened by sales.
 */
function workshopFixture(string $template = FlowTemplates::RETAIL): object
{
    config(['opsone.default_flow_template' => $template]);
    $f = procFixture();
    $f->workshop = procSupplier([
        'name' => ['ar' => 'ورشة الدمام', 'en' => 'Dammam workshop'], 'is_workshop' => true, 'lead_days' => 18, 'email' => 'orders@workshop.test',
    ]);
    $f->customer = Customer::factory()->create(['branch_id' => $f->org->a->id, 'name' => 'عميل سري', 'mobile' => '0551234567']);
    $f->order = openCustomOrder($f->sales, $f->customer);

    return $f;
}

/**
 * Completes the needs, design and agreement stages so the order reaches «sent to the workshop».
 */
function toWorkshopStage(object $f): void
{
    foreach (['measure', 'design', 'agreement'] as $key) {
        expect(app(StageEngine::class)->activeStage($f->order->refresh())?->key)->toBe($key);
        finishActiveStage($f->owner, $f->order);
    }
    expect(app(StageEngine::class)->activeStage($f->order->refresh())?->key)->toBe('workshop');
}

/**
 * A purchase order to the workshop for the order's lines (draft unless confirmed).
 *
 * @param  array<string, mixed>  $extra
 */
function workshopPo(object $f, bool $confirm = true, ?Supplier $supplier = null, array $extra = [], ?Order $order = null, ?User $actor = null): PurchaseOrder
{
    $order ??= $f->order;
    $actor ??= $f->inventory;
    $lines = OrderLine::query()->where('order_id', $order->id)->get()->map(fn (OrderLine $l) => [
        'id' => null, 'item_type' => 'order_line', 'item_id' => $l->id, 'quantity_milli' => 1000, 'unit_cost_minor' => 500000, 'order_id' => $order->id,
    ])->all();
    $po = procDraft($f, $actor, $lines, ['supplier_id' => ($supplier ?? $f->workshop)->id, ...$extra]);

    return $confirm ? app(T01ConfirmPurchaseOrder::class)->handle($actor, $po, $po->lock_version, procKey()) : $po;
}
