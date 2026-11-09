<?php

namespace App\Ai\Insights;

use App\Models\Branch;
use App\Models\User;
use App\Organization\OrgPresenter;
use App\Reports\Definitions\Report;
use App\Reports\MetricCatalogue;
use App\Reports\ReportRegistry;
use App\Reports\ReportScope;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The only tools the model may ask for (plan axis 6, ADR-0050): the existing reports, read-only, run server-side
 * with the ASKING user's scope (ReportRegistry::canOpen = P-60 + the report's own grant + license; ReportScope =
 * the user's branch scope, money narrowed by P-37). Nothing here writes, and the model never sees a report the user
 * cannot open, a branch outside the user's scope, or an amount they have no grant for (the report omits it).
 *
 * A call is `{tool, period, from?, to?, branch_id?, detail?}`; anything else, or any value outside the rules below,
 * is refused with InvalidToolCall. Numbers leave here in a form the facts check understands: money as
 * `*_halalas`, quantities as decimals.
 */
final class ReportTools
{
    public const MAX_CALLS = 4;

    public const MAX_DAYS = 366;

    public const MAX_ROWS = 25;

    public const MAX_POINTS = 31;

    private const CALL_KEYS = ['tool', 'period', 'from', 'to', 'branch_id', 'detail'];

    /**
     * @return array<string, Report> report key => report, only the ones this user may open
     */
    public function available(User $user): array
    {
        return ReportRegistry::availableTo($user);
    }

    /**
     * What the model is told it can call: tool name, and the metrics that report produces.
     *
     * @return list<array{tool: string, metrics: array<string, string>}>
     */
    public function catalogue(User $user): array
    {
        $out = [];
        foreach ($this->available($user) as $key => $report) {
            $metrics = [];
            foreach (MetricCatalogue::DEFINITIONS as $metric => $definition) {
                if (str_starts_with($metric, $report->key().'.') || ($report->key() === 'returns' && str_starts_with($metric, 'cancellations.'))) {
                    $metrics[self::metricName($metric, $definition['kind'])] = $definition['formula'];
                }
            }
            $out[] = ['tool' => $key, 'metrics' => $metrics];
        }

        return $out;
    }

