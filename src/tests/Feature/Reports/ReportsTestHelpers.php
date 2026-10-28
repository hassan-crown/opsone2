<?php

use App\Access\Authorizer;
use App\Access\Contracts\LicenseCapabilities;
use App\Models\Finance\OrderDue;
use App\Models\Order;
use App\Models\User;
use App\Reports\ReportRegistry;
use App\Reports\ReportScope;
use App\Workflow\AppointmentBooking;
use Illuminate\Support\Facades\Facade;
use Tests\Support\LicenseFactory;

require_once __DIR__.'/../Settlement/SettlementTestHelpers.php';
require_once __DIR__.'/../Cancellation/CancellationTestHelpers.php';

/**
 * One report as the screen's JSON gets it.
 *
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function repReport(User $viewer, string $key, array $query = [], int $status = 200): array
{
    if ($key === 'collection') {
        // The collection report keeps the P6b screen at /reports/collection; the metrics layer is what it reads.
        $scope = new ReportScope($viewer, $query['from'] ?? ReportScope::defaultFrom(), $query['to'] ?? AppointmentBooking::today(), $query['branch_ids'] ?? []);

        return ReportRegistry::find('collection')->build($scope)->toArray();
    }
    $response = test()->actingAs($viewer)->getJson("/reports/{$key}".($query === [] ? '' : '?'.http_build_query($query)));
    $response->assertStatus($status);

    return $status === 200 ? (array) $response->json('report') : [];
}

/**
 * @param  array<string, mixed>  $report
 * @return array<string, int|float>
 */
function repMetrics(array $report): array
{
    $out = [];
    foreach ($report['metrics'] as $m) {
        $out[$m['key']] = $m['value'];
    }

    return $out;
}

/**
 * Every `*_minor` key anywhere in a payload (amounts must be absent, never zero, for a viewer without the grant).
 *
 * @param  array<array-key, mixed>  $payload
 * @return list<string>
 */
function repMinorKeys(array $payload): array
{
    $found = [];
    array_walk_recursive($payload, function ($v, $k) use (&$found) {
        if (is_string($k) && str_ends_with($k, '_minor')) {
            $found[] = $k;
        }
        if ($k === 'key' && is_string($v) && str_ends_with($v, '_minor')) {
            $found[] = $v;
        }
    });

    return $found;
}

/**
 * A second custom order, in branch B (owner opens it), with an approved agreement of one line at `$price`.
 */
function repOrderInB(object $f, string $price): Order
{
    $order = openCustomOrder($f->owner, $f->inB);
    $quote = agrSend($f->owner, agrQuote($f->owner, $order, agrQuoteData(lines: [finLine('ستائر', $price)])));
    agrApprove($f->owner, $quote);

    return $order->refresh();
}

function repDueOn(Order $order, int $position, int $daysAgo): void
{
    OrderDue::query()->where('order_id', $order->id)->where('position', $position)
        ->update(['due_event' => 'date', 'due_date' => now()->subDays($daysAgo)->toDateString()]);
}

/**
 * Swaps the installed license for one with exactly these capabilities, for the Gate and the registry alike.
 *
 * @param  list<string>  $capabilities
 */
function repLicense(array $capabilities): void
{
    LicenseFactory::install(['capabilities' => $capabilities]);
    Facade::clearResolvedInstances();
    app()->forgetInstance(Authorizer::class);
    app()->forgetInstance(LicenseCapabilities::class);
}
