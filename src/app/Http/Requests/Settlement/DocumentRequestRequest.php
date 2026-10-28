<?php

namespace App\Http\Requests\Settlement;

use App\Actions\StateMachines\M40Document\DocumentLinks;
use App\Http\Requests\Finance\FinanceWriteRequest;

/**
 * M-40 request: the kind and the order event the external document must cover.
 */
class DocumentRequestRequest extends FinanceWriteRequest
{
    protected function bodyRules(): array
    {
        return DocumentLinks::rules();
    }
}
