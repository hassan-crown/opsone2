<?php

namespace App\Http\Requests\Finance;

use App\Finance\OrderSchedule;

/**
 * FinSchedule: the whole list of dues (OrderSchedule::rules()).
 */
class ScheduleRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return OrderSchedule::rules();
    }
}
