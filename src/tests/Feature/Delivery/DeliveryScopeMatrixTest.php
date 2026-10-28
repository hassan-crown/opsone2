<?php

/**
 * Scope matrix of delivery (ADR-0018): recording a delivery is P-52 over the ORDER (branch / region / facility /
 * assigned); no other stock or order permission (P-18, P-45..P-51, P-53) records one; the note's files follow the order.
 */

use App\Access\Access;
use App\Access\Permission;
use App\Delivery\DeliveryAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/DeliveryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->d = deliveryFixture(10, 5);
    $this->org = $this->d->s->f->org;
    $this->line = deliveryLineId($this->d->order);
});

dataset('permissions that never deliver', ['P-18', 'P-45', 'P-46', 'P-47', 'P-48', 'P-49', 'P-50', 'P-51', 'P-53']);

it('does not let any other stock or order permission record a delivery', function (string $code) {
    $member = $this->org->member(null);
    grantOrders($member, $code, 'tenant');

    expect(DeliveryAccess::canRecord($member, $this->d->order))->toBeFalse()
        ->and(DeliveryAccess::any($member))->toBeFalse();
    $this->actingAs($member)->postJson("/orders/{$this->d->order->id}/deliveries", [
        'idempotency_key' => 'k-'.$code.'-0001', 'lines' => [['order_line_id' => $this->line, 'quantity' => '1']],
    ])->assertForbidden();
    $this->actingAs($member)->getJson('/deliveries')->assertForbidden();
})->with('permissions that never deliver');

it('P-52 covers an order by its branch, its region and the facility', function () {
    $branch = $this->org->member(null);
    grantOrders($branch, 'P-52', 'branches', [$this->org->a->id]);
    $otherBranch = $this->org->member(null);
    grantOrders($otherBranch, 'P-52', 'branches', [$this->org->b->id]);
    $region = $this->org->member(null);
    grantOrders($region, 'P-52', 'region', [$this->org->north->id]);
    $south = $this->org->member(null);
    grantOrders($south, 'P-52', 'region', [$this->org->south->id]);
    $facility = $this->org->member(null);
    grantOrders($facility, 'P-52', 'tenant');
    $order = $this->d->order;

    expect(Access::can($branch, Permission::P52, $order))->toBeTrue()
        ->and(Access::can($otherBranch, Permission::P52, $order))->toBeFalse()
        ->and(Access::can($region, Permission::P52, $order))->toBeTrue()
        ->and(Access::can($south, Permission::P52, $order))->toBeFalse()
        ->and(Access::can($facility, Permission::P52, $order))->toBeTrue();
});

it('lists and opens only the notes of the covered orders; a wrong branch gets 403 / empty', function () {
    $note = deliveryDraft($this->d->keeper, $this->d->order, [$this->line => '1']);
    $otherBranch = $this->org->member('inventory', [$this->org->b]);

    $this->actingAs($this->d->keeper)->getJson('/deliveries')->assertOk()->assertJsonCount(1, 'notes.data')->assertJsonCount(1, 'awaiting');
    $this->actingAs($otherBranch)->getJson('/deliveries')->assertOk()->assertJsonCount(0, 'notes.data')->assertJsonCount(0, 'awaiting');
    $this->actingAs($otherBranch)->getJson("/deliveries/{$note->id}")->assertForbidden();
    $this->actingAs($otherBranch)->postJson("/deliveries/{$note->id}/cancel", ['reason' => 'محاولة'])->assertForbidden();
    $this->actingAs($this->d->keeper)->getJson("/deliveries/{$note->id}")->assertOk()->assertJsonPath('note.status', 'draft');
});

it('the default roles: inventory, manager and owner may deliver in scope; sales and finance may not', function () {
    $order = $this->d->order;
    $roles = ['inventory' => true, 'manager' => true, 'sales' => false, 'finance' => false];
    foreach ($roles as $role => $expected) {
        $user = $this->org->member($role, [$this->org->a]);
        expect(Access::can($user, Permission::P52, $order))->toBe($expected, $role);
    }
    expect(Access::can($this->org->owner, Permission::P52, $order))->toBeTrue();
});
