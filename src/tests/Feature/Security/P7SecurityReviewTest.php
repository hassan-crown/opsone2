<?php

/**
 * Regressions of the P7 merge review (stock, procurement, delivery, returns, cancellation): each test pins one
 * confirmed finding so it cannot come back.
 */

use App\Access\Enums\Scope;
use App\Audit\AuditAction;
use App\Audit\AuditRecorder;
use App\Enums\MemberGrantEffect;
use App\Inventory\MovementKind;
use App\Inventory\StockItems;
use App\Inventory\StockLedger;
use App\Models\AuditEvent;
use App\Models\Inventory\StockMovement;
use App\Models\Settlement\SettlementItem;
use App\Models\User;
use App\Settlement\SettlementFollowUps;
use App\Support\LockingTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

require_once __DIR__.'/../Returns/ReturnTestHelpers.php';
require_once __DIR__.'/../Cancellation/CancellationTestHelpers.php';
require_once __DIR__.'/../Procurement/ProcurementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = returnFixture(10, 5, 5);
});

function p7Member(object $f, string $role, array $allow): User
{
    $user = $f->org->member($role, [$f->org->a]);
    foreach ($allow as $permission) {
        Org::membershipOf($user)->grants()->create(['permission' => $permission, 'effect' => MemberGrantEffect::Allow, 'scope' => Scope::Tenant]);
    }

    return $user->refresh();
}

it('hides return amounts in the audit log from a viewer without P-37 on the order', function () {
    $f = $this->f;
    rtAccept($f->manager, rtRequest($f->keeper, $f->order, $f->line, '2'));
    $accepted = AuditEvent::query()->where('action', AuditAction::ReturnAccepted->value)->sole();
    $auditor = p7Member($f, 'supervisor', ['P-63']);
    procDeny($auditor, 'P-37');

    $this->actingAs($auditor)->get('/org/audit?event='.$accepted->id)
        ->assertInertia(fn (Assert $page) => $page->where('selected.id', $accepted->id)->where('selected.after.settlement_minor', AuditRecorder::REDACTED));
    $this->actingAs($f->owner)->get('/org/audit?event='.$accepted->id)
        ->assertInertia(fn (Assert $page) => $page->where('selected.after.settlement_minor', fn ($v) => is_int($v) && $v > 0));
});

it('lets only a P-37 holder set a return settlement other than its value', function () {
    $f = $this->f;
    procDeny($f->manager, 'P-37');
    $return = rtRequest($f->keeper, $f->order, $f->line, '2');

    rtRefused(fn () => rtAccept($f->manager, $return, null, 100), AuthorizationException::class);
    expect(rtAccept($f->manager, $return)->status->value)->toBe('accepted');
});

it('authorizes a settlement item resolution before the follow-up is re-read', function () {
    $f = $this->f;
    $return = rtAccept($f->manager, rtRequest($f->keeper, $f->order, $f->line, '2'));
    $item = SettlementItem::query()->where('source', 'return:'.$return->id)->sole();
    $outsider = $f->org->member('sales', [$f->org->b]);

    $before = SettlementItem::query()->get()->map(fn (SettlementItem $i) => [$i->id, $i->status, $i->lock_version, $i->target_minor])->all();
    expect(fn () => app(SettlementFollowUps::class)->resolve($outsider, $item, SettlementItem::DROPPED, 'لا حاجة للرد', null))->toThrow(AuthorizationException::class);
    expect(SettlementItem::query()->get()->map(fn (SettlementItem $i) => [$i->id, $i->status, $i->lock_version, $i->target_minor])->all())->toBe($before)
        ->and(AuditEvent::query()->where('actor_id', $outsider->id)->count())->toBe(0);
});

it('posts a stock adjustment once per idempotency key', function () {
    $f = $this->f;
    $body = [
        'location_id' => $f->location->id, 'item_type' => 'ready_variant', 'item_id' => $f->s->f->variant->id,
        'direction' => 'in', 'quantity' => '1', 'reason' => 'وجدنا قطعة', 'idempotency_key' => (string) Str::uuid(),
    ];
    $first = $this->actingAs($f->owner)->postJson('/stock/adjustments', $body)->assertCreated()->json('movement');
    $again = $this->actingAs($f->owner)->postJson('/stock/adjustments', $body)->assertOk()->json('movement');

    expect($again)->toBe($first)
        ->and(StockMovement::query()->where('kind', MovementKind::AdjustmentIn->value)->count())->toBe(1);
    $this->actingAs($f->owner)->postJson('/stock/adjustments', ['quantity' => '2'] + $body)->assertUnprocessable();
    $this->actingAs($f->owner)->postJson('/stock/adjustments', array_diff_key($body, ['idempotency_key' => true]))->assertJsonValidationErrors('idempotency_key');
});

it('never lets stock arrive at a disabled location, whatever path posts it', function () {
    $f = $this->f;
    $f->s->la2->forceFill(['status' => 'disabled'])->save();
    $item = StockItems::resolve('ready_variant', $f->s->f->variant->id);
    $ledger = app(StockLedger::class);

    expect(fn () => LockingTransaction::run(fn () => $ledger->post(MovementKind::TransferIn, $ledger->lock($f->s->la2->id, $item->id), 1000)))
        ->toThrow(ValidationException::class);
    stockJournalMatches();
});
