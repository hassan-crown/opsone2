<?php

namespace App\Configuration;

use App\Models\ConfigRelease;
use App\Models\Finance\PaymentPlan;
use App\Models\MessageTemplate;
use App\Models\NumberFormat;
use App\Models\Order;
use App\Support\RequestCache;

/**
 * The read side of configuration releases (ADR-0040). The application reads the rules of a release, never the draft
 * tables:
 * - money and documents (payment plans, contract/quote clauses) come from the release the order pinned when it was
 *   created — `forOrder()`; an order without a pin (created before the first release) follows the current release;
 * - communication and operations (reminders, message texts, task SLA, numbering, purchase-order document) follow the
 *   current release — `current()` — because they act on new events, not on what the order agreed to.
 * Before the first release both are the built-in defaults.
 */
final class ConfigReleases
{
    public static function latest(): ?ConfigRelease
    {
        return app(RequestCache::class)->remember('config_release_latest', fn () => ConfigRelease::query()->orderByDesc('number')->first());
    }

    public static function currentId(): ?int
    {
        return self::latest()?->id;
    }

    /**
     * The rules in force now.
     *
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        return self::latest()->snapshot ?? ConfigSnapshot::defaults();
    }

    /**
     * The rules an order started on.
     *
     * @return array<string, mixed>
     */
    public static function forOrder(Order|int|null $order): array
    {
        $releaseId = $order instanceof Order ? $order->config_release_id
            : ($order === null ? null : app(RequestCache::class)->remember("order_release:{$order}", fn () => Order::query()->whereKey($order)->value('config_release_id')));
        if ($releaseId === null || $releaseId === self::currentId()) {
            return self::current();
        }

        return app(RequestCache::class)->remember("config_release:{$releaseId}", fn () => ConfigRelease::query()->whereKey($releaseId)->firstOrFail()->snapshot);
    }

    /**
     * A snapshot's payment plans as read-only models (ids are the draft rows', so an order can record its choice).
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<PaymentPlan>
     */
    public static function plans(array $snapshot): array
    {
        return array_values(array_map(fn (array $p) => (new PaymentPlan)->newFromBuilder([
            'id' => $p['id'], 'key' => $p['key'], 'name' => json_encode($p['name'], JSON_UNESCAPED_UNICODE), 'lines' => json_encode($p['lines'], JSON_UNESCAPED_UNICODE),
            'is_default' => $p['is_default'], 'archived_at' => null, 'lock_version' => 1,
        ]), $snapshot['payment_plans'] ?? []));
    }

    /**
     * @return array<string, MessageTemplate> keyed «event|channel|locale»
     */
    public static function messages(): array
    {
        return app(RequestCache::class)->remember('config_messages', function () {
            $out = [];
            foreach (self::current()['messages'] ?? [] as $m) {
                $out["{$m['event']}|{$m['channel']}|{$m['locale']}"] = (new MessageTemplate)->newFromBuilder($m);
            }

            return $out;
        });
    }

    /**
     * @return array<string, NumberFormat> keyed by sequence
     */
    public static function numberFormats(): array
    {
        return app(RequestCache::class)->remember('config_number_formats', function () {
            $out = [];
            foreach (self::current()['numbering'] ?? [] as $f) {
                $out[$f['sequence']] = (new NumberFormat)->newFromBuilder($f);
            }

            return $out;
        });
    }

    public static function forget(): void
    {
        app(RequestCache::class)->flush();
    }
}
