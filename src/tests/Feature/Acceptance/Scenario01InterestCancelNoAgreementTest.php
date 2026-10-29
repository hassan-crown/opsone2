<?php

/**
 * Mandatory integration scenario 1 «تحويل اهتمام ثم إلغاء بلا اتفاق» (SRS «اختبارات ترابط ملزمة»; SRS-COM-13, SRS-COM-14,
 * P03), driven only over HTTP: sales records an interest and converts it; exactly one order results and the interest
 * stays converted (a historical reference, never re-converted silently). The owner then records the customer's phone
 * request and cancels at once (P-18): with no agreement, work, money, reservation or purchase the cancellation closes
 * directly — no zero-value quote, no payment, no stock movement, no settlement follow-up.
 */

use App\Enums\InterestStatus;
use App\Enums\TaskStatus;
use App\Models\Customer;
use App\Models\Interest;
use App\Models\Order;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/AcceptanceStockHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = ordersFixture();
    $this->owner = $this->f->org->owner;
    $this->year = now()->year;

    // Sales records the interest from a WhatsApp enquiry and converts it (P-09 / P-15).
    $this->actingAs($this->f->sales)->post('/interests', [
        'name' => 'ريم العتيبي', 'mobile' => '0555551234', 'need' => 'مطبخ كامل مع جزيرة', 'channel' => 'whatsapp', 'branch_id' => $this->f->org->a->id,
    ])->assertRedirect();
    $this->interest = Interest::query()->sole();
    $this->customers = Customer::query()->count();
    $this->actingAs($this->f->sales)->post("/interests/{$this->interest->id}/convert")->assertRedirect();
    $this->customer = Customer::query()->where('mobile', '+966555551234')->sole();
});

it('creates exactly one order on conversion and keeps the interest converted', function () {
    expect($this->interest->refresh()->status)->toBe(InterestStatus::Converted)
        ->and($this->interest->converted_customer_id)->toBe($this->customer->id)
        // SRS-COM-13: the conversion yields one made-to-order order for the customer.
        ->and(Order::query()->count())->toBe(1)
        ->and(Order::query()->sole()->customer_id)->toBe($this->customer->id)
        ->and($this->interest->converted_order_id)->toBe(Order::query()->sole()->id)
        ->and(Order::query()->sole()->status)->toBe('in_progress')
        ->and(Order::query()->sole()->total_minor)->toBeNull();
});

it('cancels the order of a converted interest with no agreement: closed directly, no quote, money or stock', function () {
    $f = $this->f;
    expect(Customer::query()->count())->toBe($this->customers + 1);

    // The order the conversion opened for the customer (custom: in progress, no price yet).
    $order = Order::query()->sole();
    expect($order->status)->toBe('in_progress')->and($order->customer_id)->toBe($this->customer->id);

    // The customer phones to cancel: the owner reviews the impact and records + accepts in one step (SRS-COM-14).
    $review = accSPreview($this->owner, $order);
    expect($review['impact']['order']['status_after'])->toBe('cancelled')
        ->and($review['impact']['settlement']['stock'])->toBe([])
        ->and($review['impact']['settlement']['purchases'])->toBe([])
        ->and($review['impact']['settlement']['money']['collected_minor'] ?? 0)->toBe(0)
        ->and($review['impact']['settlement']['money']['refund_minor'] ?? 0)->toBe(0);

    accSCancel($this->owner, $order, ['scope' => 'full'], $review['hash'], $review['lock_version'])->assertCreated()
        ->assertJsonPath('cancellation.number', "CN-{$this->year}-000001")
        ->assertJsonPath('cancellation.status', 'accepted')
        ->assertJsonPath('order.status', 'cancelled')
        ->assertJsonPath('settlement', null);

    expect($order->refresh()->status)->toBe('cancelled')
        // Closed directly: no zero-value quote or agreement, no money, no stock, no follow-up.
        ->and(DB::table('agreement_documents')->count())->toBe(0)
        ->and(DB::table('agreements')->count())->toBe(0)
        ->and(DB::table('payments')->count())->toBe(0)
        ->and(DB::table('receipts')->count())->toBe(0)
        ->and(DB::table('refunds')->count())->toBe(0)
        ->and(DB::table('stock_movements')->count())->toBe(0)
        ->and(DB::table('stock_reservations')->count())->toBe(0)
        ->and(cnFollowUp($order))->toBeNull()
        ->and(DB::table('settlement_items')->count())->toBe(0)
        ->and(Task::query()->where('order_id', $order->id)->whereIn('status', TaskStatus::open())->count())->toBe(0)
        ->and(DB::table('order_cancellations')->where('order_id', $order->id)->value('status'))->toBe('accepted')
        ->and(accSAudits('cancellation.accepted'))->toBe(1)
        ->and(DB::table('state_transitions')->where('machine', 'M-34')->where('transition', 'T03')->count())->toBe(1);

    // The interest stays converted to its historical customer/order; it is not re-converted silently.
    expect($this->interest->refresh()->status)->toBe(InterestStatus::Converted)
        ->and($this->interest->converted_customer_id)->toBe($this->customer->id);
    $this->actingAs($f->sales)->post("/interests/{$this->interest->id}/convert")->assertRedirect("/interests/{$this->interest->id}");
    expect($this->interest->refresh()->status)->toBe(InterestStatus::Converted)
        ->and(Order::query()->count())->toBe(1)
        ->and(Customer::query()->count())->toBe($this->customers + 1)
        ->and(accSAudits('interest.converted'))->toBe(1);
});
