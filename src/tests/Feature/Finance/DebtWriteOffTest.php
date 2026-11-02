<?php

/**
 * Debt at closure (ADR-0034, product owner's decision): an authorised write-off of what the customer still owes — P-103,
 * a mandatory reason, an optional file, a balanced journal, an audit entry, visible in collections and reports. After it
 * the order may close; it is undone only by a reversal entry, never by editing.
 */

use App\Access\Permission;
use App\Delivery\DeliveryProgress;
use App\Finance\CollectionReport;
use App\Finance\DebtWriteOffs;
use App\Finance\MoneyLedger;
use App\Finance\OpenDues;
use App\Models\Finance\DebtWriteOff;
use App\Models\Finance\DebtWriteOffEntry;
use App\Models\MemberGrant;
use App\Models\Order;
use App\Orders\OrderClosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Org;

require_once __DIR__.'/FinanceTestHelpers.php';
require_once __DIR__.'/../Delivery/DeliveryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
    finAgree($this->f, '1000');
    finPay($this->f->finance, $this->f->order, '300');
    // The flow is not what is tested here: the order is put where a closure is decided (execution finished) and its
    // made-to-order lines are delivered, so the only thing left is the money.
    $order = $this->f->order->refresh();
    $order->forceFill(['status' => 'execution_finished'])->save();
    $owed = array_filter(array_map(fn (array $r) => $r['deliverable'], DeliveryProgress::lines($order)));
    if ($owed !== []) {
        $q = [];
        foreach ($owed as $lineId => $milli) {
            $q[$lineId] = (string) ($milli / 1000);
        }
        deliveryConfirm($this->f->owner, deliveryDraft($this->f->owner, $order, $q));
    }
    $this->order = $order->refresh();
});

/**
 * @param  array<string, mixed>  $extra
 */
function writeOff(object $t, $user, ?int $amount = null, array $extra = [])
{
    return $t->actingAs($user)->post(route('orders.finance.write-offs.store', $t->order), [
        'amount_minor' => $amount ?? array_sum(DebtWriteOffs::owedByDue($t->order->refresh())), 'reason' => 'العميل متعثر وتمت التسوية بالتراضي',
        'idempotency_key' => (string) Str::uuid(), ...$extra,
    ], ['Accept' => 'application/json']);
}

function closureKeys(Order $order): array
{
    return array_column(OrderClosure::blockers($order->refresh()), 'key');
}

it('writes off what is owed with a balanced journal and an audit entry, and then the order may close', function () {
    expect(closureKeys($this->order))->toBe(['money_owed'])
        ->and(array_sum(DebtWriteOffs::owedByDue($this->order)))->toBe(70000);
    $collectedBefore = MoneyLedger::collected($this->order->id)->minor;

    writeOff($this, $this->f->owner, extra: ['file' => UploadedFile::fake()->createWithContent('settlement.pdf', "%PDF-1.4\n%x\n%%EOF")])
        ->assertCreated()->assertJsonPath('write_off.amount_minor', 70000);

    $w = DebtWriteOff::query()->sole();
    $entries = DebtWriteOffEntry::query()->where('debt_write_off_id', $w->id)->get();
    expect($w->number)->toBe('WO-'.now()->year.'-000001')->and($w->kind)->toBe('write_off')
        ->and((int) $entries->sum('amount_minor'))->toBe(0)
        ->and((int) $entries->where('account', 'bad_debt')->sum('amount_minor'))->toBe(70000)
        ->and((int) $entries->where('account', 'receivable')->sum('amount_minor'))->toBe(-70000)
        ->and(DebtWriteOffs::balanced($this->order->id))->toBeTrue()
        ->and(DebtWriteOffs::owedByDue($this->order))->toBe([])
        ->and(MoneyLedger::collected($this->order->id)->minor)->toBe($collectedBefore)
        ->and($w->attachments()->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'finance.debt_written_off')->count())->toBe(1)
        ->and(closureKeys($this->order))->toBe([]);

    $this->actingAs($this->f->owner)->postJson(route('orders.close', $this->order), ['reason' => 'شُطب الباقي'])->assertOk();
    expect($this->order->refresh()->status)->toBe('closed');
});

it('needs P-103 (the owner by default; finance and managers do not hold it) and refuses without effect', function () {
    expect($this->f->finance->id)->not->toBe($this->f->owner->id);
    foreach ([$this->f->finance, $this->f->manager, $this->f->sales] as $member) {
        writeOff($this, $member)->assertForbidden();
    }
    expect(DebtWriteOff::query()->count())->toBe(0)->and(closureKeys($this->order))->toBe(['money_owed']);

    // A member granted P-103 (with P-37 to see the amount) may write off.
    MemberGrant::query()->create(['membership_id' => Org::membershipOf($this->f->finance)->id, 'permission' => Permission::P103->value, 'effect' => 'allow', 'scope' => 'branches']);
    writeOff($this, $this->f->finance)->assertCreated();
});

