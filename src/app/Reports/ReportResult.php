<?php

namespace App\Reports;

/**
 * What a report returns: summary metrics, charts and one flat table. The table IS the export: the screen and the CSV
 * are rendered from the same rows. Money keys are omitted — never zeroed — when the viewer lacks the grant.
 *
 * Column types: text | int | money | qty | date | datetime | hours | enum | percent.
 */
final class ReportResult
{
    public const ROW_CAP = 1000;

    /** @var list<array{key: string, kind: string, value: int|float}> */
    private array $metrics = [];

    /** @var list<array{key: string, type: string, unit: string, points: list<array{label: string, value: int|float}>}> */
    private array $charts = [];

    /** @var list<array{key: string, type: string}> */
    private array $columns = [];

    /** @var list<array<string, scalar|null>> */
    private array $rows = [];

    private bool $truncated = false;

    public function __construct(public readonly string $key, private readonly ReportScope $scope) {}

    public function metric(string $key, int|float $value): self
    {
        $def = MetricCatalogue::get($key);
        if (! $this->grantAllows($def['grant'])) {
            return $this;
        }
        $this->metrics[] = ['key' => $key, 'kind' => $def['kind'], 'value' => $value];

        return $this;
    }

    /**
     * @param  list<array{label: string, value: int|float}>  $points
     */
    public function chart(string $key, string $type, string $unit, array $points): self
    {
        if ($unit === 'money' && ! $this->scope->amountsVisible()) {
            return $this;
        }
        $this->charts[] = ['key' => $key, 'type' => $type, 'unit' => $unit, 'points' => $points];

        return $this;
    }

    /**
     * Money columns need P-37, or P-82 when declared `cost`; a column the viewer may not see is dropped with its cells.
     *
     * @param  list<array{key: string, type: string, cost?: bool}>  $columns
     * @param  array<array<string, scalar|null>>  $rows
     */
    public function table(array $columns, array $rows): self
    {
        $this->columns = [];
        foreach ($columns as $c) {
            if ($c['type'] === 'money' && ! (($c['cost'] ?? false) ? $this->scope->costsVisible() : $this->scope->amountsVisible())) {
                continue;
            }
            $this->columns[] = ['key' => $c['key'], 'type' => $c['type']];
        }
        if (count($rows) > self::ROW_CAP) {
            $rows = array_slice($rows, 0, self::ROW_CAP);
            $this->truncated = true;
        }
        $keep = array_flip(array_column($this->columns, 'key'));
        $this->rows = array_values(array_map(fn (array $r) => array_intersect_key($r, $keep), $rows));

        return $this;
    }

    private function grantAllows(?string $grant): bool
    {
        return match ($grant) {
            'P-37' => $this->scope->amountsVisible(),
            'P-82' => $this->scope->costsVisible(),
            'P-94' => $this->scope->commissionsVisible(),
            default => true,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'range' => ['from' => $this->scope->from, 'to' => $this->scope->to],
            'branch_ids' => $this->scope->branchIds,
            'amounts_visible' => $this->scope->amountsVisible(),
            'metrics' => $this->metrics,
            'charts' => $this->charts,
            'table' => ['columns' => $this->columns, 'rows' => $this->rows, 'truncated' => $this->truncated],
        ];
    }
}
