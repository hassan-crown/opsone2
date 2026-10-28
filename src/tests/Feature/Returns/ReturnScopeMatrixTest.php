<?php

/**
 * Scope matrix of returns (ADR-0019): each act needs its own grant over the ORDER — P-53 or P-42 to request, P-102 to
 * decide, P-53 (also on the location) to receive and inspect, P-42 to ask the refund; no other stock, delivery or
 * order permission does any of it, and a grant of another branch or region sees nothing.
 */

use App\Access\Access;
use App\Access\Permission;
use App\Returns\ReturnAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ReturnTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = returnFixture(10, 5, 3);
    $this->org = $this->f->org;
});

dataset('permissions that never touch a return', ['P-18', 'P-37', 'P-45', 'P-46', 'P-47', 'P-48', 'P-49', 'P-50', 'P-51', 'P-52', 'P-43']);

it('does not let any other permission request, decide or receive a return', function (string $code) {
    $member = $this->org->member(null);
    grantOrders($member, $code, 'tenant');
    $return = rtRequest($this->f->keeper, $this->f->order, $this->f->line, '1');

    expect(ReturnAccess::any($member))->toBeFalse()
        ->and(ReturnAccess::canRequest($member, $this->f->order))->toBeFalse()
        ->and(ReturnAccess::canView($member, $return))->toBeFalse();
    $this->actingAs($member)->postJson("/orders/{$this->f->order->id}/returns", [
        'idempotency_key' => 'k-'.$code.'-0001', 'reason' => 'سبب الإرجاع', 'lines' => [['order_line_id' => $this->f->line, 'quantity' => '1']],
    ])->assertForbidden();
    $this->actingAs($member)->postJson("/returns/{$return->id}/accept", ['idempotency_key' => 'k-'.$code.'-0002', 'reason' => 'مقبول'])->assertForbidden();
    $this->actingAs($member)->getJson('/returns')->assertForbidden();
    $this->actingAs($member)->getJson("/returns/{$return->id}")->assertForbidden();
})->with('permissions that never touch a return');

it('covers an order by its branch, its region and the facility for each return permission', function (string $code) {
    $permission = Permission::from($code);
    $branch = $this->org->member(null);
    grantOrders($branch, $code, 'branches', [$this->org->a->id]);
    $otherBranch = $this->org->member(null);
    grantOrders($otherBranch, $code, 'branches', [$this->org->b->id]);
    $region = $this->org->member(null);
    grantOrders($region, $code, 'region', [$this->org->north->id]);
    $south = $this->org->member(null);
    grantOrders($south, $code, 'region', [$this->org->south->id]);
    $facility = $this->org->member(null);
    grantOrders($facility, $code, 'tenant');
    $return = rtRequest($this->f->keeper, $this->f->order, $this->f->line, '1');

    foreach ([[$branch, true], [$otherBranch, false], [$region, true], [$south, false], [$facility, true]] as [$user, $allowed]) {
        expect(Access::can($user, $permission, $return))->toBe($allowed)
            ->and(ReturnAccess::canView($user, $return))->toBe($allowed);
    }
    $this->actingAs($otherBranch)->getJson('/returns')->assertOk()->assertJsonPath('returns.data', []);
    $this->actingAs($branch)->getJson('/returns')->assertOk()->assertJsonPath('returns.data.0.number', $return->number);
})->with(['P-53', 'P-102', 'P-42']);

it('receives only at a location the P-53 grant covers', function () {
    $return = rtRequest($this->f->keeper, $this->f->order, $this->f->line, '1');
    rtAccept($this->f->manager, $return);
    // P-53 on the order (region north covers branch A) but the location belongs to branch A only through a branch grant.
    $receiver = $this->org->member(null);
    grantOrders($receiver, 'P-53', 'branches', [$this->org->a->id]);

    expect(ReturnAccess::canReceiveAt($receiver, $this->f->location))->toBeTrue()
        ->and(ReturnAccess::canReceiveAt($receiver, $this->f->s->lc))->toBeFalse()
        ->and(ReturnAccess::canReceiveAt($receiver, $this->f->s->main))->toBeFalse();
    rtReceive($receiver, $return, $this->f->location, '1');
    expect($return->refresh()->status->value)->toBe('received');
});