it('requires a reason and the amount the screen showed; a changed amount writes nothing', function () {
    writeOff($this, $this->f->owner, extra: ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    writeOff($this, $this->f->owner, 50000)->assertUnprocessable()->assertJsonValidationErrors(['amount']);
    expect(DebtWriteOff::query()->count())->toBe(0)->and(DebtWriteOffEntry::query()->count())->toBe(0);
});

it('is refused while the order is still running (not a closure decision)', function () {
    $this->order->forceFill(['status' => 'in_progress'])->save();
    writeOff($this, $this->f->owner)->assertStatus(409);
    expect(DebtWriteOff::query()->count())->toBe(0);
});

it('is undone only by a reversal entry that negates it once; the debt is owed again', function () {
    writeOff($this, $this->f->owner)->assertCreated();
    $w = DebtWriteOff::query()->sole();
    expect(fn () => $w->forceFill(['amount_minor' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $w->delete())->toThrow(LogicException::class);

    $reverse = fn ($user) => $this->actingAs($user)->postJson(route('finance.write-offs.reverse', $w), ['reason' => 'دفع العميل لاحقًا', 'idempotency_key' => (string) Str::uuid()]);
    $reverse($this->f->finance)->assertForbidden();
    $reverse($this->f->owner)->assertOk()->assertJsonPath('write_off.kind', 'reversal');
    $reverse($this->f->owner)->assertStatus(409);

    expect(DebtWriteOff::query()->where('kind', 'reversal')->sole()->reverses_id)->toBe($w->id)
        ->and(DebtWriteOffs::writtenOff($this->order->id))->toBe(0)
        ->and((int) DebtWriteOffEntry::query()->sum('amount_minor'))->toBe(0)
        ->and(DebtWriteOffs::balanced($this->order->id))->toBe(true)
        ->and(array_sum(DebtWriteOffs::owedByDue($this->order)))->toBe(70000)
        ->and(closureKeys($this->order))->toBe(['money_owed'])
        ->and(DB::table('audit_events')->where('action', 'finance.debt_write_off_reversed')->count())->toBe(1);
});

it('shows in collections and reports: out of open dues and aging, counted as written off and never as collected', function () {
    $owner = $this->f->owner;
    expect(collect(OpenDues::of(Order::query()))->where('order.id', $this->order->id)->sum('remaining'))->toBe(70000);

    writeOff($this, $owner)->assertCreated();

    $report = CollectionReport::build($owner);
    expect(collect(OpenDues::of(Order::query()))->where('order.id', $this->order->id)->count())->toBe(0)
        ->and($report['month']['written_off_minor'])->toBe(70000)
        ->and($report['month']['collected_minor'])->toBe(30000);

    $this->actingAs($owner)->get(route('orders.finance', $this->order))->assertOk()
        ->assertInertia(fn ($p) => $p->where('finance.summary.written_off_minor', 70000)->where('finance.summary.remaining_minor', 0));
    $this->actingAs($owner)->get(route('orders.show', $this->order))->assertOk()
        ->assertInertia(fn ($p) => $p->where('closure.write_off.entries.0.kind', 'write_off')->where('closure.write_off.entries.0.can_reverse', true));
});

it('keeps the schedule fixed while a write-off stands, and a late payment after a reversal settles the debt', function () {
    writeOff($this, $this->f->owner)->assertCreated();
    $this->actingAs($this->f->owner)->putJson(route('orders.finance.schedule', $this->order), [
        'dues' => [['name' => ['ar' => 'كامل'], 'reference' => 'all', 'basis' => 'rest', 'event' => 'agreement']],
        'idempotency_key' => (string) Str::uuid(), 'lock_version' => finVersion($this->order),
    ])->assertUnprocessable()->assertJsonPath('errors.dues.0', __('finance.write_off.schedule_locked'));

    $w = DebtWriteOff::query()->sole();
    $this->actingAs($this->f->owner)->postJson(route('finance.write-offs.reverse', $w), ['reason' => 'خطأ في الشطب', 'idempotency_key' => (string) Str::uuid()])->assertOk();
    finPay($this->f->finance, $this->order, '700');
    expect(DebtWriteOffs::owedByDue($this->order))->toBe([])->and(closureKeys($this->order))->toBe([]);
});
