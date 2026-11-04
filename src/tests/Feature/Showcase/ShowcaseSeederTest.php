<?php

use App\EInvoicing\Zatca\InvoiceHasher;
use App\EInvoicing\Zatca\InvoiceSigner;
use App\Models\Customer;
use App\Models\EInvoicing\EInvoice;
use App\Models\Flow;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Database\Seeders\ShowcaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LicenseFactory;

/*
 * The showcase dataset (docs/showcase.md) is built through the real domain actions while the clock is walked through
 * six months. A small slice of it on SQLite must leave every append-only invariant intact: balanced money journals and
 * stock balances equal to their movements.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake((string) config('attachments.disk'));
    // The license must already be valid on the first historical day.
    LicenseFactory::install([
        'plan' => 'scale', 'limits' => ['seats' => 100, 'branches' => 10, 'storage_mb' => 25600],
        'starts_at' => '2026-04-01T00:00:00Z', 'ends_at' => '2028-12-31T00:00:00Z', 'issued_at' => '2026-04-01T00:00:00Z',
    ]);
});

afterEach(function () {
    Carbon\Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('seeds a coherent history and keeps the ledger and stock invariants', function () {
    $seeder = app(ShowcaseSeeder::class)->setContainer(app());
    $seeder->scale = 0.08;
    $seeder->images = false;
    $seeder->run();

    // The dated exceptions (changes, cancellations, corrections, tickets) need the full-size dataset to find their order.
    expect(array_values(array_filter($seeder->failures, fn (string $f) => ! str_starts_with($f, 'special-') && ! str_starts_with($f, 'ticket-'))))->toBe([]);
    expect(Customer::query()->count())->toBeGreaterThan(5)
        ->and(Order::query()->where('kind', 'custom')->count())->toBeGreaterThan(3)
        ->and(Order::query()->where('kind', 'ready')->count())->toBeGreaterThan(3)
        ->and(DB::table('payments')->count())->toBeGreaterThan(5)
        ->and(DB::table('site_page_locales')->whereNotNull('published_version_id')->count())->toBeGreaterThanOrEqual(10);

    // ADR-0041: every channel is represented, and the field rep sold, visited and earned through the dispatched leads.
    $rep = (int) DB::table('users')->where('email', 'sami.khaled@opsone-demo.test')->value('id');
    expect(Order::query()->distinct()->pluck('sales_channel')->map(fn ($c) => $c instanceof BackedEnum ? $c->value : $c)->sort()->values()->all())
        ->toBe(['field_sales', 'online_store', 'showroom'])
        ->and(Order::query()->where('seller_id', $rep)->count())->toBe(6)
        ->and(DB::table('field_assignments')->where('rep_id', $rep)->count())->toBe(9)
        ->and(DB::table('sales_visits')->whereNotNull('finished_at')->count())->toBe(5)
        ->and(DB::table('order_sales')->where('user_id', $rep)->where('owner_source', 'seller')->count())->toBe(3);

    // Money: every journal sums to what its kind allows; a reversal negates the journal it names.
    foreach (DB::table('money_journals')->get() as $j) {
        $sum = (int) DB::table('money_entries')->where('journal_id', $j->id)->sum('amount_minor');
        match ($j->kind) {
            'collection' => expect($sum)->toBe((int) DB::table('payments')->where('id', $j->payment_id)->value('amount_minor')),
            'refund' => expect($sum)->toBeLessThan(0),
            'reversal' => expect($sum)->toBe(-(int) DB::table('money_entries')->where('journal_id', $j->reverses_journal_id)->sum('amount_minor')),
            default => expect($sum)->toBe(0),
        };
    }
    // No due is allocated above its amount.
    foreach (DB::table('order_dues')->get() as $due) {
        $allocated = (int) DB::table('money_entries')->where('order_due_id', $due->id)->sum('amount_minor');
        expect($allocated)->toBeLessThanOrEqual((int) $due->amount_minor);
    }

    // Stock: the projected balance equals the sum of the append-only journal and never goes negative.
    foreach (DB::table('stock_balances')->get() as $b) {
        $movements = DB::table('stock_movements')->where('location_id', $b->location_id)->where('stock_item_id', $b->stock_item_id);
        expect((int) $b->on_hand_milli)->toBe((int) (clone $movements)->sum('on_hand_delta'))
            ->and((int) $b->reserved_milli)->toBe((int) (clone $movements)->sum('reserved_delta'))
            ->and((int) $b->on_hand_milli)->toBeGreaterThanOrEqual((int) $b->reserved_milli)
            ->and((int) $b->reserved_milli)->toBeGreaterThanOrEqual(0);
    }

    // Customer messages: a WhatsApp history with outcomes, SMS for the opted-out, nothing left queued.
    $statuses = DB::table('customer_messages')->where('channel', 'whatsapp')->distinct()->pluck('status')->all();
    expect(DB::table('customer_messages')->count())->toBeGreaterThan(5)
        ->and($statuses)->toContain('read')
        ->and(DB::table('customer_messages')->where('status', 'queued')->count())->toBe(0)
        ->and(DB::table('customer_messages as m')->join('customers as c', 'c.id', '=', 'm.customer_id')->whereNotNull('c.whatsapp_opt_out_at')->where('m.channel', 'whatsapp')->count())->toBe(0);

    // ZATCA e-invoicing (ADR-0033): invoices on first payments, notes, phase 1 then a signed chain after onboarding.
    $einvoices = EInvoice::query()->orderBy('id')->get();
    expect($einvoices->where('kind', 'invoice')->count())->toBeGreaterThan(3)
        ->and($einvoices->where('kind', 'credit_note')->count())->toBeGreaterThanOrEqual(2)
        ->and($einvoices->where('kind', 'debit_note')->count())->toBe(1)
        ->and($einvoices->where('submission_status', 'not_required')->count())->toBeGreaterThan(0)
        ->and($einvoices->where('signed', true)->count())->toBeGreaterThan(0)
        ->and($einvoices->whereIn('submission_status', ['pending', 'failed', 'rejected'])->count())->toBe(0)
        ->and(DB::table('zatca_devices')->where('status', 'active')->count())->toBe(1);
    foreach ($einvoices->groupBy(fn ($i) => (string) $i->device_id) as $chain) {
        $previous = InvoiceHasher::FIRST_PIH;
        foreach ($chain->sortBy('icv')->values() as $n => $invoice) {
            expect($invoice->icv)->toBe($n + 1)->and($invoice->previous_hash)->toBe($previous);
            $previous = $invoice->hash;
        }
    }
    foreach ($einvoices->where('signed', true) as $invoice) {
        expect(InvoiceSigner::verify($invoice->xml)['valid'])->toBeTrue();
    }

    // ADR-0038: the retail flow with external workshops — workshop purchase orders confirmed (sent) and received,
    // each verifying its order's workshop actions from the purchase order only.
    expect(Flow::current()->template_key)->toBe('retail_outsourced')
        ->and(DB::table('suppliers')->where('is_workshop', true)->count())->toBe(3);
    $workshopPos = DB::table('purchase_orders')->whereIn('supplier_id', DB::table('suppliers')->where('is_workshop', true)->select('id'))->get();
    expect($workshopPos->where('status', '!=', 'draft')->count())->toBeGreaterThan(0);
    foreach (DB::table('order_actions')->where('type', 'workshop_order')->where('status', 'verified')->get() as $action) {
        expect(json_decode((string) $action->source_reference, true)['type'] ?? null)->toBeIn(['purchase_order', 'purchase_receipt']);
    }

    // Payment plans (ADR-0037): two plans, every agreed order copied one of them, and 40/40/20 orders have a second
    // payment due when installation starts.
    expect(DB::table('payment_plans')->orderBy('key')->pluck('key')->all())->toBe(['fifty_fifty', 'forty_forty_twenty'])
        ->and(DB::table('order_finances')->whereNotNull('agreement_id')->whereNotIn('payment_plan_key', ['fifty_fifty', 'forty_forty_twenty'])->count())->toBe(0)
        ->and(DB::table('order_dues')->where('reference', 'second')->where('stage_key', '!=', 'install')->count())->toBe(0);

    // Gapless numbering: receipts follow each other without holes.
    $numbers = DB::table('receipts')->orderBy('id')->pluck('number')->map(fn ($n) => (int) substr((string) $n, -6))->all();
    expect($numbers)->toBe(range(1, count($numbers)));

    // E-signatures (ADR-0029): signed quotes/contracts with their signed PDF, and documents awaiting signature with a live link.
    expect(DB::table('document_signatures')->whereNotNull('signed_pdf_id')->count())->toBeGreaterThanOrEqual(3)
        ->and(DB::table('document_sign_links')->whereNull('used_at')->whereNull('revoked_at')->count())->toBeGreaterThanOrEqual(2);

    // Installation field app (ADR-0036): finished visits each with before/after photos and a signature.
    $finished = DB::table('installation_visits')->whereNotNull('finished_at')->get();
    expect($finished->count())->toBeGreaterThanOrEqual(1);
    foreach ($finished as $visit) {
        expect($visit->signed_at)->not->toBeNull()
            ->and(DB::table('installation_photos')->where('installation_visit_id', $visit->id)->where('phase', 'before')->exists())->toBeTrue()
            ->and(DB::table('installation_photos')->where('installation_visit_id', $visit->id)->where('phase', 'after')->exists())->toBeTrue()
            ->and(DB::table('appointments')->where('id', $visit->appointment_id)->value('status'))->toBe('done');
    }
});

it('refuses to run outside the local and testing environments', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(ShowcaseSeeder::class)->run())->toThrow(RuntimeException::class);
});

it('is not part of the default seeding', function () {
    expect(file_get_contents(database_path('seeders/DatabaseSeeder.php')))->not->toContain('Showcase');
});