    /**
     * Branches the user's scope covers: the only ids a call may name.
     *
     * @return list<array{id: int, name: string}>
     */
    public function branches(User $user): array
    {
        $ids = (new ReportScope($user, ...InsightPeriods::range('today'), tables: false))->visibleBranchIds();

        return array_values(Branch::query()->whereIn('id', $ids)->orderBy('id')->get()
            ->map(fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)])->all());
    }

    /**
     * Checks one call against the rules and returns it normalized. No report runs here.
     *
     * @param  array<mixed>  $call
     * @return array{tool: string, period: string, from: string, to: string, branch_id: int|null, detail: bool}
     *
     * @throws InvalidToolCall
     */
    public function validate(User $user, array $call): array
    {
        if (array_diff(array_keys($call), self::CALL_KEYS) !== []) {
            throw new InvalidToolCall('unknown parameter');
        }

        $tool = $call['tool'] ?? null;
        if (! is_string($tool) || ! array_key_exists($tool, $this->available($user))) {
            throw new InvalidToolCall('tool not available');
        }

        $period = $call['period'] ?? null;
        if (! is_string($period)) {
            throw new InvalidToolCall('period required');
        }
        if ($period === 'custom') {
            [$from, $to] = $this->customRange($call['from'] ?? null, $call['to'] ?? null);
        } elseif (in_array($period, InsightPeriods::NAMES, true)) {
            if (($call['from'] ?? null) !== null || ($call['to'] ?? null) !== null) {
                throw new InvalidToolCall('dates only with a custom period');
            }
            [$from, $to] = InsightPeriods::range($period);
        } else {
            throw new InvalidToolCall('unknown period');
        }

        $branch = $call['branch_id'] ?? null;
        if ($branch !== null) {
            if (! is_int($branch) || ! in_array($branch, array_column($this->branches($user), 'id'), true)) {
                throw new InvalidToolCall('branch not in scope');
            }
        }

        $detail = $call['detail'] ?? false;
        if (! is_bool($detail)) {
            throw new InvalidToolCall('detail must be boolean');
        }

        return ['tool' => $tool, 'period' => $period, 'from' => $from, 'to' => $to, 'branch_id' => $branch, 'detail' => $detail];
    }

    /**
     * Runs a validated call as the user. Read-only: a report only reads.
     *
     * @param  array<mixed>  $call
     * @return array{result: array<string, mixed>, names: list<string>} `names`: free text cells to hide from the provider
     *
     * @throws InvalidToolCall
     */
    public function run(User $user, array $call): array
    {
        $call = $this->validate($user, $call);
        $report = $this->available($user)[$call['tool']];

        $scope = new ReportScope($user, $call['from'], $call['to'], $call['branch_id'] === null ? [] : [$call['branch_id']], $call['detail']);
        $built = $report->build($scope)->toArray();

        $names = [];
        $result = [
            'tool' => $call['tool'],
            'range' => ['from' => $call['from'], 'to' => $call['to']],
            'branch_id' => $call['branch_id'],
            'metrics' => self::compactMetrics($built['metrics']),
        ];

        if ($call['detail']) {
            $result['charts'] = array_map(fn (array $chart) => [
                'key' => $chart['key'],
                'unit' => $chart['unit'],
                'points' => array_map(fn (array $p) => [
                    'label' => $p['label'],
                    ($chart['unit'] === 'money' ? 'value_halalas' : 'value') => self::scale($chart['unit'], $p['value']),
                ], array_slice($chart['points'], 0, self::MAX_POINTS)),
            ], $built['charts']);

            $columns = $built['table']['columns'];
            $rows = [];
            foreach (array_slice($built['table']['rows'], 0, self::MAX_ROWS) as $row) {
                $out = [];
                foreach ($columns as $column) {
                    $value = $row[$column['key']] ?? null;
                    if ($column['type'] === 'text' && is_string($value) && $value !== '' && $column['key'] !== 'branch') {
                        $names[] = $value;
                    }
                    $out[$column['type'] === 'money' ? $column['key'].'_halalas' : $column['key']] = is_numeric($value) ? self::scale($column['type'], $value) : $value;
                }
                $rows[] = $out;
            }
            $result['rows'] = $rows;
            $result['rows_truncated'] = $built['table']['truncated'] || count($built['table']['rows']) > self::MAX_ROWS;
        }

        return ['result' => $result, 'names' => array_values(array_unique($names))];
    }

    /**
     * Metrics as `name => value`: money named `*_halalas`, quantities (thousandths) as decimals. A metric the viewer
     * has no grant for is already absent from the report.
     *
     * @param  list<array{key: string, kind: string, value: int|float}>  $metrics
     * @return array<string, int|float>
     */
    public static function compactMetrics(array $metrics): array
    {
        $out = [];
        foreach ($metrics as $metric) {
            $out[self::metricName($metric['key'], $metric['kind'])] = self::scale($metric['kind'], $metric['value']);
        }

        return $out;
    }

    private static function metricName(string $key, string $kind): string
    {
        return match ($kind) {
            'money' => (string) preg_replace('/_minor$/', '_halalas', $key),
            'quantity' => (string) preg_replace('/_milli$/', '_qty', $key),
            default => $key,
        };
    }

    private static function scale(string $kind, int|float|string $value): int|float
    {
        $value = is_string($value) ? (str_contains($value, '.') ? (float) $value : (int) $value) : $value;

        return match ($kind) {
            'quantity', 'qty' => round($value / 1000, 3),
            'hours', 'percent' => round($value, 1),
            default => $value,
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function customRange(mixed $from, mixed $to): array
    {
        if (! is_string($from) || ! is_string($to)) {
            throw new InvalidToolCall('custom period needs both dates');
        }

        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $from);
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $to);
            if ($start === null || $end === null) {
                throw new InvalidToolCall('bad date');
            }
        } catch (Throwable) {
            throw new InvalidToolCall('bad date');
        }
        if ($start->toDateString() !== $from || $end->toDateString() !== $to) {
            throw new InvalidToolCall('bad date');
        }
        if ($start->gt($end) || $start->year < 2000 || $start->diffInDays($end) >= self::MAX_DAYS) {
            throw new InvalidToolCall('bad date range');
        }

        return [$from, $to];
    }
}
