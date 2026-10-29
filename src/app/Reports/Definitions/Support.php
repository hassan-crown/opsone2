<?php

namespace App\Reports\Definitions;

use App\Models\Branch;
use App\Models\Order;
use App\Organization\OrgPresenter;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Small helpers shared by the report definitions.
 */
final class Support
{
    /** @var array<int, string>|null */
    private static ?array $branchNames = null;

    public static function flush(): void
    {
        self::$branchNames = null;
    }

    public static function branchName(?int $id): string
    {
        self::$branchNames ??= Branch::query()->get()->mapWithKeys(fn (Branch $b) => [$b->id => OrgPresenter::name($b)])->all();

        return $id === null ? '' : (self::$branchNames[$id] ?? '');
    }

    public static function v(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : ($value === null ? null : (string) $value);
    }

    public static function morph(): string
    {
        return (new Order)->getMorphClass();
    }

    /**
     * @param  Builder<Order>  $orders
     */
    public static function orderIds(Builder $orders): QueryBuilder
    {
        return (clone $orders)->select('orders.id')->toBase();
    }

    public static function at(CarbonImmutable $t): string
    {
        return $t->toDateTimeString();
    }

    public static function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $value, (string) config('app.timezone'))
            ->setTimezone(AppointmentBooking::timezone())->toDateString();
    }

    public static function datetime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $value, (string) config('app.timezone'))
            ->setTimezone(AppointmentBooking::timezone())->format('Y-m-d H:i');
    }

    /**
     * @param  array<string, int|float>  $counts
     * @return list<array{label: string, value: int|float, enum: true}>
     */
    public static function enumPoints(array $counts): array
    {
        $out = [];
        foreach ($counts as $k => $v) {
            $out[] = ['label' => (string) $k, 'value' => $v, 'enum' => true];
        }

        return $out;
    }

    /** Integer round half up of numerator / denominator. */
    public static function divRound(int $numerator, int $denominator): int
    {
        return $denominator === 0 ? 0 : intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
