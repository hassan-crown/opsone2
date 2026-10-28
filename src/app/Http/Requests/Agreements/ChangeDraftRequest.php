<?php

namespace App\Http\Requests\Agreements;

use App\Actions\StateMachines\M09Change\UpdateChangeDraft;

class ChangeDraftRequest extends AgreementWriteRequest
{
    protected function bodyRules(): array
    {
        return UpdateChangeDraft::rules();
    }
}
