<?php

namespace App\Http\Requests\Reports;

use App\Models\User;
use App\Reports\ReportScope;
use App\Workflow\AppointmentBooking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Date range and branch filter shared by the report screens and the export request. The range is bounded so one
 * run stays a reasonable query; branches outside the viewer's scope simply match nothing (the scope is an AND).
 */
class ReportFilterRequest extends FormRequest
{
    public const MAX_DAYS = 400;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'branch_ids' => ['nullable', 'array', 'max:50'],
            'branch_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [function (Validator $v) {
            $from = $this->input('from');
            $to = $this->input('to');
            if (is_string($from) && is_string($to) && $from > $to) {
                $v->errors()->add('to', __('reports.error.range'));
            } elseif (is_string($from) && is_string($to) && (strtotime($to) - strtotime($from)) / 86400 > self::MAX_DAYS) {
                $v->errors()->add('to', __('reports.error.too_long', ['days' => self::MAX_DAYS]));
            }
        }];
    }

    /**
     * @return list<int>
     */
    public function branchIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input('branch_ids', []))));
    }

    public function from(): string
    {
        return (string) ($this->input('from') ?: ReportScope::defaultFrom());
    }

    public function to(): string
    {
        return (string) ($this->input('to') ?: AppointmentBooking::today());
    }

    public function scope(): ReportScope
    {
        /** @var User $user */
        $user = $this->user();

        return new ReportScope($user, $this->from(), $this->to(), $this->branchIds());
    }
}
