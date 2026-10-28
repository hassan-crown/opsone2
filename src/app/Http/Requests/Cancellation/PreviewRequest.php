<?php

namespace App\Http\Requests\Cancellation;

class PreviewRequest extends CancellationRequest
{
    protected bool $preview = true;
}
