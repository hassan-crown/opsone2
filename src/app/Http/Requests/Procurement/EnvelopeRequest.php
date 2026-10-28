<?php

namespace App\Http\Requests\Procurement;

use App\Http\Requests\Agreements\AgreementWriteRequest;

/**
 * A write that carries only the envelope (confirm, enable / disable).
 */
class EnvelopeRequest extends AgreementWriteRequest
{
    protected bool $requiresKey = false;
}
