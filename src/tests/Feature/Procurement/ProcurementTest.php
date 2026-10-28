<?php

/**
 * P7 part B — suppliers, purchase orders (M-13) and receiving (FUL-05..07): the SRS numbers of FUL-03 / FUL-06 and the
 * scenario «توريد وتسليم جزئيان» (ordered 10, 4 accepted + 1 rejected arrive → 6 remain; the rest later), a double
 * receive that touches the stock once, cancelling the remainder after a partial receipt, gapless PO numbers under
 * rollback, the scope matrix, costs hidden without P-82 and exactly one StockReceiver call per received line.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\Procurement\SaveSupplier;
use App\Actions\Procurement\SetSupplierStatus;
use App\Actions\Procurement\UpdateDraftPurchase;
use App\Actions\StateMachines\M13PurchaseOrder\T01ConfirmPurchaseOrder;
use App\Actions\StateMachines\M13PurchaseOrder\T03CancelRemainder;
use App\Actions\StateMachines\M13PurchaseOrder\T04AdjustQuantities;
use App\Enums\PurchaseStatus;
use App\Files\AttachmentService;
use App\Inventory\Contracts\StockReceiver;
use App\Models\AuditEvent;
use App\Models\FinanceSettings;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseReceipt;
use App\Models\Procurement\Supplier;
use App\Models\StateTransition;
use App\Procurement\PurchaseAccess;
use App\Procurement\PurchasePresenter;
use App\Support\StaleRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/ProcurementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = procFixture();
    $this->year = now()->format('Y');
});

// --- suppliers -------------------------------------------------------------------------------------------------

it('keeps a supplier with a bilingual name, contact and a validated VAT number', function () {
    $save = app(SaveSupplier::class);
    $supplier = $save->handle($this->f->inventory, null, [
        'name' => ['ar' => 'مؤسسة الأدوات', 'en' => 'Tools Est.'], 'contact_name' => 'سعد', 'phone' => '0551234567', 'email' => 'a@b.sa',
        'vat_number' => '300000000000003', 'supplies' => 'مقابض', 'notes' => null,
    ], null, procKey());

    expect($supplier->name->get('en'))->toBe('Tools Est.')
        ->and($supplier->vat_number)->toBe('300000000000003')
        ->and(Supplier::validVatNumber('300000000000003'))->toBeTrue()
        ->and(Supplier::validVatNumber('200000000000003'))->toBeFalse()
        ->and(Supplier::validVatNumber('30000000000003'))->toBeFalse();
    expect(AuditEvent::query()->where('action', 'supplier.created')->count())->toBe(1);
});

it('refuses supplier management without P-45 and stale edits', function () {
    $data = ['name' => ['ar' => 'مورد', 'en' => null], 'contact_name' => null, 'phone' => null, 'email' => null, 'vat_number' => null, 'supplies' => null, 'notes' => null];
    // P-45 is for owner, manager and inventory: a sales member has no grant at all.
    expect(fn () => app(SaveSupplier::class)->handle($this->f->sales, null, $data, null, procKey()))->toThrow(AuthorizationException::class);

    $supplier = app(SaveSupplier::class)->handle($this->f->inventory, null, $data, null, procKey());
    app(SaveSupplier::class)->handle($this->f->inventory, $supplier, [...$data, 'phone' => '0550000000'], $supplier->lock_version, null);
    expect(fn () => app(SaveSupplier::class)->handle($this->f->inventory, $supplier, [...$data, 'phone' => '0551111111'], $supplier->lock_version, null))
        ->toThrow(StaleRecord::class);
});

it('disables a supplier without losing its purchases; a disabled supplier cannot take a new order', function () {
    $po = procOrdered($this->f);
    app(SetSupplierStatus::class)->handle($this->f->inventory, $this->f->supplier, false);

    expect($this->f->supplier->refresh()->isActive())->toBeFalse()
        ->and(PurchaseOrder::query()->whereKey($po->id)->exists())->toBeTrue();
    expect(fn () => procDraft($this->f, $this->f->inventory, [procVariantLine($this->f)]))->toThrow(ValidationException::class);

    // The existing order can still be received.
    procReceive($this->f, $po, [1 => [4_000]]);
    expect($po->refresh()->status)->toBe(PurchaseStatus::PartiallyReceived);

    app(SetSupplierStatus::class)->handle($this->f->inventory, $this->f->supplier, true);
    expect(procDraft($this->f, $this->f->inventory, [procVariantLine($this->f)])->status)->toBe(PurchaseStatus::Draft);
});

// --- numbering ----------------------------------------------------------------------------------------------------

it('numbers purchase orders PO-YYYY-000123 gaplessly, a rolled-back order gives its number back', function () {
    $first = procDraft($this->f, $this->f->inventory, [procVariantLine($this->f)]);
    expect($first->number)->toBe("PO-{$this->year}-000001");

    // The number is taken inside the order's transaction; an invalid line fails after it and rolls it back.
    expect(fn () => procDraft($this->f, $this->f->inventory, [procLine('ready_variant', 999_999, 1_000)]))->toThrow(ValidationException::class);
    expect(DB::table('sequence_counters')->where('name', 'po')->value('last_value'))->toBe(1);

    $second = procDraft($this->f, $this->f->inventory, [procVariantLine($this->f)]);
    expect($second->number)->toBe("PO-{$this->year}-000002");
    expect(PurchaseOrder::query()->orderBy('id')->pluck('number')->all())->toBe(["PO-{$this->year}-000001", "PO-{$this->year}-000002"]);
});

it('creates an order once per idempotency key', function () {
    $key = procKey();
    $a = procDraft($this->f, $this->f->inventory, [procVariantLine($this->f)], [], $key);
    $b = procDraft($this->f, $this->f->inventory, [procVariantLine($this->f)], [], $key);

    expect($b->id)->toBe($a->id)->and(PurchaseOrder::query()->count())->toBe(1);
    expect(fn () => procDraft($this->f, $this->f->inventory, [procVariantLine($this->f, 5_000)], [], $key))->toThrow(ValidationException::class);
});

// --- money and VAT ---------------------------------------------------------------------------------------------

it('prices lines by the facility VAT policy: inclusive splits the VAT out, exclusive adds it', function () {
    // Default policy: 15%, prices include VAT. 10 × 11.50 = 115.00 → VAT 15.00, net 100.00.
    $po = procDraft($this->f, $this->f->inventory, [procVariantLine($this->f, 10_000, 1150)]);
    expect([$po->net_minor, $po->vat_minor, $po->gross_minor])->toBe([10_000, 1_500, 11_500]);

    FinanceSettings::query()->create(['vat_rate_bp' => 1500, 'prices_include_vat' => false, 'quote_validity_days' => 14, 'contracts_enabled' => true]);
    $exclusive = procDraft($this->f, $this->f->inventory, [procVariantLine($this->f, 10_000, 1150)]);
    expect([$exclusive->net_minor, $exclusive->vat_minor, $exclusive->gross_minor])->toBe([11_500, 1_725, 13_225]);

    // A fractional quantity rounds once per line (half-up): 2.5 × 3.33 = 8.325 → 8.33 (833 halalas).
    $odd = procDraft($this->f, $this->f->inventory, [procLine('material', $this->f->tracked->id, 2_500, 333)]);
    expect($odd->lines->first()->net_minor)->toBe(833);
});

// --- the partial-supply scenario ------------------------------------------------------------------------------------

it('scenario: ordered 10, 4 accepted + 1 rejected arrive → 6 remain; the rest arrives later and completes the order', function () {
    $po = procOrdered($this->f);

    $first = procReceive($this->f, $po, [1 => [4_000, 1_000, 'مكسور']]);
    expect(procLineOf($po, 1))->toBe(['quantity' => 10_000, 'received' => 5_000, 'accepted' => 4_000, 'rejected' => 1_000, 'remaining' => 6_000])
        ->and($po->refresh()->status)->toBe(PurchaseStatus::PartiallyReceived);
    // Only the accepted part reaches the stock.
    expect($this->f->stock->calls)->toHaveCount(1)
        ->and($this->f->stock->calls[0]['quantityMilli'])->toBe(4_000);
    expect($first->lines->first()->reject_reason)->toBe('مكسور');

    procReceive($this->f, $po, [1 => [6_000]]);
    expect(procLineOf($po, 1)['remaining'])->toBe(0)
        ->and($po->refresh()->status)->toBe(PurchaseStatus::Received);
    expect(array_sum(array_column($this->f->stock->calls, 'quantityMilli')))->toBe(10_000);
});

it('FUL-03: required 10, receiving 4 leaves 6; a repeated notification of the same receipt does not add', function () {
    $po = procOrdered($this->f);
    $key = procKey();
    $version = $po->lock_version;
    procReceive($this->f, $po, [1 => [4_000]], ['lock_version' => $version], $key);
    procReceive($this->f, $po, [1 => [4_000]], ['lock_version' => $version], $key);

    expect(procLineOf($po, 1)['remaining'])->toBe(6_000);
});

it('receives with fractional quantities in thousandths', function () {
    $po = procOrdered($this->f, [procLine('material', $this->f->tracked->id, 2_500, 400)]);
    procReceive($this->f, $po, [1 => [1_250]]);

    expect(procLineOf($po, 1)['remaining'])->toBe(1_250)
        ->and($this->f->stock->calls[0])->toMatchArray(['itemType' => 'material', 'quantityMilli' => 1_250, 'locationId' => $this->f->location]);
});

it('records an all-rejected arrival without touching the stock or the remainder', function () {
    $po = procOrdered($this->f);
    procReceive($this->f, $po, [1 => [0, 3_000, 'تالف']]);

    expect($this->f->stock->calls)->toBe([])
        ->and(procLineOf($po, 1))->toMatchArray(['accepted' => 0, 'rejected' => 3_000, 'remaining' => 10_000])
        ->and($po->refresh()->status)->toBe(PurchaseStatus::PartiallyReceived);
});

// --- idempotent receive --------------------------------------------------------------------------------------------

it('a double receive with the same key creates one receipt and reaches the stock once', function () {
    $po = procOrdered($this->f);
    $key = procKey();
    $version = $po->lock_version;

    $a = procReceive($this->f, $po, [1 => [4_000]], ['lock_version' => $version], $key);
    $b = procReceive($this->f, $po, [1 => [4_000]], ['lock_version' => $version], $key);

    expect($b->id)->toBe($a->id)
        ->and(PurchaseReceipt::query()->count())->toBe(1)
        ->and($this->f->stock->calls)->toHaveCount(1)
        ->and(procLineOf($po, 1)['accepted'])->toBe(4_000);
    // The same key with other numbers is a mistake, not a retry.
    expect(fn () => procReceive($this->f, $po, [1 => [5_000]], ['lock_version' => $version], $key))->toThrow(ValidationException::class);
});

it('calls the StockReceiver exactly once per received line with the right quantities and a deterministic key', function () {
    $po = procOrdered($this->f, [
        procVariantLine($this->f, 10_000), procLine('material', $this->f->tracked->id, 3_000, 200), procLine('material', $this->f->plain->id, 8_000, 90),
    ]);
    $receipt = procReceive($this->f, $po, [1 => [4_000, 1_000, 'x'], 2 => [3_000], 3 => [8_000]]);
    $lines = $po->refresh()->lines->keyBy('line_no');

    // The untracked material is descriptive (FUL-04) and never reaches the stock.
    expect($this->f->stock->calls)->toHaveCount(2);
    expect($this->f->stock->calls[0])->toBe([
        'locationId' => $this->f->location, 'itemType' => 'ready_variant', 'itemId' => $this->f->variant->id, 'quantityMilli' => 4_000,
        'sourceType' => 'purchase_receipt', 'sourceId' => $receipt->id, 'actorId' => $this->f->inventory->id,
        'idempotencyKey' => "po-receipt:{$receipt->id}:{$lines[1]->id}",
    ]);
    expect($this->f->stock->calls[1])->toMatchArray(['itemType' => 'material', 'itemId' => $this->f->tracked->id, 'quantityMilli' => 3_000, 'idempotencyKey' => "po-receipt:{$receipt->id}:{$lines[2]->id}"]);
    expect($po->status)->toBe(PurchaseStatus::PartiallyReceived);
});

it('rolls the receipt back with the stock call when it fails, leaving the order untouched', function () {
    $po = procOrdered($this->f);
    app()->instance(StockReceiver::class, new class implements StockReceiver
    {
        public function receive(int $locationId, string $itemType, int $itemId, int $quantityMilli, string $sourceType, int $sourceId, ?int $actorId, string $idempotencyKey): void
        {
            throw new RuntimeException('stock down');
        }
    });

    expect(fn () => procReceive($this->f, $po, [1 => [4_000]]))->toThrow(RuntimeException::class);
    expect(PurchaseReceipt::query()->count())->toBe(0)
        ->and(procLineOf($po, 1)['accepted'])->toBe(0)
        ->and($po->refresh()->status)->toBe(PurchaseStatus::Ordered);
});

// --- over-receipt --------------------------------------------------------------------------------------------------

it('refuses an accepted quantity above the remainder until a larger quantity is agreed explicitly', function () {
    $po = procOrdered($this->f);
    procReceive($this->f, $po, [1 => [4_000]]);

    expect(fn () => procReceive($this->f, $po, [1 => [7_000]]))->toThrow(ValidationException::class);
    expect(procLineOf($po, 1)['accepted'])->toBe(4_000);

    $line = $po->lines()->first();
    app(T04AdjustQuantities::class)->handle($this->f->inventory, $po->refresh(), [['line_id' => $line->id, 'quantity_milli' => 12_000]], 'اتفاق مع المورد على زيادة.', $po->lock_version, null);
    procReceive($this->f, $po, [1 => [7_000]]);

    expect(procLineOf($po, 1))->toMatchArray(['quantity' => 12_000, 'accepted' => 11_000, 'remaining' => 1_000])
        ->and($po->refresh()->status)->toBe(PurchaseStatus::PartiallyReceived);
    expect(StateTransition::query()->where('machine', 'M-13')->where('transition', 'T04')->count())->toBe(1);
});

it('never lets an adjustment drop the quantity below what was accepted and re-derives the state', function () {
    $po = procOrdered($this->f);
    procReceive($this->f, $po, [1 => [4_000]]);
    $line = $po->lines()->first();

    expect(fn () => app(T04AdjustQuantities::class)->handle($this->f->inventory, $po->refresh(), [['line_id' => $line->id, 'quantity_milli' => 3_000]], 'تخفيض', $po->lock_version, null))
        ->toThrow(ValidationException::class);
    expect(fn () => app(T04AdjustQuantities::class)->handle($this->f->inventory, $po->refresh(), [['line_id' => $line->id, 'quantity_milli' => 10_000]], 'بلا تغيير', $po->lock_version, null))
        ->toThrow(ValidationException::class);

    // Reducing to exactly what was accepted completes the order.
    app(T04AdjustQuantities::class)->handle($this->f->inventory, $po->refresh(), [['line_id' => $line->id, 'quantity_milli' => 4_000]], 'اكتفينا بما وصل.', $po->lock_version, null);
    expect($po->refresh()->status)->toBe(PurchaseStatus::Received);
});

it('requires a reason for every rejected quantity and something to receive', function () {
    $po = procOrdered($this->f);

    expect(fn () => procReceive($this->f, $po, [1 => [0, 2_000]]))->toThrow(ValidationException::class);
    expect(fn () => procReceive($this->f, $po, [1 => [0, 0]]))->toThrow(ValidationException::class);
});

// --- cancel the remainder ---------------------------------------------------------------------------------------------

it('cancelling the remainder after a partial receipt keeps what was received and refuses further receipts', function () {
    $po = procOrdered($this->f);
    procReceive($this->f, $po, [1 => [4_000]]);
    $callsBefore = $this->f->stock->calls;

    app(T03CancelRemainder::class)->handle($this->f->inventory, $po->refresh(), 'العميل ألغى الطلب.', $po->lock_version, procKey());

    expect($po->refresh()->status)->toBe(PurchaseStatus::CancelledRemainder)
        ->and($po->cancel_reason)->toBe('العميل ألغى الطلب.')
        ->and(procLineOf($po, 1)['accepted'])->toBe(4_000)
        ->and(PurchaseReceipt::query()->count())->toBe(1)
        // Nothing is taken back out of the stock.
        ->and($this->f->stock->calls)->toBe($callsBefore);
    $detail = app(PurchasePresenter::class)->detail($po, $this->f->inventory);
    expect($detail['lines'][0])->toMatchArray(['remaining_milli' => 0, 'cancelled_milli' => 6_000, 'accepted_milli' => 4_000]);

    expect(fn () => procReceive($this->f, $po, [1 => [1_000]]))->toThrow(InvalidTransition::class);
    expect(fn () => app(T03CancelRemainder::class)->handle($this->f->inventory, $po->refresh(), 'مرة أخرى', null, null))->toThrow(InvalidTransition::class);
});

it('cancels a draft and demands a reason', function () {
    $po = procDraft($this->f, $this->f->inventory, [procVariantLine($this->f)]);

    expect(fn () => app(T03CancelRemainder::class)->handle($this->f->inventory, $po, ' ', null, null))->toThrow(ValidationException::class);
    app(T03CancelRemainder::class)->handle($this->f->inventory, $po, 'لم تعد هناك حاجة.', null, null);
    expect($po->refresh()->status)->toBe(PurchaseStatus::CancelledRemainder);
});

// --- state, audit, locks --------------------------------------------------------------------------------------------------

it('logs every transition and audits the writes', function () {
    $po = procOrdered($this->f);
    procReceive($this->f, $po, [1 => [10_000]]);

    $log = StateTransition::query()->where('machine', 'M-13')->where('subject_id', $po->id)->orderBy('id')->get();
    expect($log->pluck('transition')->all())->toBe(['T00', 'T01', 'T02'])
        ->and($log->pluck('to_state')->all())->toBe(['draft', 'ordered', 'received'])
        ->and($log->last()->context['lines'][0])->toMatchArray(['accepted_milli' => 10_000, 'remaining_milli' => 0]);
    expect(AuditEvent::query()->whereIn('action', ['purchase.created', 'purchase.confirmed', 'purchase.received'])->count())->toBe(3);
});

it('checks the optimistic lock on every write and refuses transitions from the wrong state', function () {
    $po = procDraft($this->f, $this->f->inventory, [procVariantLine($this->f)]);

    expect(fn () => app(T01ConfirmPurchaseOrder::class)->handle($this->f->inventory, $po, $po->lock_version + 5, null))->toThrow(StaleRecord::class);
    expect(fn () => procReceive($this->f, $po, [1 => [1_000]]))->toThrow(InvalidTransition::class);

    app(T01ConfirmPurchaseOrder::class)->handle($this->f->inventory, $po, $po->lock_version, null);
    expect(fn () => app(T01ConfirmPurchaseOrder::class)->handle($this->f->inventory, $po->refresh(), null, null))->toThrow(InvalidTransition::class);

    $stale = $po->lock_version;
    procReceive($this->f, $po, [1 => [1_000]]);
    expect(fn () => app(T03CancelRemainder::class)->handle($this->f->inventory, $po->refresh(), 'سبب', $stale, null))->toThrow(StaleRecord::class);
});

it('confirms only a complete order: location, date, responsible and lines', function () {
    $po = procDraft($this->f, $this->f->inventory, [procVariantLine($this->f)], ['location_id' => null, 'expected_on' => null, 'responsible_id' => null]);

    try {
        app(T01ConfirmPurchaseOrder::class)->handle($this->f->inventory, $po, null, null);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toEqualCanonicalizing(['location_id', 'expected_on', 'responsible_id']);
    }
    expect($po->refresh()->status)->toBe(PurchaseStatus::Draft);
});

it('edits a draft only while it is a draft', function () {
    $po = procDraft($this->f, $this->f->inventory, [procVariantLine($this->f)]);
    $data = ['supplier_id' => $this->f->supplier->id, 'branch_id' => $this->f->org->a->id, 'location_id' => 7, 'expected_on' => now()->addDay()->toDateString(), 'responsible_id' => $this->f->inventory->id, 'notes' => 'ملاحظة',
        'lines' => [procVariantLine($this->f, 4_000, 900)]];
    app(UpdateDraftPurchase::class)->handle($this->f->inventory, $po, $data, $po->lock_version, null);
    expect($po->refresh()->notes)->toBe('ملاحظة')->and(procLineOf($po, 1)['quantity'])->toBe(4_000);

    app(T01ConfirmPurchaseOrder::class)->handle($this->f->inventory, $po->refresh(), null, null);
    expect(fn () => app(UpdateDraftPurchase::class)->handle($this->f->inventory, $po->refresh(), $data, null, null))->toThrow(InvalidTransition::class);
});

// --- scope matrix ---------------------------------------------------------------------------------------------------

it('scope matrix: receiving needs P-47 in the order\'s branch, buying P-46, costs P-82', function () {
    $po = procOrdered($this->f);

    // Inventory of branch B holds P-47 but only over branch B.
    expect(fn () => procReceive($this->f, $po, [1 => [1_000]], [], null, $this->f->inventoryB))->toThrow(AuthorizationException::class);
    // A branch-A manager buys (P-46) but does not receive (P-47 is owner/inventory only).
    expect(fn () => procReceive($this->f, $po, [1 => [1_000]], [], null, $this->f->manager))->toThrow(AuthorizationException::class);
    // Sales holds nothing here.
    expect(fn () => procDraft($this->f, $this->f->sales, [procVariantLine($this->f)]))->toThrow(AuthorizationException::class);
    // The manager cancels, branch-B inventory cannot create for branch A.
    expect(fn () => procDraft($this->f, $this->f->inventoryB, [procVariantLine($this->f)]))->toThrow(AuthorizationException::class);
    app(T03CancelRemainder::class)->handle($this->f->manager, $po, 'ألغيت من المدير.', null, null);
    expect($po->refresh()->status)->toBe(PurchaseStatus::CancelledRemainder);
    // And nothing reached the stock through the refused attempts.
    expect($this->f->stock->calls)->toBe([]);
});

it('lists only the orders of the viewer\'s scope', function () {
    $mine = procOrdered($this->f);
    $other = procDraft($this->f, $this->f->owner, [procVariantLine($this->f)], ['branch_id' => $this->f->org->b->id]);

    $visible = fn ($user) => PurchaseAccess::scoped(PurchaseOrder::query(), $user)->pluck('id')->all();
    expect($visible($this->f->inventory))->toBe([$mine->id])
        ->and($visible($this->f->inventoryB))->toBe([$other->id])
        ->and($visible($this->f->sales))->toBe([])
        ->and($visible($this->f->owner))->toEqualCanonicalizing([$mine->id, $other->id]);
});

// --- costs hidden ---------------------------------------------------------------------------------------------------------

it('hides costs from a member without P-82: not stored, not shown, kept on edit', function () {
    procDeny($this->f->manager, 'P-82');
    $po = procOrdered($this->f, [procVariantLine($this->f, 10_000, 1150)]);
    $presenter = app(PurchasePresenter::class);

    // With P-82 the cost and the totals are in the payload.
    $full = $presenter->detail($po, $this->f->inventory);
    expect($full['costs_visible'])->toBeTrue()
        ->and($full['totals'])->toBe(['net_minor' => 10_000, 'vat_minor' => 1_500, 'gross_minor' => 11_500])
        ->and($full['lines'][0]['unit_cost_minor'])->toBe(1150);

    // Without it the keys are absent altogether.
    $hidden = $presenter->detail($po, $this->f->manager);
    expect($hidden['costs_visible'])->toBeFalse()
        ->and($hidden)->not->toHaveKeys(['totals', 'vat'])
        ->and($hidden['lines'][0])->not->toHaveKeys(['unit_cost_minor', 'net_minor', 'vat_minor', 'gross_minor']);
    expect(PurchasePresenter::row($po->load(['supplier', 'branch', 'lines']), $this->f->manager))->not->toHaveKey('gross_minor');
    expect(PurchasePresenter::row($po, $this->f->inventory))->toHaveKey('gross_minor', 11_500);
    expect(json_encode($hidden))->not->toContain('1150');

    // A cost typed by someone without P-82 is dropped; the cost of a line they resend is kept.
    $draft = procDraft($this->f, $this->f->manager, [procVariantLine($this->f, 2_000, 5_000)]);
    expect($draft->lines->first()->unit_cost_minor)->toBeNull();
    $withCost = procDraft($this->f, $this->f->inventory, [procVariantLine($this->f, 2_000, 700)]);
    $lineId = $withCost->lines->first()->id;
    app(UpdateDraftPurchase::class)->handle($this->f->manager, $withCost, [
        'supplier_id' => $this->f->supplier->id, 'branch_id' => $this->f->org->a->id, 'location_id' => 7, 'expected_on' => null, 'responsible_id' => null, 'notes' => null,
        'lines' => [[...procVariantLine($this->f, 3_000, 999_999), 'id' => $lineId]],
    ], $withCost->lock_version, null);
    $kept = $withCost->refresh()->lines->first();
    expect($kept->unit_cost_minor)->toBe(700)->and($kept->quantity_milli)->toBe(3_000);
});

// --- attachments ---------------------------------------------------------------------------------------------------------------

it('stores receipt proofs as private attachments that follow the order\'s scope', function () {
    $po = procOrdered($this->f);
    $receipt = procReceive($this->f, $po, [1 => [4_000]], [], null, null, [procPdf()]);
    $file = $receipt->attachments()->firstOrFail();

    $files = app(AttachmentService::class);
    expect($file->purpose)->toBe('delivery_proof')
        ->and($files->canDownload($this->f->inventory, $file))->toBeTrue()
        ->and($files->canDownload($this->f->inventoryB, $file))->toBeFalse()
        ->and($files->canDownload($this->f->sales, $file))->toBeFalse();
    Storage::disk('attachments')->assertExists($file->path);
});
