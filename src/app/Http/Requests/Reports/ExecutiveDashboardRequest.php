<?php

namespace App\Http\Requests\Reports;

use App\Reports\Dashboard\ExecutiveDashboard;
use Illuminate\Validation\Rule;

/**
 * Period selector of the owner dashboard: a named period resolves to its range on the server (facility time zone);
 * `custom` takes from/to like every report (same 400-day bound).
 */
class ExecutiveDashboardRequest extends ReportFilterRequest
{
    /** A rolling window: "this month" is nearly empty in the first days of a month, the worst first impression. */
    public const DEFAULT_PERIOD = 'last30';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + ['period' => ['nullable', Rule::in(ExecutiveDashboard::PERIODS)]];
    }

    public function period(): string
    {
        $period = (string) $this->input('period', self::DEFAULT_PERIOD);
        // A custom period without both dates falls back to the default rather than guessing a range.
        if ($period === 'custom' && ! ($this->filled('from') && $this->filled('to'))) {
            return self::DEFAULT_PERIOD;
        }

        return in_array($period, ExecutiveDashboard::PERIODS, true) ? $period : self::DEFAULT_PERIOD;
    }

    public function from(): string
    {
        return $this->period() === 'custom' ? (string) $this->input('from') : ExecutiveDashboard::range($this->period())[0];
    }

    public function to(): string
    {
        return $this->period() === 'custom' ? (string) $this->input('to') : ExecutiveDashboard::range($this->period())[1];
    }
}
