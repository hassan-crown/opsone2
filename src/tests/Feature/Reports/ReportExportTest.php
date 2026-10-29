<?php

/**
 * Report exports (SHR-12, NFR-16, P-62): generated on the queue from the same build as the screen, private file,
 * short-lived signed link, notification, audit, formula-injection-safe cells.
 */

use App\Access\Access;
use App\Jobs\GenerateReportExport;
use App\Models\AuditEvent;
use App\Models\ReportExport;
use App\Models\UserNotification;
use App\Reports\Export\CsvWriter;
use App\Reports\Export\ReportExports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ReportsTestHelpers.php';
require_once __DIR__.'/../Procurement/ProcurementTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
    finAgree($this->f, '18450');
    repOrderInB($this->f, '1000');
});

/**
 * @return list<list<string>>
 */
function repCsv(ReportExport $export): array
{
    $body = Storage::disk('attachments')->get((string) $export->path);
    expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue();
    $lines = array_values(array_filter(explode("\r\n", substr($body, 3)), fn ($l) => $l !== ''));

    return array_map(fn (string $l) => str_getcsv($l, ',', '"', ''), $lines);
}

function repRequest(object $f, $user, string $key = 'sales', array $extra = []): ReportExport
{
    test()->actingAs($user)->postJson('/reports/exports', ['report' => $key, ...$extra])->assertCreated();

    return ReportExport::query()->latest('id')->firstOrFail();
}

it('queues the export and builds exactly the rows the screen shows, with money as decimals', function () {
    Queue::fake();
    $export = repRequest($this->f, $this->f->owner);
    Queue::assertPushed(GenerateReportExport::class, fn ($job) => $job->exportId === $export->id);
    expect($export->status)->toBe('queued');

    app(ReportExports::class)->generate($export);
    $export->refresh();
    expect($export->status)->toBe('ready')->and($export->sha256)->toBe(hash('sha256', Storage::disk('attachments')->get((string) $export->path)));

    $screen = repReport($this->f->owner, 'sales');
    $csv = repCsv($export);
    $header = array_shift($csv);
    expect($header)->toBe(array_map(fn ($c) => __('reports.col.'.$c['key']), $screen['table']['columns']))
        ->and($csv)->toHaveCount(count($screen['table']['rows']))
        ->and(array_column($csv, 0))->toBe(array_column($screen['table']['rows'], 'number'));
    $grossIndex = array_search('agreed_gross_minor', array_column($screen['table']['columns'], 'key'), true);
    expect(array_column($csv, $grossIndex))->toBe(['18450.00', '1000.00']);
});

it('runs on the queue and notifies the requester when ready, auditing request and generation', function () {
    $export = repRequest($this->f, $this->f->finance);
    $export->refresh();
    expect($export->status)->toBe('ready')
        ->and(UserNotification::query()->where('user_id', $this->f->finance->id)->where('type', 'report.export_ready')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'report_export.requested')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'report_export.generated')->count())->toBe(1);
});

it('applies the same scope and grants as the screen: a branch manager exports only their branch, without P-37 no money', function () {
    $mgr = repRequest($this->f, $this->f->manager);
    $csv = repCsv($mgr->refresh());
    expect($csv)->toHaveCount(2)->and($csv[1][0])->toBe($this->f->order->number);

    $blind = $this->f->org->member('manager', [$this->f->org->a]);
    procDeny($blind, 'P-37');
    Access::flush();
    $export = repRequest($this->f, $blind);
    $rows = repCsv($export->refresh());
    expect($rows[0])->not->toContain(__('reports.col.agreed_gross_minor'))->and(implode(',', $rows[1]))->not->toContain('18450');
});

it('refuses the export to people without P-62 or without access to that report', function () {
    $this->actingAs($this->f->supervisor)->postJson('/reports/exports', ['report' => 'sales'])->assertForbidden();
    $this->actingAs($this->f->executor)->postJson('/reports/exports', ['report' => 'sales'])->assertForbidden();
    $this->actingAs($this->f->finance)->postJson('/reports/exports', ['report' => 'compare'])->assertForbidden();
    $this->actingAs($this->f->finance)->postJson('/reports/exports', ['report' => 'nope'])->assertForbidden();
    expect(ReportExport::query()->count())->toBe(0);
});

