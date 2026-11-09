<?php

namespace App\Ai\Insights;

use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;

/**
 * Named date ranges in the facility's time zone (inclusive days). The model picks a name; the dates are computed
 * here, so it never has to do calendar arithmetic (and every date it sees has a source).
 */
final class InsightPeriods
{
    public const NAMES = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_quarter', 'this_year'];

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(AppointmentBooking::today());
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function range(string $name): array
    {
        $today = self::today();

        [$from, $to] = match ($name) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'last_7_days' => [$today->subDays(6), $today],
            'last_30_days' => [$today->subDays(29), $today],
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'this_quarter' => [$today->startOfQuarter(), $today],
            'this_year' => [$today->startOfYear(), $today],
            default => throw new InvalidToolCall('unknown period'),
        };

        return [$from->toDateString(), $to->toDateString()];
    }

    /**
     * @return array<string, array{from: string, to: string}>
     */
    public static function calendar(): array
    {
        $out = [];
        foreach (self::NAMES as $name) {
            [$from, $to] = self::range($name);
            $out[$name] = ['from' => $from, 'to' => $to];
        }

        return $out;
    }
}
