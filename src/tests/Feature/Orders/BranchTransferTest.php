<?php

/**
 * Branch transfer (COM-12, P-19, Q-29 option a): impact review, required reason, both branches inside the grant,
 * one atomic apply that is audited — or nothing at all.
 */

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/OrderTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Makes the audit write — the last step of every transfer — fail after the row was already changed.
 */
function failingAudit(string $message): void
{
    Audit::swap(new class($message)
    {
        public function __construct(private string $message) {}

        public function record(mixed ...$args): never
        {
            throw new RuntimeException($this->message);
        }
    });
}

beforeEach(function () {
    $this->f = ordersFixture();
    $this->manager = $this->f->org->member('manager', [$this->f->org->a, $this->f->org->b]);
    $this->actingAs($this->f->sales)->postJson('/orders', customPayload($this->f->inA))->assertCreated();
    $this->order = lastOrder();
});

it('reviews the impact before applying', function () {
    $impact = $this->actingAs($this->manager)
        ->getJson("/orders/{$this->order->id}/branch-transfer?branch_id={$this->f->org->b->id}")->assertOk()->json('impact');

    expect($impact['from_branch']['id'])->toBe($this->f->org->a->id)
        ->and($impact['to_branch']['id'])->toBe($this->f->org->b->id)
        ->and($impact['assignee']['current']['id'])->toBe($this->f->sales->id)
        ->and($impact['assignee']['requires_new'])->toBeTrue() // sales of A cannot see an order of B
        ->and($impact['appointments']['available'])->toBeFalse()
        ->and($impact['reserved_stock']['applicable'])->toBeFalse()
        ->and($impact['open_lines'])->toBe(1);
});

it('moves branch and assignee in one audited step with a reason', function () {
    $salesB = $this->f->org->member('sales', [$this->f->org->b]);

    $this->actingAs($this->manager)->postJson("/orders/{$this->order->id}/branch-transfer", [
        'branch_id' => $this->f->org->b->id, 'reason' => 'العميل انتقل للفرع ب', 'assignee_id' => $salesB->id, 'lock_version' => 1,
    ])->assertOk();

    $order = $this->order->refresh();
    expect($order->branch_id)->toBe($this->f->org->b->id)
        ->and($order->assignee_id)->toBe($salesB->id)
        ->and($order->lock_version)->toBe(2);

    $event = AuditEvent::query()->where('action', AuditAction::OrderBranchTransferred->value)->firstOrFail();
    expect($event->reason)->toBe('العميل انتقل للفرع ب')
        ->and($event->before['branch_id'])->toBe($this->f->org->a->id)
        ->and($event->after['branch_id'])->toBe($this->f->org->b->id);
    // Both branches' auditors see the move.
    expect(DB::table('audit_event_branches')->where('audit_event_id', $event->id)->pluck('branch_id')->sort()->values()->all())
        ->toBe([$this->f->org->a->id, $this->f->org->b->id]);
});

it('requires a reason, a valid assignee, the current version and both branches in the grant', function () {
    $url = "/orders/{$this->order->id}/branch-transfer";
    $to = $this->f->org->b->id;

    $this->actingAs($this->manager)->postJson($url, ['branch_id' => $to, 'lock_version' => 1])->assertJsonValidationErrors('reason');
    $this->actingAs($this->manager)->postJson($url, ['branch_id' => $to, 'reason' => 'نقل', 'lock_version' => 1])->assertJsonValidationErrors('assignee_id');
    $this->actingAs($this->manager)->postJson($url, ['branch_id' => $this->f->org->a->id, 'reason' => 'نقل', 'lock_version' => 1])->assertJsonValidationErrors('branch_id');
    $this->actingAs($this->manager)->postJson($url, ['branch_id' => $to, 'reason' => 'نقل', 'assignee_id' => $this->manager->id, 'lock_version' => 9])->assertStatus(409);

    // Manager of A and B cannot move into C; sales has no P-19 at all.
    $this->actingAs($this->manager)->postJson($url, ['branch_id' => $this->f->org->c->id, 'reason' => 'نقل', 'assignee_id' => $this->manager->id, 'lock_version' => 1])->assertForbidden();
    $this->actingAs($this->f->sales)->postJson($url, ['branch_id' => $to, 'reason' => 'نقل', 'assignee_id' => $this->f->sales->id, 'lock_version' => 1])->assertForbidden();

    expect($this->order->refresh()->branch_id)->toBe($this->f->org->a->id)
        ->and($this->order->lock_version)->toBe(1);
});

it('changes nothing when the apply fails after the write', function () {
    failingAudit('audit store down');

    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs($this->manager)->postJson("/orders/{$this->order->id}/branch-transfer", [
        'branch_id' => $this->f->org->b->id, 'reason' => 'نقل', 'assignee_id' => $this->manager->id, 'lock_version' => 1,
    ]))->toThrow(RuntimeException::class, 'audit store down');

    $order = Order::query()->findOrFail($this->order->id);
    expect($order->branch_id)->toBe($this->f->org->a->id)
        ->and($order->assignee_id)->toBe($this->f->sales->id)
        ->and($order->lock_version)->toBe(1);
});

it('moves a customer with P-10 over both branches; its orders keep their branch', function () {
    $customer = $this->f->inA;
    $impact = $this->actingAs($this->manager)->getJson("/customers/{$customer->id}/branch-transfer?branch_id={$this->f->org->b->id}")->assertOk()->json('impact');
    expect($impact['orders_staying'])->toBe(1);

    $this->actingAs($this->f->sales)->postJson("/customers/{$customer->id}/branch-transfer", ['branch_id' => $this->f->org->b->id, 'reason' => 'نقل'])->assertForbidden();
    $this->actingAs($this->manager)->postJson("/customers/{$customer->id}/branch-transfer", ['branch_id' => $this->f->org->b->id])->assertJsonValidationErrors('reason');

    $this->actingAs($this->manager)->postJson("/customers/{$customer->id}/branch-transfer", ['branch_id' => $this->f->org->b->id, 'reason' => 'نقل العميل'])->assertOk();

    expect(Customer::query()->find($customer->id)->branch_id)->toBe($this->f->org->b->id)
        ->and($this->order->refresh()->branch_id)->toBe($this->f->org->a->id)
        ->and(AuditEvent::query()->where('action', AuditAction::CustomerBranchTransferred->value)->value('reason'))->toBe('نقل العميل');
});

it('rolls back a customer move when the apply fails', function () {
    failingAudit('boom');
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->manager)->postJson("/customers/{$this->f->inA->id}/branch-transfer", ['branch_id' => $this->f->org->b->id, 'reason' => 'نقل']))
        ->toThrow(RuntimeException::class);
    expect(Customer::query()->find($this->f->inA->id)->branch_id)->toBe($this->f->org->a->id);
});