it('re-evaluates the grants when the file is built: access lost after the request means no file', function () {
    Queue::fake();
    $user = $this->f->org->member('manager', [$this->f->org->a]);
    $export = repRequest($this->f, $user);
    procDeny($user, 'P-62');
    Access::flush();

    app(ReportExports::class)->generate($export);
    $export->refresh();
    expect($export->status)->toBe('failed')->and($export->error)->toBe('access')->and($export->path)->toBeNull();
});

it('neutralizes formula-looking text in cells (CSV injection) and leaves numbers and normal text alone', function () {
    foreach (['=1+1', '+SUM(A1)', '-2+3', '@cmd', "\t=x", "\r=x", '  =x', '=HYPERLINK("http://x","y")'] as $bad) {
        expect(CsvWriter::neutralize($bad))->toStartWith("'");
    }
    foreach (['مطبخ', 'Order 5', 'a=b', '1-2', ''] as $ok) {
        expect(CsvWriter::neutralize($ok))->toBe($ok);
    }
    expect(CsvWriter::render(['"x"'], [[['type' => 'text', 'value' => '=2+2'], ['type' => 'number', 'value' => '-12.50']]]))
        ->toContain("\"'=2+2\",-12.50")->toContain('"""x"""');

    // End to end: a customer named like a formula reaches the collection export neutralized.
    $this->f->inA->forceFill(['name' => '=cmd|\' /C calc\'!A0'])->saveQuietly();
    repDueOn($this->f->order, 1, 5);
    $export = repRequest($this->f, $this->f->owner, 'collection');
    $body = (string) Storage::disk('attachments')->get((string) $export->refresh()->path);
    expect($body)->toContain("\"'=cmd|")->and($body)->not->toContain(',"=cmd')->and($body)->not->toContain("\r\n\"=cmd");
});

it('downloads through a short-lived signed link bound to the requester, and the link expires', function () {
    $export = repRequest($this->f, $this->f->owner)->refresh();
    $url = $this->actingAs($this->f->owner)->postJson("/reports/exports/{$export->uuid}/link")->assertOk()->json('url');

    $response = $this->actingAs($this->f->owner)->get($url)->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv')->and($response->headers->get('cache-control'))->toContain('no-store');
    expect(AuditEvent::query()->where('action', 'report_export.downloaded')->count())->toBe(1);

    // Another user with the same link is refused, and cannot even ask for a link on someone else's export.
    $this->actingAs($this->f->finance)->get($url)->assertForbidden();
    $this->actingAs($this->f->finance)->postJson("/reports/exports/{$export->uuid}/link")->assertNotFound();
    // A tampered signature is refused.
    $this->actingAs($this->f->owner)->get($url.'x')->assertForbidden();

    $this->travel(6)->minutes();
    $this->actingAs($this->f->owner)->get($url)->assertForbidden();
});

it('stops serving after the retention window and the purge removes the file', function () {
    $export = repRequest($this->f, $this->f->owner)->refresh();
    $path = (string) $export->path;
    expect(Storage::disk('attachments')->exists($path))->toBeTrue();

    $this->travel(25)->hours();
    $this->actingAs($this->f->owner)->postJson("/reports/exports/{$export->uuid}/link")->assertStatus(410);

    expect(app(ReportExports::class)->purgeExpired())->toBe(1)
        ->and(Storage::disk('attachments')->exists($path))->toBeFalse()
        ->and($export->refresh()->purged_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'report_export.purged')->count())->toBe(1);
});

it('lists only the requester\'s own exports', function () {
    repRequest($this->f, $this->f->owner);
    repRequest($this->f, $this->f->finance);

    $mine = $this->actingAs($this->f->finance)->getJson('/reports/exports')->assertOk()->json('exports');
    expect($mine)->toHaveCount(1)->and($mine[0])->not->toHaveKey('path');
});

it('exports the branch comparison with the same rows the comparison screen shows', function () {
    $export = repRequest($this->f, $this->f->owner, 'compare')->refresh();
    $screen = $this->actingAs($this->f->owner)->getJson('/reports/compare')->json('report.table.rows');
    $csv = repCsv($export);
    array_shift($csv);
    expect($csv)->toHaveCount(count($screen))->and(array_column($csv, 0))->toBe(array_column($screen, 'branch'));
});
