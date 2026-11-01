<?php

use App\Models\Customer;
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

    // Gapless numbering: receipts follow each other without holes.
    $numbers = DB::table('receipts')->orderBy('id')->pluck('number')->map(fn ($n) => (int) substr((string) $n, -6))->all();
    expect($numbers)->toBe(range(1, count($numbers)));
});

it('refuses to run outside the local and testing environments', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(ShowcaseSeeder::class)->run())->toThrow(RuntimeException::class);
});

it('is not part of the default seeding', function () {
    expect(file_get_contents(database_path('seeders/DatabaseSeeder.php')))->not->toContain('Showcase');
});
