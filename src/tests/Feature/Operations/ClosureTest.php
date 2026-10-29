<?php

use App\Models\AuditEvent;
use App\Models\FacilityExport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LicenseFactory;

require_once __DIR__.'/../Inventory/InventoryTestHelpers.php';

/*
| Facility closure (Q-53 a, FIN-12, NFR-16, ADR-0028): readiness from the sources, a full export built on the queue,
| downloaded through a short signed link that re-checks the owner, every step audited, nothing deleted.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('attachments');
    $this->s = stockFixture();
    $this->org = $this->s->f->org;
});

function exportZip(string $content): ZipArchive
{
    $path = tempnam(sys_get_temp_dir(), 'export');
    file_put_contents($path, $content);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    return $zip;
}

it('shows closure readiness from the source records to the owner only', function () {
    $this->actingAs($this->org->owner)->get('/settings/closure')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('account/closure')
            ->where('ready', true)
            ->where('items.0.key', 'open_orders')
            ->where('items.0.count', 0));

    readyOrder($this->s);

    $this->get('/settings/closure')->assertInertia(fn (Assert $page) => $page
        ->where('ready', false)
        ->where('items.0.count', 1));

    $manager = $this->org->member('manager');
    $this->actingAs($manager)->get('/settings/closure')->assertForbidden();
    $this->post('/settings/closure/exports')->assertForbidden();

    $this->actingAs($this->org->owner)->get('/settings')->assertInertia(fn (Assert $page) => $page->where('can.closure', true));
    $this->actingAs($manager)->get('/settings')->assertInertia(fn (Assert $page) => $page->where('can.closure', false));
});

it('builds a full export on the queue and serves it once through a signed link, without secrets', function () {
    Storage::disk('attachments')->put('orders/1/contract.pdf', '%PDF contract');
    readyOrder($this->s);

    $this->actingAs($this->org->owner)->post('/settings/closure/exports')->assertRedirect();

    $export = FacilityExport::query()->sole();
    expect($export->status)->toBe(FacilityExport::READY)
        ->and($export->summary['files'])->toBe(1)
        ->and($export->expires_at?->isFuture())->toBeTrue();

    $url = null;
    $this->get('/settings/closure')->assertInertia(function (Assert $page) use (&$url) {
        $page->where('exports.0.status', 'ready')->where('busy', false);
        $url = $page->toArray()['props']['exports'][0]['download_url'];
    });
    expect($url)->toContain('signature=');

    $zip = exportZip($this->get($url)->assertOk()->streamedContent());
    $users = (string) $zip->getFromName('organization/users.csv');

    expect($zip->getFromName('README.txt'))->toContain('organization/users.csv')
        ->and($zip->getFromName('orders/orders.csv'))->toContain('ORD-')
        ->and($zip->getFromName('files/attachments/orders/1/contract.pdf'))->toBe('%PDF contract')
        ->and($users)->toContain('email')->not->toContain('password')->not->toContain('two_factor_secret')
        ->and($zip->locateName('other/sessions.csv'))->toBeFalse()
        ->and($zip->locateName('other/integration_settings.csv'))->toBeFalse()
        ->and(collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->filter(fn ($n) => str_contains((string) $n, 'license_states')))->toBeEmpty();

    expect(AuditEvent::query()->where('action', 'ops.export_requested')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'ops.export_downloaded')->count())->toBe(1);

    // The link is signed, re-checks the owner and dies with the export.
    $this->get(route('closure.exports.download', $export))->assertForbidden();
    $this->actingAs($this->org->member('manager'))->get($url)->assertForbidden();
    $this->travel(25)->hours();
    $this->artisan('ops:exports-prune')->assertSuccessful();
    expect($export->refresh()->status)->toBe(FacilityExport::EXPIRED);
    Storage::disk('local')->assertMissing('exports/'.$export->uuid.'.zip');
    $this->actingAs($this->org->owner)->get(URL::temporarySignedRoute('closure.exports.download', now()->addMinutes(5), ['export' => $export->uuid]))->assertStatus(410);
});

it('queues one export at a time', function () {
    FacilityExport::query()->create(['uuid' => (string) Str::uuid(), 'requested_by' => $this->org->owner->id, 'status' => FacilityExport::RUNNING]);

    $this->actingAs($this->org->owner)->post('/settings/closure/exports')->assertRedirect();

    expect(FacilityExport::query()->count())->toBe(1);
});

it('keeps readiness and export open in the read-only phase', function () {
    $now = CarbonImmutable::now('UTC');
    LicenseFactory::place([
        'starts_at' => $now->subDays(425)->toIso8601ZuluString(),
        'ends_at' => $now->subDays(60)->toIso8601ZuluString(),
        'issued_at' => $now->toIso8601ZuluString(),
    ]);

    $this->actingAs($this->org->owner)->get('/settings/closure')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('subscription.status', 'expired'));
    $this->post('/settings/closure/exports')->assertRedirect();

    expect(FacilityExport::query()->sole()->status)->toBe(FacilityExport::READY);
});
